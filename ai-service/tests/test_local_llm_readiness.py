"""S11-02: small bounded LLM calls for slow local models (qwen2.5-coder:7b at ~18 tokens/s).

One GENERATE call per target endpoint with a compact prompt, a bounded max_tokens and a
consistent time budget; HYBRID seeds in real DSL shape, limited per endpoint; LLM paths
normalised to declared templates.
"""

import copy
import json
import re
from pathlib import Path
from typing import Any

import httpx
import jsonschema
import pytest

from app.config import Settings
from app.contracts.llm import ChatMessage, LlmCompletion, LlmProviderError
from app.providers.fake import FakeLlmProvider
from app.providers.openai_compatible import OpenAICompatibleProvider
from app.schemas.errors import AppError
from app.schemas.generate import GenerateRequest
from app.services.generator import GeneratorService
from app.services.path_normalizer import normalize_request_paths
from app.services.prompts import (
    MAX_HYBRID_SEEDS_PER_ENDPOINT,
    build_user_prompt,
    compact_schema,
    technique_plan,
)
from tests.contract_payloads import contract_endpoint, contract_options
from tests.repo_files import REPO_ROOT, repo_file

ITEM_SCHEMA = {
    "type": "object",
    "title": "ItemCreate",
    "properties": {
        "name": {"type": "string", "title": "Name", "description": "Item name"},
        "title": {"type": "string", "maxLength": 20, "title": "Title"},
        "price": {"type": "number", "minimum": 0, "examples": [1.5]},
    },
    "required": ["name"],
}

POST_ITEMS: dict[str, Any] = contract_endpoint(
    {
        "id": 1,
        "method": "POST",
        "path": "/items",
        "summary": "Create an item",
        "request_body": {
            "required": True,
            "content": {"application/json": {"schema": ITEM_SCHEMA}},
        },
        "responses": {"201": {"description": "Created"}, "422": {"description": "Invalid"}},
    }
)
GET_ITEM: dict[str, Any] = contract_endpoint(
    {
        "id": 2,
        "method": "GET",
        "path": "/items/{id}",
        "summary": "Read an item",
        "parameters": [
            {"name": "id", "in": "path", "required": True, "schema": {"type": "integer"}}
        ],
        "responses": {"200": {"description": "OK"}, "404": {"description": "Missing"}},
    }
)
GET_HEALTH: dict[str, Any] = contract_endpoint(
    {
        "id": 3,
        "method": "GET",
        "path": "/health",
        "responses": {"200": {"description": "OK"}},
    }
)


def _case(method: str, path: str, title: str, **request: Any) -> dict[str, Any]:
    status = {"POST": 201, "GET": 200}[method]
    return {
        "dsl_version": "1.0",
        "title": title,
        "endpoint": {"method": method, "path": path},
        "technique": "HAPPY_PATH",
        "source": "LLM",
        "steps": [
            {
                "name": title,
                "request": {"method": method, "path": path, **request},
                "assertions": [{"type": "status", "equals": status}],
            }
        ],
    }


def _post_case(title: str) -> dict[str, Any]:
    return _case("POST", "/items", title, body={"name": title})


def _get_case(title: str) -> dict[str, Any]:
    return _case("GET", "/items/{id}", title, path_params={"id": 1})


def _payload(**options: Any) -> dict[str, Any]:
    return {
        "job_id": 1,
        "strategy": "LLM",
        "endpoints": [copy.deepcopy(POST_ITEMS), copy.deepcopy(GET_ITEM)],
        "business_rules": [
            {"code": "BR_POST", "description": "Item names are unique", "endpoint_id": 1},
            {"code": "BR_GET", "description": "Archived items are hidden", "endpoint_id": 2},
            {"code": "BR_ALL", "description": "Prices are in VND", "endpoint_id": None},
        ],
        "seed_cases": [],
        "options": contract_options(
            **{"max_cases_per_endpoint": 10, "max_repair_attempts": 2, **options}
        ),
    }


def _answer(*cases: dict[str, Any]) -> str:
    return json.dumps({"test_cases": list(cases)})


def _data_block(content: str, name: str) -> Any:
    match = re.search(rf"<<<DATA:{name}>>>\n(.*?)\n<<<END DATA:{name}>>>", content, re.DOTALL)
    return json.loads(match.group(1)) if match else None


