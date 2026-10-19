import contextlib
import json
import math
import re
import time
from dataclasses import dataclass
from typing import Any

from app.config import Settings, get_settings
from app.contracts.llm import ChatMessage as ProviderMessage
from app.contracts.llm import LlmProvider, LlmProviderError
from app.schemas.errors import AppError
from app.schemas.generate import (
    ChatMessage,
    GeneratedTestCase,
    GenerateRequest,
    GenerateResponse,
    GenerationStats,
    LlmCall,
)
from app.services.dedup import DslDeduplicationService
from app.services.path_normalizer import normalize_request_paths
from app.services.prompts import (
    RELATED_SCOPES,
    build_generation_messages,
    build_repair_messages,
)
from app.services.validator import DslValidator


def _clean_json_content(raw: str) -> str:
    """Strip markdown code fences and extraneous whitespace from LLM response."""
    cleaned = raw.strip()
    match = re.search(r"^```(?:json)?\s*\n?(.*?)\n?```$", cleaned, re.DOTALL)
    if match:
        cleaned = match.group(1).strip()
    return cleaned


def _check_json_depth(s: str, max_depth: int = 100) -> None:
    depth = 0
    in_string = False
    escape = False
    for ch in s:
        if escape:
            escape = False
            continue
        if ch == "\\":
            if in_string:
                escape = True
            continue
        if ch == '"':
            in_string = not in_string
            continue
        if not in_string:
            if ch == "{" or ch == "[":
                depth += 1
                if depth > max_depth:
                    raise ValueError(f"JSON nesting depth exceeds limit ({max_depth})")
            elif ch == "}" or ch == "]":
                depth -= 1


def _parse_strict_float(s: str) -> float:
    v = float(s)
    if math.isinf(v) or math.isnan(v):
        raise ValueError(f"Non-finite floating point number out of range: {s}")
    return v


def _reject_json_constant(c: str) -> float:
    raise ValueError(f"Non-finite number literal '{c}' is not permitted in JSON")


def _strict_json_loads(s: str, max_depth: int = 100) -> Any:
    _check_json_depth(s, max_depth)
    try:
        return json.loads(s, parse_float=_parse_strict_float, parse_constant=_reject_json_constant)
    except RecursionError as exc:
        raise ValueError(f"JSON recursion depth exceeded: {exc}") from exc


def _find_json_safety_errors(obj: Any) -> list[str]:
    """Check that obj contains only standard JSON-safe values (no NaN/Inf, no lone surrogates)."""
    errors: list[str] = []

    def _check(val: Any, path: str = "dsl") -> None:
        safe_path = _sanitize_surrogates(path)
        if isinstance(val, str):
            try:
                val.encode("utf-8")
            except UnicodeEncodeError as exc:
                safe_exc = _sanitize_surrogates(str(exc))
                errors.append(
                    f"Giá trị tại {safe_path} chứa ký tự UTF-8 không hợp lệ ({safe_exc})."
                )
        elif isinstance(val, float):
            if math.isinf(val) or math.isnan(val):
                errors.append(f"Giá trị tại {safe_path} là số không hữu hạn ({val}).")
        elif isinstance(val, dict):
            for k, v in val.items():
                safe_k = _sanitize_surrogates(k) if isinstance(k, str) else str(k)
                if isinstance(k, str):
                    try:
                        k.encode("utf-8")
                    except UnicodeEncodeError as exc:
                        safe_exc = _sanitize_surrogates(str(exc))
                        errors.append(
                            f"Key '{safe_k}' at {safe_path} contains invalid UTF-8 "
                            f"surrogate character: {safe_exc}"
                        )
                _check(v, f"{safe_path}.{safe_k}")
        elif isinstance(val, list):
            for i, v in enumerate(val):
                _check(v, f"{safe_path}[{i}]")

    _check(obj)
    return [_sanitize_surrogates(e) for e in errors]


def _sanitize_surrogates(obj: Any) -> Any:
    """Safely sanitize lone surrogates using backslashreplace for UTF-8/JSON serialization."""
    if isinstance(obj, str):
        try:
            obj.encode("utf-8")
            return obj
        except UnicodeEncodeError:
            return obj.encode("utf-8", errors="backslashreplace").decode("utf-8")
    elif isinstance(obj, dict):
        return {_sanitize_surrogates(k): _sanitize_surrogates(v) for k, v in obj.items()}
    elif isinstance(obj, list):
        return [_sanitize_surrogates(v) for v in obj]
    elif isinstance(obj, tuple):
        return tuple(_sanitize_surrogates(v) for v in obj)
    return obj


def _extract_repaired_case(parsed: Any) -> dict[str, Any] | None:
    """Extract a single test case dict from repaired LLM response."""
    if isinstance(parsed, dict):
        if (
            "test_cases" in parsed
            and isinstance(parsed["test_cases"], list)
            and len(parsed["test_cases"]) > 0
        ):
            first = parsed["test_cases"][0]
            if isinstance(first, dict):
                return first
        if "test_case" in parsed and isinstance(parsed["test_case"], dict):
            return parsed["test_case"]
        if "dsl_version" in parsed or "steps" in parsed or "endpoint" in parsed:
            return parsed
    elif isinstance(parsed, list) and len(parsed) > 0:
        first = parsed[0]
        if isinstance(first, dict):
            return first
    return None


def _salvage_case_objects(raw: str) -> list[Any]:
    """Recover the complete elements of a cut-off ``test_cases`` array.

    Local models often stop at the token limit mid-array, which makes the whole batch
    unparseable. Every element that was fully emitted is still usable on its own.
    """
    try:
        _check_json_depth(raw)
    except ValueError:
        return []
    match = re.search(r'"test_cases"\s*:\s*\[', raw)
    if match:
        idx = match.end()
    else:
        stripped = raw.lstrip()
        if not stripped.startswith("["):
            return []
        idx = len(raw) - len(stripped) + 1
    decoder = json.JSONDecoder(
        parse_float=_parse_strict_float, parse_constant=_reject_json_constant
    )
    items: list[Any] = []
    while True:
        while idx < len(raw) and (raw[idx].isspace() or raw[idx] == ","):
            idx += 1
        if idx >= len(raw) or raw[idx] == "]":
            break
        try:
            obj, idx = decoder.raw_decode(raw, idx)
        except (ValueError, RecursionError):
            break
        items.append(obj)
    return items


@dataclass(frozen=True)
class _CallParams:
    """Effective LLM parameters and the wall-clock deadline of one generate request."""

    temperature: float
    seed: int | None
    deadline: float

    def remaining(self) -> float:
        return self.deadline - time.monotonic()


def _prompt_chars(messages: list[ProviderMessage]) -> int:
    return sum(len(m.content) for m in messages)


# Compact JSON/English prompts tokenize at about 3-4.5 characters per token. A provider
# whose context is smaller than the prompt (Ollama keeps only the tail, e.g. with its
# 2k-4k default when OLLAMA_CONTEXT_LENGTH is unset) drops the head first: the system
# prompt with the DSL rules and the untrusted-data guard. Two layers guard against that:
# 1. Before the call (deterministic): a conservative estimate of chars / 3 tokens plus the
#    call's max_tokens must fit AI_LLM_CONTEXT_TOKENS, otherwise the prompt is not sent.
# 2. After the call: the reported prompt token count must be consistent with the prompt
#    length. Any truncation, even a partial one, makes the completion unusable.
_CONSERVATIVE_CHARS_PER_TOKEN = 3
# A non-truncated prompt stays well below this ratio; above it the prompt was cut.
_MAX_CHARS_PER_PROMPT_TOKEN = 5.5
# Ratio above which a token count sitting at a context size is treated as a cut.
_MAX_CHARS_PER_TOKEN_AT_CONTEXT = 4.5
# Common context sizes (powers of two, 1k-128k): a truncated prompt fills the context.
_COMMON_CONTEXT_SIZES = tuple(2**k for k in range(10, 18))
# A truncated prompt reports between this fraction of the context and the context itself.
_AT_CONTEXT_FRACTION = 0.95
# Shorter prompts are at most ~2000 tokens even at 3 characters/token, so they fit every
# supported context (>= 2048) and their token counts are not checked.
_TRUNCATION_CHECK_MIN_CHARS = 6000


# A GENERATE call is not started with less time left than this (it could not finish).
_MIN_GENERATE_CALL_SECONDS = 5.0
# Fixed part of max_tokens: JSON envelope and small variations around the per-case estimate.
_OUTPUT_TOKENS_OVERHEAD = 256


def _json_error_position(exc: Exception) -> str:
    """ " (dòng L, cột C)" of a JSON syntax error, else "" (no English parser text for users)."""
    if isinstance(exc, json.JSONDecodeError):
        return f" (dòng {exc.lineno}, cột {exc.colno})"
    return ""


def _output_limit_error(completion: Any, max_tokens: int) -> str | None:
    """Explain a completion cut off at ``max_tokens`` (finish_reason=length), else None."""
    finish_reason: Any = None
    raw = getattr(completion, "raw", None)
    if isinstance(raw, dict):
        choices = raw.get("choices")
        if isinstance(choices, list) and choices and isinstance(choices[0], dict):
            finish_reason = choices[0].get("finish_reason")
    tokens = getattr(completion, "completion_tokens", None)
    at_limit = isinstance(tokens, int) and not isinstance(tokens, bool) and tokens >= max_tokens
    if finish_reason != "length" and not at_limit:
        return None
    return (
        f"Answer cut off at max_tokens={max_tokens} (finish_reason=length): the case is "
        "larger than the REPAIR output budget (2 x AI_LLM_OUTPUT_TOKENS_PER_CASE + 256)"
    )