def _service(provider: Any, **settings: Any) -> GeneratorService:
    return GeneratorService(provider=provider, settings=Settings(llm_provider="fake", **settings))


# --- one bounded GENERATE call per endpoint ------------------------------------------------


def test_one_generate_call_per_endpoint_with_only_its_endpoint_in_the_prompt() -> None:
    provider = FakeLlmProvider([_answer(_post_case("Create pen")), _answer(_get_case("Read 1"))])

    response = _service(provider).generate(GenerateRequest.model_validate(_payload()))

    assert [c.purpose for c in response.llm_calls] == ["GENERATE", "GENERATE"]
    targets = [_data_block(call[1].content, "endpoints") for call in provider.calls]
    assert [(t["method"], t["path"]) for t in targets] == [
        ("POST", "/items"),
        ("GET", "/items/{id}"),
    ]
    assert [tc.status for tc in response.test_cases] == ["VALID", "VALID"]
    assert response.stats.valid == 2


def test_cases_per_call_and_max_tokens_are_bounded() -> None:
    provider = FakeLlmProvider()
    service = _service(provider, llm_cases_per_call=3, llm_output_tokens_per_case=400)

    service.generate(GenerateRequest.model_validate(_payload(max_cases_per_endpoint=10)))
    # min(10, 3) cases: 3 * 400 + 256 tokens; the prompt asks for exactly that many cases.
    assert provider.max_tokens == [1456, 1456]
    assert "Write exactly 3 test case(s)" in provider.calls[0][1].content

    provider = FakeLlmProvider([_answer(), _answer()])
    _service(provider, llm_cases_per_call=3).generate(
        GenerateRequest.model_validate(_payload(max_cases_per_endpoint=2))
    )
    assert provider.max_tokens == [2 * 400 + 256] * 2
    assert "Write exactly 2 test case(s)" in provider.calls[0][1].content


def test_max_tokens_never_exceeds_half_the_context() -> None:
    provider = FakeLlmProvider([_answer(), _answer()])
    service = _service(
        provider, llm_context_tokens=4096, llm_cases_per_call=10, llm_output_tokens_per_case=800
    )
    service.generate(GenerateRequest.model_validate(_payload(max_cases_per_endpoint=10)))
    assert provider.max_tokens == [2048, 2048]


def test_an_empty_answer_is_not_an_error() -> None:
    provider = FakeLlmProvider([_answer(), _answer(_get_case("Read 1"))])
    response = _service(provider).generate(GenerateRequest.model_validate(_payload()))
    assert [tc.dsl["endpoint"]["path"] for tc in response.test_cases] == ["/items/{id}"]


class _FailingFirstProvider(FakeLlmProvider):
    def complete(
        self,
        messages: list[ChatMessage],
        *,
        temperature: float,
        seed: int | None = None,
        json_mode: bool = True,
        model: str | None = None,
        timeout: float | None = None,
        max_tokens: int | None = None,
    ) -> LlmCompletion:
        if not self.calls:
            self.calls.append(messages)
            raise LlmProviderError("timed out")
        return super().complete(messages, temperature=temperature, max_tokens=max_tokens)


def test_a_failed_endpoint_call_keeps_the_other_endpoints() -> None:
    provider = _FailingFirstProvider([_answer(_get_case("Read 1"))])

    response = _service(provider).generate(GenerateRequest.model_validate(_payload()))

    assert [(c.purpose, c.error) for c in response.llm_calls] == [
        ("GENERATE", "timed out"),
        ("GENERATE", None),
    ]
    assert [tc.status for tc in response.test_cases] == ["VALID"]


class _AlwaysFailingProvider(FakeLlmProvider):
    def complete(
        self,
        messages: list[ChatMessage],
        *,
        temperature: float,
        seed: int | None = None,
        json_mode: bool = True,
        model: str | None = None,
        timeout: float | None = None,
        max_tokens: int | None = None,
    ) -> LlmCompletion:
        self.calls.append(messages)
        raise LlmProviderError(f"down {len(self.calls)}")


def test_all_endpoint_calls_failing_is_a_provider_error_with_every_call_logged() -> None:
    provider = _AlwaysFailingProvider()

    with pytest.raises(AppError) as exc:
        _service(provider).generate(GenerateRequest.model_validate(_payload()))

    assert exc.value.code == "LLM_PROVIDER_ERROR"
    assert exc.value.status_code == 502
    calls = exc.value.details["llm_calls"]
    assert [c["error"] for c in calls] == ["down 1", "down 2"]