def _estimated_prompt_tokens(prompt_chars: int) -> int:
    return -(-prompt_chars // _CONSERVATIVE_CHARS_PER_TOKEN)


def _prompt_context_error(prompt_chars: int, context_tokens: int, reserve: int) -> str | None:
    """Return an error when the prompt plus ``reserve`` output tokens may not fit the context."""
    estimate = _estimated_prompt_tokens(prompt_chars)
    if estimate + reserve <= context_tokens:
        return None
    return (
        f"Prompt has {prompt_chars} characters (about {estimate} tokens at "
        f"{_CONSERVATIVE_CHARS_PER_TOKEN} characters/token) which with {reserve} output "
        f"tokens does not fit AI_LLM_CONTEXT_TOKENS={context_tokens}; send fewer endpoints, "
        "business rules or seed cases per request, or raise the model context"
    )


def _prompt_truncation_error(
    prompt_chars: int, prompt_tokens: int | None, context_tokens: int
) -> str | None:
    """Return an error message when the provider evidently truncated the prompt."""
    if prompt_tokens is None or prompt_tokens <= 0:
        return None
    if prompt_chars < _TRUNCATION_CHECK_MIN_CHARS:
        return None
    ratio = prompt_chars / prompt_tokens
    truncated = ratio > _MAX_CHARS_PER_PROMPT_TOKEN or prompt_tokens >= context_tokens
    if not truncated and ratio > _MAX_CHARS_PER_TOKEN_AT_CONTEXT:
        truncated = any(
            size * _AT_CONTEXT_FRACTION <= prompt_tokens <= size
            for size in (context_tokens, *_COMMON_CONTEXT_SIZES)
        )
    if not truncated:
        return None
    return (
        f"Provider reported only {prompt_tokens} prompt tokens for a {prompt_chars}-character "
        "prompt: the prompt was truncated to the model context window. Raise the model "
        "context (Ollama OLLAMA_CONTEXT_LENGTH) and set AI_LLM_CONTEXT_TOKENS to it, or "
        "send fewer endpoints per request"
    )


class GeneratorService:
    """Generate and repair cases while preserving successful results and call logs."""

    def __init__(
        self,
        provider: LlmProvider,
        validator: DslValidator | None = None,
        dedup_service: DslDeduplicationService | None = None,
        settings: Settings | None = None,
    ) -> None:
        self.provider = provider
        self.validator = validator or DslValidator()
        self.settings = settings or get_settings()
        self.dedup_service = dedup_service or DslDeduplicationService()

    def generate(self, request: GenerateRequest) -> GenerateResponse:
        if request.strategy not in ("LLM", "HYBRID"):
            raise AppError(
                code="VALIDATION_FAILED",
                message=f"Strategy '{request.strategy}' is not supported in this version",
                status_code=422,
            )

        spec_endpoints = [ep.model_dump() for ep in request.endpoints]

        if request.strategy == "HYBRID" and not request.seed_cases:
            raise AppError(
                code="VALIDATION_FAILED",
                message="seed_cases must have at least 1 item when strategy is HYBRID",
                status_code=422,
                details={
                    "seed_cases": ["seed_cases must have at least 1 item when strategy is HYBRID"]
                },
            )

        known_fingerprints: set[str] = set()
        if request.seed_cases:
            for idx, sc in enumerate(request.seed_cases):
                if not isinstance(sc, dict) or not sc:
                    raise AppError(
                        code="VALIDATION_FAILED",
                        message=f"seed_cases[{idx}]: seed case must be a non-empty object",
                        status_code=422,
                        details={
                            "seed_cases": [
                                f"seed_cases[{idx}]: seed case must be a non-empty object"
                            ]
                        },
                    )
                sc_dsl = sc.get("dsl") if isinstance(sc.get("dsl"), dict) else sc
                if not isinstance(sc_dsl, dict) or not sc_dsl:
                    raise AppError(
                        code="VALIDATION_FAILED",
                        message=f"seed_cases[{idx}]: test case DSL must be a non-empty object",
                        status_code=422,
                        details={
                            "seed_cases": [
                                f"seed_cases[{idx}]: test case DSL must be a non-empty object"
                            ]
                        },
                    )
                val_result = self.validator.validate(
                    sc_dsl, spec=spec_endpoints, context_variables=request.context_variables()
                )
                if not val_result.is_valid:
                    err_msg = val_result.errors[0] if val_result.errors else "Invalid Test DSL"
                    raise AppError(
                        code="VALIDATION_FAILED",
                        message=f"seed_cases[{idx}]: {err_msg}",
                        status_code=422,
                        details={"seed_cases": [f"seed_cases[{idx}]: {err_msg}"]},
                    )
                validated_seed = val_result.dsl if isinstance(val_result.dsl, dict) else sc_dsl
                with contextlib.suppress(Exception):
                    known_fingerprints.add(self.dedup_service.get_fingerprint(validated_seed))
                if request.strategy == "HYBRID" and validated_seed.get("source") != "HYBRID":
                    hybrid_seed = {**validated_seed, "source": "HYBRID"}
                    with contextlib.suppress(Exception):
                        known_fingerprints.add(self.dedup_service.get_fingerprint(hybrid_seed))

        # docs/DESIGN.md section 7: AI_LLM_SEED is the default when options.seed is null, so every
        # call is seeded. options.temperature is required by the contract (S13-03), so it is
        # always the caller's value; AI_LLM_TEMPERATURE is the default reported by /health.
        params = _CallParams(
            temperature=request.options.temperature,
            seed=(
                request.options.seed if request.options.seed is not None else self.settings.llm_seed
            ),
            deadline=time.monotonic() + self.settings.generate_budget_seconds,
        )

        # S11-02: one small GENERATE call per target endpoint, asking for at most
        # AI_LLM_CASES_PER_CALL cases with a bounded max_tokens, so every call of a slow
        # local model stays well inside AI_LLM_TIMEOUT_SECONDS. Every prompt is built and
        # checked before the first call, so an oversized request fails fast (422).
        cases_per_call = min(
            request.options.max_cases_per_endpoint, self.settings.llm_cases_per_call
        )
        generate_max_tokens = self._max_tokens(cases_per_call)
        plans: list[tuple[list[ProviderMessage], int, tuple[str, str]]] = []
        for endpoint in request.endpoints:
            # Every other selected endpoint is listed for multi-step cases; when that does
            # not fit the prompt limits, only the endpoints of the same resource are.
            for scope in RELATED_SCOPES:
                messages = build_generation_messages(request, endpoint, cases_per_call, scope)
                prompt_chars = _prompt_chars(messages)
                if (
                    prompt_chars <= self.settings.max_prompt_chars
                    and _prompt_context_error(
                        prompt_chars, self.settings.llm_context_tokens, generate_max_tokens
                    )
                    is None
                ):
                    break
            if prompt_chars > self.settings.max_prompt_chars:
                msg = (
                    f"Generation prompt has {prompt_chars} characters, above the "
                    f"AI_MAX_PROMPT_CHARS limit of {self.settings.max_prompt_chars}; "
                    "send fewer business rules or seed cases per request"
                )
                raise AppError(
                    code="VALIDATION_FAILED",
                    message=msg,
                    status_code=422,
                    details={"endpoints": [msg]},
                )
            context_error = _prompt_context_error(
                prompt_chars, self.settings.llm_context_tokens, generate_max_tokens
            )
            if context_error is not None:
                raise AppError(
                    code="VALIDATION_FAILED",
                    message=context_error,
                    status_code=422,
                    details={"endpoints": [context_error]},
                )
            plans.append((messages, prompt_chars, (endpoint.method, endpoint.path)))

        llm_calls: list[LlmCall] = []
        # (case, parse errors, target endpoint of the GENERATE call that produced it)
        pending: list[tuple[Any, list[str], tuple[str, str]]] = []
        gen_model: str | None = None
        last_error: str | None = None
        for messages, prompt_chars, call_target in plans:
            if params.remaining() < _MIN_GENERATE_CALL_SECONDS:
                # Budget exhausted: the remaining endpoints get no cases (the web marks the
                # job PARTIAL), but everything generated so far is still returned.
                break
            call, content, call_error = self._generate_call(
                messages, prompt_chars, request, params, generate_max_tokens
            )
            llm_calls.append(call)
            if content is None:
                last_error = call_error
                continue
            gen_model = call.model
            pending.extend(
                (item, errors, call_target) for item, errors in self._parse_generation(content)
            )

        if gen_model is None:
            # Every GENERATE call failed (D09: the calls are still returned for logging).
            message = last_error or (
                "Không thực hiện được lời gọi LLM nào trong thời gian cho phép của lượt sinh test."
            )
            raise AppError(
                code="LLM_PROVIDER_ERROR",
                message=message,
                status_code=502,
                details={"llm_calls": [c.model_dump() for c in llm_calls]},
            )

        test_cases: list[GeneratedTestCase] = []
        cases_per_endpoint: dict[tuple[str, str], int] = {}
        selected = {(ep.method, ep.path) for ep in request.endpoints}
        # Cases without a usable selected endpoint (missing, malformed or hallucinated)
        # share one max_cases_per_endpoint repair budget, so the number of REPAIR calls
        # stays bounded by (endpoints + 1) * max_cases_per_endpoint * max_repair_attempts.
        # Past that budget they are still returned, as INVALID without repair: every
        # failed answer stays visible in test_cases and in stats.generated.
        unusable_endpoint_cases = 0
        for item, errors, call_target in pending:
            if isinstance(item, str):
                try:
                    item = _strict_json_loads(item)
                except (json.JSONDecodeError, ValueError, RecursionError) as exc:
                    errors = [
                        *errors,
                        "Không đọc được JSON của test case trong câu trả lời của LLM"
                        f"{_json_error_position(exc)}.",
                    ]

            # Provenance follows the strategy: source is forced to "LLM" or "HYBRID".
            if isinstance(item, dict):
                item["source"] = request.strategy
                normalize_request_paths(item, spec_endpoints)

            safety_errs = _find_json_safety_errors(item)
            if safety_errs:
                errors = [*errors, *safety_errs]

            if not errors:
                val_result = self.validator.validate(
                    item, spec=spec_endpoints, context_variables=request.context_variables()
                )
                errors = [_sanitize_surrogates(e) for e in val_result.errors]
                if val_result.dsl and isinstance(val_result.dsl, dict):
                    item = val_result.dsl
                    item["source"] = request.strategy

            if not isinstance(item, dict):
                errors = [*errors, "Test case phải là một đối tượng JSON."]

            original_endpoint = self._endpoint_key(item)
            if original_endpoint in selected:
                if cases_per_endpoint.get(original_endpoint, 0) >= (
                    request.options.max_cases_per_endpoint
                ):
                    continue
            else:
                if unusable_endpoint_cases >= request.options.max_cases_per_endpoint:
                    test_cases.append(
                        self._unrepaired_case(
                            item,
                            [
                                *errors,
                                "Bỏ qua sửa lỗi: đã dùng hết lượt sửa dành cho các test case "
                                "không có endpoint đích hợp lệ.",
                            ],
                            request,
                        )
                    )
                    continue
                unusable_endpoint_cases += 1
            first_repair_call = len(llm_calls)
            case = self._repair_case(
                item,
                errors,
                spec_endpoints,
                request,
                llm_calls,
                gen_model,
                original_endpoint if original_endpoint in selected else None,
                params,
                call_target,
            )
            final_endpoint = self._endpoint_key(case.dsl)
            if final_endpoint is not None and final_endpoint in selected:
                if (
                    cases_per_endpoint.get(final_endpoint, 0)
                    >= request.options.max_cases_per_endpoint
                ):
                    continue
                cases_per_endpoint[final_endpoint] = cases_per_endpoint.get(final_endpoint, 0) + 1
            # Deduplication below keeps the order and length of test_cases, so this index
            # still points at the case in the response.
            for repair_call in llm_calls[first_repair_call:]:
                repair_call.case_index = len(test_cases)
            test_cases.append(case)

        # Perform deduplication across generated test cases
        raw_tc_dicts = [tc.model_dump() for tc in test_cases]
        dedup_result = self.dedup_service.deduplicate_batch(
            raw_tc_dicts,
            known_fingerprints=known_fingerprints,
        )
        test_cases = [GeneratedTestCase.model_validate(p) for p in dedup_result.processed]

        valid_count = sum(1 for tc in test_cases if tc.status == "VALID")
        invalid_count = sum(1 for tc in test_cases if tc.status == "INVALID")
        duplicate_count = sum(1 for tc in test_cases if tc.status == "DUPLICATE")
        stats = GenerationStats(
            generated=len(test_cases),
            valid=valid_count,
            invalid=invalid_count,
            repaired=sum(tc.status == "VALID" and tc.repair_attempts > 0 for tc in test_cases),
            duplicates=duplicate_count,
        )

        return GenerateResponse(test_cases=test_cases, llm_calls=llm_calls, stats=stats)

    @staticmethod
    def _endpoint_key(dsl: Any) -> tuple[str, str] | None:
        endpoint = dsl.get("endpoint") if isinstance(dsl, dict) else None
        if not isinstance(endpoint, dict):
            return None
        method, path = endpoint.get("method"), endpoint.get("path")
        if isinstance(method, str) and isinstance(path, str) and method and path:
            return method.upper(), path
        return None

    def _requested_model(self, request: GenerateRequest) -> str:
        """Model the provider is asked for: options.model, else AI_LLM_MODEL (D09).

        Logged for calls whose completion reports no model (failed calls in particular),
        never the provider name.
        """
        if request.options.model and request.options.model.strip():
            return request.options.model
        return self.settings.llm_model

    def _max_tokens(self, cases: int) -> int:
        """max_tokens for an answer of ``cases`` cases, never above half the context."""
        wanted = cases * self.settings.llm_output_tokens_per_case + _OUTPUT_TOKENS_OVERHEAD
        return max(1, min(wanted, self.settings.llm_context_tokens // 2))

    def _generate_call(
        self,
        messages: list[ProviderMessage],
        prompt_chars: int,
        request: GenerateRequest,
        params: _CallParams,
        max_tokens: int,
    ) -> tuple[LlmCall, str | None, str | None]:
        """Run one GENERATE call. Returns its log entry, the content (None on failure) and,
        on failure, the message for users (the log keeps the technical text, D09).

        A failed call (provider error or truncated prompt) is still logged (D09) with its
        error, so the other endpoints of the request can go on.
        """
        safe_provider = _sanitize_surrogates(self.provider.name).strip() or "unknown"
        safe_prompt = [
            ChatMessage(role=m.role, content=_sanitize_surrogates(m.content)) for m in messages
        ]
        start_time = time.perf_counter()
        try:
            completion = self.provider.complete(
                messages,
                temperature=params.temperature,
                seed=params.seed,
                json_mode=True,
                model=request.options.model,
                timeout=self._call_timeout(params),
                max_tokens=max_tokens,
            )
        except LlmProviderError as exc:
            failed_call = LlmCall(
                purpose="GENERATE",
                provider=safe_provider,
                model=_sanitize_surrogates(self._requested_model(request)).strip() or "unknown",
                prompt=safe_prompt,
                response_raw="",
                temperature=params.temperature,
                seed=params.seed,
                prompt_tokens=None,
                completion_tokens=None,
                latency_ms=max(0, int((time.perf_counter() - start_time) * 1000)),
                error=_sanitize_surrogates(exc.detail),
            )
            return failed_call, None, _sanitize_surrogates(str(exc))
        raw_model = (
            completion.model.strip()
            if completion.model and completion.model.strip()
            else self._requested_model(request)
        )
        safe_prompt_tokens = (
            completion.prompt_tokens
            if completion.prompt_tokens is not None and completion.prompt_tokens >= 0
            else None
        )
        safe_completion_tokens = (
            completion.completion_tokens
            if completion.completion_tokens is not None and completion.completion_tokens >= 0
            else None
        )
        call = LlmCall(
            purpose="GENERATE",
            provider=safe_provider,
            model=_sanitize_surrogates(raw_model).strip() or "unknown",
            prompt=safe_prompt,
            response_raw=_sanitize_surrogates(completion.content or ""),
            temperature=params.temperature,
            seed=params.seed,
            prompt_tokens=safe_prompt_tokens,
            completion_tokens=safe_completion_tokens,
            latency_ms=max(0, int((time.perf_counter() - start_time) * 1000)),
            error=None,
        )
        truncation = _prompt_truncation_error(
            prompt_chars, safe_prompt_tokens, self.settings.llm_context_tokens
        )
        if truncation is not None:
            # The completion answers a prompt without its head: unusable, logged with why.
            return call.model_copy(update={"error": truncation}), None, truncation
        return call, completion.content, None

    @staticmethod
    def _parse_generation(content: str) -> list[tuple[Any, list[str]]]:
        """Split one GENERATE answer into (case, parse errors) items."""
        raw_text = _clean_json_content(content)
        try:
            parsed = _strict_json_loads(raw_text)
        except (json.JSONDecodeError, ValueError, RecursionError) as exc:
            salvaged = _salvage_case_objects(raw_text)
            if salvaged:
                return [(item, []) for item in salvaged]
            return [
                (
                    content,
                    [f"Không đọc được JSON trong câu trả lời của LLM{_json_error_position(exc)}."],
                )
            ]
        if isinstance(parsed, dict) and "test_cases" in parsed:
            if isinstance(parsed["test_cases"], list):
                return [(item, []) for item in parsed["test_cases"]]
            return [(parsed, ["Câu trả lời của LLM sai dạng: 'test_cases' phải là một mảng."])]
        if isinstance(parsed, list):
            return [(item, []) for item in parsed]
        if isinstance(parsed, dict) and "dsl_version" in parsed and "steps" in parsed:
            return [(parsed, [])]
        return [(parsed, ["Câu trả lời của LLM sai dạng: thiếu mảng 'test_cases'."])]

    def _call_timeout(self, params: _CallParams) -> float:
        """Per-call timeout that never runs past the request deadline."""
        return max(0.001, min(self.settings.llm_timeout_seconds, params.remaining()))

    @staticmethod
    def _unrepaired_case(
        item: Any, errors: list[str], request: GenerateRequest
    ) -> GeneratedTestCase:
        """INVALID case returned as is, without any REPAIR call."""
        safe_dsl = _sanitize_surrogates(item) if isinstance(item, dict) else {}
        if isinstance(safe_dsl, dict) and safe_dsl:
            safe_dsl["source"] = request.strategy
        return GeneratedTestCase(
            dsl=safe_dsl,
            status="INVALID",
            validation_errors=[_sanitize_surrogates(e) for e in errors],
            repair_attempts=0,
            fingerprint=None,
        )

    def _repair_case(
        self,
        dsl: Any,
        errors: list[str],
        spec_endpoints: list[dict[str, Any]],
        request: GenerateRequest,
        llm_calls: list[LlmCall],
        model: str,
        original_endpoint: tuple[str, str] | None,
        params: _CallParams,
        call_target: tuple[str, str] | None = None,
    ) -> GeneratedTestCase:
        attempts = 0
        max_attempts = min(request.options.max_repair_attempts, self.settings.max_repair_attempts)
        # A repair answers with one case; twice the per-case estimate leaves room for it
        # (ADR-0005 protocol value, 1056 tokens by default).
        repair_max_tokens = self._max_tokens(2)
        for attempt in range(1, max_attempts + 1):
            if not errors:
                break
            if params.remaining() <= 0:
                errors = [
                    *errors,
                    "Bỏ qua sửa lỗi: đã hết thời gian cho phép của lượt sinh test.",
                ]
                break
            messages = build_repair_messages(
                _sanitize_surrogates(dsl),
                [_sanitize_surrogates(e) for e in errors],
                spec_endpoints,
                strategy=request.strategy,
                target_endpoint=call_target,
                auth=request.auth,
            )
            if _prompt_chars(messages) > self.settings.max_prompt_chars:
                errors = [
                    *errors,
                    "Bỏ qua sửa lỗi: prompt sửa lỗi dài quá giới hạn cho phép "
                    f"({self.settings.max_prompt_chars} ký tự).",
                ]
                break
            repair_context_error = _prompt_context_error(
                _prompt_chars(messages), self.settings.llm_context_tokens, repair_max_tokens
            )
            if repair_context_error is not None:
                errors = [
                    *errors,
                    "Bỏ qua sửa lỗi: prompt sửa lỗi không vừa độ dài ngữ cảnh (context) của model.",
                ]
                break
            attempts = attempt
            start = time.perf_counter()
            completion: Any = None
            try:
                completion = self.provider.complete(
                    messages,
                    temperature=params.temperature,
                    seed=params.seed,
                    json_mode=True,
                    model=request.options.model,
                    timeout=self._call_timeout(params),
                    max_tokens=repair_max_tokens,
                )
                if not completion.model or not completion.model.strip():
                    raise ValueError("Provider returned empty model name")
                if completion.prompt_tokens is not None and completion.prompt_tokens < 0:
                    raise ValueError(
                        f"Provider returned invalid prompt_tokens: {completion.prompt_tokens}"
                    )
                if completion.completion_tokens is not None and completion.completion_tokens < 0:
                    raise ValueError(
                        f"Provider returned invalid completion_tokens: "
                        f"{completion.completion_tokens}"
                    )
                repair_truncation = _prompt_truncation_error(
                    _prompt_chars(messages),
                    completion.prompt_tokens,
                    self.settings.llm_context_tokens,
                )
                if repair_truncation is not None:
                    raise ValueError(repair_truncation)

                safe_repair_prompt = [
                    ChatMessage(role=m.role, content=_sanitize_surrogates(m.content))
                    for m in messages
                ]
                safe_repair_raw = _sanitize_surrogates(completion.content or "")
                safe_repair_model = (
                    _sanitize_surrogates(completion.model.strip()).strip() or "unknown"
                )
                safe_provider = _sanitize_surrogates(self.provider.name).strip() or "unknown"
                cut_off = _output_limit_error(completion, repair_max_tokens)
                llm_calls.append(
                    LlmCall(
                        purpose="REPAIR",
                        provider=safe_provider,
                        model=safe_repair_model,
                        prompt=safe_repair_prompt,
                        response_raw=safe_repair_raw,
                        temperature=params.temperature,
                        seed=params.seed,
                        prompt_tokens=completion.prompt_tokens,
                        completion_tokens=completion.completion_tokens,
                        latency_ms=max(0, int((time.perf_counter() - start) * 1000)),
                        error=cut_off,
                    )
                )
            except Exception as exc:
                raw_model = (
                    completion.model.strip()
                    if completion is not None
                    and isinstance(getattr(completion, "model", None), str)
                    and completion.model.strip()
                    else (
                        model.strip() if model and model.strip() else self._requested_model(request)
                    )
                )
                safe_model = _sanitize_surrogates(raw_model).strip() or "unknown"
                safe_raw = _sanitize_surrogates(
                    completion.content
                    if completion is not None
                    and isinstance(getattr(completion, "content", None), str)
                    else ""
                )
                safe_prompt = [
                    ChatMessage(role=m.role, content=_sanitize_surrogates(m.content))
                    for m in messages
                ]
                safe_provider = _sanitize_surrogates(self.provider.name).strip() or "unknown"
                # The log keeps the technical text (D09); users get the short message below.
                safe_err_str = _sanitize_surrogates(
                    exc.detail if isinstance(exc, LlmProviderError) else str(exc)
                )
                try:
                    llm_calls.append(
                        LlmCall(
                            purpose="REPAIR",
                            provider=safe_provider,
                            model=safe_model,
                            prompt=safe_prompt,
                            response_raw=safe_raw,
                            temperature=params.temperature,
                            seed=params.seed,
                            prompt_tokens=None,
                            completion_tokens=None,
                            latency_ms=max(0, int((time.perf_counter() - start) * 1000)),
                            error=safe_err_str,
                        )
                    )
                except Exception as log_exc:
                    fallback_model = _sanitize_surrogates(model).strip() or "unknown"
                    llm_calls.append(
                        LlmCall(
                            purpose="REPAIR",
                            provider=safe_provider,
                            model=fallback_model,
                            prompt=safe_prompt,
                            response_raw="",
                            temperature=params.temperature,
                            seed=params.seed,
                            prompt_tokens=None,
                            completion_tokens=None,
                            latency_ms=max(0, int((time.perf_counter() - start) * 1000)),
                            error=_sanitize_surrogates(f"{exc} (log error: {log_exc})"),
                        )
                    )
                errors = [
                    *errors,
                    "Lỗi khi gọi LLM để sửa test case: "
                    + (
                        _sanitize_surrogates(str(exc))
                        if isinstance(exc, LlmProviderError)
                        else "nhà cung cấp LLM trả dữ liệu không dùng được."
                    ),
                ]
                break

            try:
                candidate = _extract_repaired_case(
                    _strict_json_loads(_clean_json_content(completion.content))
                )
            except (json.JSONDecodeError, ValueError, RecursionError) as exc:
                if cut_off is not None:
                    # Another attempt would hit the same output limit: stop here instead of
                    # spending another full-length call of the time budget.
                    errors = [
                        *errors,
                        "Câu trả lời sửa lỗi của LLM bị cắt do vượt giới hạn token đầu ra: "
                        "test case quá lớn để sửa tự động.",
                    ]
                    break
                errors = [
                    *errors,
                    "Không đọc được JSON trong câu trả lời sửa lỗi của LLM"
                    f"{_json_error_position(exc)}.",
                ]
                continue
            if candidate is None:
                errors = [
                    *errors,
                    "Câu trả lời sửa lỗi phải chứa một đối tượng Test DSL JSON.",
                ]
                continue
            if isinstance(candidate, dict):
                candidate["source"] = request.strategy
                normalize_request_paths(candidate, spec_endpoints)
            if original_endpoint and self._endpoint_key(candidate) != original_endpoint:
                errors = [
                    *errors,
                    "Bản sửa phải giữ nguyên endpoint đích của test case ban đầu.",
                ]
                continue
            cand_safety_errs = _find_json_safety_errors(candidate)
            if cand_safety_errs:
                errors = [*errors, *cand_safety_errs]
                continue
            candidate_val = self.validator.validate(
                candidate, spec=spec_endpoints, context_variables=request.context_variables()
            )
            errors = [_sanitize_surrogates(e) for e in candidate_val.errors]
            if candidate_val.dsl and isinstance(candidate_val.dsl, dict):
                dsl = candidate_val.dsl
                dsl["source"] = request.strategy
            else:
                dsl = candidate
        is_valid = bool(
            not errors and isinstance(dsl, dict) and dsl and not _find_json_safety_errors(dsl)
        )
        safe_dsl = _sanitize_surrogates(dsl) if isinstance(dsl, dict) else {}
        if isinstance(safe_dsl, dict) and safe_dsl:
            safe_dsl["source"] = request.strategy
        return GeneratedTestCase(
            dsl=safe_dsl,
            status="VALID" if is_valid else "INVALID",
            validation_errors=[_sanitize_surrogates(e) for e in errors],
            repair_attempts=attempts,
            fingerprint=None,
        )