class _ClockProvider(FakeLlmProvider):
    """Each call advances a fake monotonic clock by ``step`` seconds."""

    def __init__(self, responses: list[str], clock: list[float], step: float) -> None:
        super().__init__(responses)
        self.clock, self.step = clock, step
        self.timeouts: list[float | None] = []

    def complete(
        self,
        messages: list[ChatMessage],
        *,
        temperature: float,
        seed: int | None = None,
        json_mode: bool = True,
        model: str | None = None,
        timeout: float | None = None,
        max_tokens: int | None = None,
    ) -> LlmCompletion:
        self.timeouts.append(timeout)
        self.clock[0] += self.step
        return super().complete(messages, temperature=temperature, max_tokens=max_tokens)


def test_budget_exhausted_skips_remaining_endpoints_and_returns_partial_results(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    clock = [0.0]
    monkeypatch.setattr("app.services.generator.time.monotonic", lambda: clock[0])
    payload = _payload()
    payload["endpoints"].append(copy.deepcopy(GET_HEALTH))
    provider = _ClockProvider(
        [_answer(_post_case("Create pen")), _answer(_get_case("Read 1"))], clock, step=60.0
    )
    service = _service(provider, generate_budget_seconds=100.0, llm_timeout_seconds=300.0)

    response = service.generate(GenerateRequest.model_validate(payload))

    # t=0: POST (timeout capped to the 100 s budget), t=60: GET (40 s left), t=120: skipped.
    assert provider.timeouts == [100.0, 40.0]
    assert len(response.llm_calls) == 2
    assert {tc.dsl["endpoint"]["path"] for tc in response.test_cases} == {"/items", "/items/{id}"}


def test_oversized_prompt_of_any_endpoint_is_rejected_before_any_call() -> None:
    payload = _payload()
    payload["endpoints"][1]["description"] = "x" * 40000
    provider = FakeLlmProvider()

    with pytest.raises(AppError) as exc:
        _service(provider).generate(GenerateRequest.model_validate(payload))

    assert exc.value.code == "VALIDATION_FAILED"
    assert provider.calls == []


# --- compact prompt -------------------------------------------------------------------------


def test_prompt_keeps_only_rules_of_the_target_endpoint_and_global_rules() -> None:
    request = GenerateRequest.model_validate(_payload())
    post_prompt = build_user_prompt(request, request.endpoints[0], 3)
    get_prompt = build_user_prompt(request, request.endpoints[1], 3)

    assert [r["code"] for r in _data_block(post_prompt, "business_rules")] == ["BR_POST", "BR_ALL"]
    assert [r["code"] for r in _data_block(get_prompt, "business_rules")] == ["BR_GET", "BR_ALL"]
    assert "Declared response status codes of the target: 201, 422" in post_prompt
    assert 'Every case uses "endpoint": {"method": "GET", "path": "/items/{id}"}' in get_prompt


def test_compact_schema_drops_annotations_but_keeps_a_property_named_title() -> None:
    compact = compact_schema(ITEM_SCHEMA)

    assert "title" not in compact
    assert set(compact["properties"]) == {"name", "title", "price"}
    assert compact["properties"]["title"] == {"type": "string", "maxLength": 20}
    assert compact["properties"]["price"] == {"type": "number", "minimum": 0}
    assert compact["required"] == ["name"]


def test_technique_plan_skips_techniques_the_endpoint_cannot_support() -> None:
    hybrid = _payload()
    hybrid["strategy"] = "HYBRID"
    hybrid["seed_cases"] = [_post_case("seed")]
    request = GenerateRequest.model_validate(hybrid)
    post = request.endpoints[0]

    # No security anywhere: AUTH is dropped. Related endpoint exists: SEQUENCE stays.
    assert technique_plan(request, post, 3, has_related=True) == [
        "SEQUENCE",
        "BUSINESS_RULE",
        "SEQUENCE",
    ]
    assert technique_plan(request, post, 2, has_related=False) == ["BUSINESS_RULE"] * 2

    llm = GenerateRequest.model_validate(_payload(techniques=["AUTH"]))
    # Nothing else requested: AUTH is kept rather than asking for nothing.
    assert technique_plan(llm, llm.endpoints[0], 2, has_related=False) == ["AUTH", "AUTH"]


def test_related_endpoints_of_the_same_resource_are_listed_for_sequence() -> None:
    hybrid = _payload()
    hybrid["strategy"] = "HYBRID"
    hybrid["endpoints"].append(copy.deepcopy(GET_HEALTH))
    hybrid["seed_cases"] = [_post_case("seed")]
    request = GenerateRequest.model_validate(hybrid)

    read_item = {
        "method": "GET",
        "path": "/items/{id}",
        "summary": "Read an item",
        "path_params": ["id"],
        "responses": ["200", "404"],
    }
    # S10-01 r3: every other selected endpoint is listed, the same resource first.
    related = _data_block(build_user_prompt(request, request.endpoints[0], 3), "related_endpoints")
    assert related == [read_item, {"method": "GET", "path": "/health", "responses": ["200"]}]

    # The resource-only fallback (used when the full list does not fit the prompt limits).
    related = _data_block(
        build_user_prompt(request, request.endpoints[0], 3, related_scope="resource"),
        "related_endpoints",
    )
    assert related == [read_item]


# --- HYBRID seeds -------------------------------------------------------------------------


def _dsl_validator() -> jsonschema.Draft202012Validator:
    schema = json.loads(
        (REPO_ROOT / "docs" / "contracts" / "test-dsl.schema.json").read_text(encoding="utf-8")
    )
    return jsonschema.Draft202012Validator(schema)


def test_hybrid_prompt_shows_only_a_few_seeds_of_the_target_in_dsl_shape() -> None:
    payload = _payload()
    payload["strategy"] = "HYBRID"
    post_seeds = [_post_case(f"Seed create {i}") for i in range(4)]
    for seed in post_seeds:
        seed["source"] = "RULE"
    payload["seed_cases"] = [{"dsl": s} for s in post_seeds] + [_get_case("Seed read")]
    provider = FakeLlmProvider([_answer(), _answer()])

    _service(provider).generate(GenerateRequest.model_validate(payload))

    post_block = _data_block(provider.calls[0][1].content, "seed_cases")
    get_block = _data_block(provider.calls[1][1].content, "seed_cases")
    assert len(post_block) == MAX_HYBRID_SEEDS_PER_ENDPOINT
    assert [s["title"] for s in post_block] == ["Seed create 0", "Seed create 1"]
    assert [s["title"] for s in get_block] == ["Seed read"]
    validator = _dsl_validator()
    for seed in post_block + get_block:
        assert list(validator.iter_errors(seed)) == []


def test_hybrid_case_equal_to_a_seed_not_shown_in_the_prompt_is_still_a_duplicate() -> None:
    payload = _payload()
    payload["strategy"] = "HYBRID"
    seeds = [_post_case(f"Seed create {i}") for i in range(3)]
    payload["seed_cases"] = seeds
    # The model repeats the third seed, which was cut from the prompt.
    provider = FakeLlmProvider([_answer(copy.deepcopy(seeds[2])), _answer(_get_case("Read 1"))])

    response = _service(provider).generate(GenerateRequest.model_validate(payload))

    assert [tc.status for tc in response.test_cases] == ["DUPLICATE", "VALID"]


# --- path normalisation -------------------------------------------------------------------


SPEC = [POST_ITEMS, GET_ITEM, {"method": "GET", "path": "/items/{id}/tags/{tag}"}]


def _step(method: str, path: str, **request: Any) -> dict[str, Any]:
    return {"steps": [{"request": {"method": method, "path": path, **request}}]}


@pytest.mark.parametrize(
    ("path", "path_params", "expected"),
    [
        ("/items/{{item_id}}", None, {"id": "{{item_id}}"}),
        ("/items/7", None, {"id": 7}),
        ("/items/abc", None, {"id": "abc"}),
        ("/items/7", {"id": 9}, {"id": 9}),
        ("/items/{item_id}", {"item_id": "{{x}}"}, {"id": "{{x}}"}),
        ("/items/7/tags/red", None, {"id": 7, "tag": "red"}),
    ],
)
def test_concrete_paths_are_rewritten_to_the_declared_template(
    path: str, path_params: dict[str, Any] | None, expected: dict[str, Any]
) -> None:
    extra = {"path_params": path_params} if path_params is not None else {}
    dsl = {"endpoint": {"method": "GET", "path": path}, **_step("GET", path, **extra)}

    normalize_request_paths(dsl, SPEC)

    request = dsl["steps"][0]["request"]
    assert request["path"] in ("/items/{id}", "/items/{id}/tags/{tag}")
    assert dsl["endpoint"]["path"] == request["path"]
    assert request["path_params"] == expected


@pytest.mark.parametrize(
    ("method", "path"),
    [("GET", "/items/{id}"), ("GET", "/ghost/1"), ("DELETE", "/items/1"), ("GET", "/items")],
)
def test_declared_unknown_or_other_method_paths_are_left_alone(method: str, path: str) -> None:
    dsl = _step(method, path)
    before = copy.deepcopy(dsl)
    normalize_request_paths(dsl, SPEC)
    assert dsl == before


def test_llm_case_with_variable_in_path_becomes_valid_without_repair() -> None:
    sequence = _post_case("Create then read")
    sequence["technique"] = "SEQUENCE"
    sequence["steps"][0]["extract"] = [{"var": "item_id", "from": "body", "path": "$.id"}]
    sequence["steps"].append(
        {
            "name": "read",
            "request": {"method": "GET", "path": "/items/{{item_id}}"},
            "assertions": [{"type": "status", "equals": 200}],
        }
    )
    provider = FakeLlmProvider([_answer(sequence), _answer()])

    response = _service(provider).generate(GenerateRequest.model_validate(_payload()))

    (case,) = response.test_cases
    assert (case.status, case.repair_attempts) == ("VALID", 0)
    assert case.dsl["steps"][1]["request"] == {
        "method": "GET",
        "path": "/items/{id}",
        "path_params": {"id": "{{item_id}}"},
    }
    assert len(provider.calls) == 2


# --- consistent, documented budgets ---------------------------------------------------------


def _env_value(path: Path, key: str) -> str:
    match = re.search(rf"^{key}=(.*)$", path.read_text(encoding="utf-8"), re.MULTILINE)
    assert match is not None, f"{key} missing from {path}"
    return match.group(1).strip()


def test_default_budgets_are_consistent_and_documented() -> None:
    settings = Settings()
    web_timeout = int(_env_value(repo_file("web", ".env.example"), "AI_SERVICE_TIMEOUT"))
    example = repo_file("ai-service", ".env.example")

    # A call never outlives the request budget, which ends before the web gives up.
    assert settings.llm_timeout_seconds < settings.generate_budget_seconds
    assert settings.generate_budget_seconds + 60 <= web_timeout
    # max_tokens of a default call (3 cases) is generated within the per-call timeout at a
    # pessimistic 10 tokens/s, which leaves the 18 tokens/s laptop rate a wide margin.
    generate_tokens = settings.llm_cases_per_call * settings.llm_output_tokens_per_case + 256
    assert generate_tokens / 10 < settings.llm_timeout_seconds
    for key, value in {
        "AI_LLM_TIMEOUT_SECONDS": settings.llm_timeout_seconds,
        "AI_GENERATE_BUDGET_SECONDS": settings.generate_budget_seconds,
        "AI_MAX_PROMPT_CHARS": settings.max_prompt_chars,
        "AI_LLM_CONTEXT_TOKENS": settings.llm_context_tokens,
        "AI_LLM_CASES_PER_CALL": settings.llm_cases_per_call,
        "AI_LLM_OUTPUT_TOKENS_PER_CASE": settings.llm_output_tokens_per_case,
    }.items():
        assert float(_env_value(example, key)) == float(value), key
    assert settings.llm_context_tokens == 16384


def test_openai_compatible_provider_sends_max_tokens_only_when_given() -> None:
    bodies: list[dict[str, Any]] = []

    def handler(request: httpx.Request) -> httpx.Response:
        bodies.append(json.loads(request.content))
        return httpx.Response(
            200, json={"model": "m", "choices": [{"message": {"content": "{}"}}], "usage": {}}
        )

    provider = OpenAICompatibleProvider(
        "http://llm.local/v1", "m", transport=httpx.MockTransport(handler)
    )
    messages = [ChatMessage(role="user", content="hi")]
    provider.complete(messages, temperature=0.2, max_tokens=1456)
    provider.complete(messages, temperature=0.2)

    assert bodies[0]["max_tokens"] == 1456
    assert "max_tokens" not in bodies[1]
