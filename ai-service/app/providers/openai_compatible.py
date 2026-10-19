import math
import re
import time
from collections.abc import Callable
from typing import Any

import httpx

from app.contracts.llm import ChatMessage, LlmCompletion, LlmProviderError

# Query string of an http(s) URL inside an error message (may carry an api key).
_URL_QUERY = re.compile(r"(https?://[^\s'\"?]+)\?[^\s'\"]*")

# HTTP statuses retried after waiting (rate limit, temporary overload).
_RETRY_STATUSES = frozenset({429, 503})
# Groq/OpenAI style durations in x-ratelimit-reset-* headers: "1m2.5s", "7.66s", "250ms".
_DURATION_PART = re.compile(r"(\d+(?:\.\d+)?)(ms|s|m|h)")
# Wait used when the provider gives no hint (attempt 1, 2, 3...: 2 s, 4 s, 8 s...).
_DEFAULT_BACKOFF_SECONDS = 2.0
# Never wait longer than this for one retry, whatever the provider says.
_MAX_WAIT_SECONDS = 60.0


class OpenAICompatibleProvider:
    """Calls any server implementing POST /chat/completions (Ollama, vLLM, OpenAI, ...)."""

    name = "openai_compatible"

    def __init__(
        self,
        base_url: str,
        model: str,
        api_key: str = "",
        timeout_seconds: float = 120.0,
        transport: httpx.BaseTransport | None = None,
        rate_limit_retries: int = 3,
        sleep: Callable[[float], None] = time.sleep,
        clock: Callable[[], float] = time.monotonic,
    ) -> None:
        headers = {"Authorization": f"Bearer {api_key}"} if api_key else {}
        self._api_key = api_key
        self._model = model
        self._timeout_seconds = timeout_seconds
        self._rate_limit_retries = max(0, rate_limit_retries)
        self._sleep = sleep
        self._clock = clock
        self._client = httpx.Client(
            base_url=base_url.rstrip("/"),
            headers=headers,
            timeout=timeout_seconds,
            transport=transport,
        )

    def close(self) -> None:
        """Release the pooled HTTP connections."""
        self._client.close()

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
        target_model = model or self._model
        payload: dict[str, object] = {
            "model": target_model,
            "messages": [{"role": m.role, "content": m.content} for m in messages],
            "temperature": temperature,
        }
        if seed is not None:
            payload["seed"] = seed
        if max_tokens is not None:
            # Bounds the completion (Ollama maps it to num_predict), so one call cannot
            # run past its timeout generating more cases than were asked for.
            payload["max_tokens"] = max_tokens
        if json_mode:
            payload["response_format"] = {"type": "json_object"}

        # The whole call, retries and waits included, stays within its timeout (the generate
        # budget already clipped it), so a rate limit can never push a job past its budget.
        call_timeout = timeout if timeout is not None else self._timeout_seconds
        deadline = self._clock() + call_timeout
        attempt = 0
        try:
            while True:
                # First attempt: exactly the timeout asked for; a retry: what is left of it.
                remaining = call_timeout if attempt == 0 else max(deadline - self._clock(), 0.001)
                response = self._client.post("/chat/completions", json=payload, timeout=remaining)
                if response.status_code in _RETRY_STATUSES and attempt < self._rate_limit_retries:
                    wait = _retry_wait(response, attempt)
                    # Retry only if the wait leaves time for a real attempt afterwards.
                    if wait + 1.0 < deadline - self._clock():
                        attempt += 1
                        self._sleep(wait)
                        continue
                response.raise_for_status()
                data = response.json()
                break
        except (httpx.HTTPError, ValueError) as exc:
            # Users see a short Vietnamese reason without the provider URL; the redacted raw
            # httpx text stays in llm_calls[].error (D09).
            raise LlmProviderError(
                _user_message(exc, target_model), detail=self._redact(str(exc))
            ) from exc
        try:
            return _parse_completion(data, target_model)
        except LlmProviderError as exc:
            raise LlmProviderError(
                "Nhà cung cấp LLM trả dữ liệu không đúng định dạng chat completion.",
                detail=self._redact(exc.detail),
            ) from exc

    def _redact(self, message: str) -> str:
        """Remove the API key and URL query strings from an error message (docs/DESIGN.md §9).

        The message ends up in llm_calls[].error, which is returned to the web and stored
        in llm_call_logs; httpx errors embed the full request URL and upstream bodies can
        echo the credentials that were sent.
        """
        redacted = _URL_QUERY.sub(r"\1?***", message)
        if self._api_key:
            redacted = redacted.replace(self._api_key, "***")
        return redacted


_STATUS_HINTS = {
    400: "yêu cầu bị từ chối, kiểm tra tham số model",
    401: "khóa API không hợp lệ",
    403: "khóa API không có quyền dùng model này",
    404: "model không tồn tại trên nhà cung cấp",
    413: "prompt quá lớn",
    429: "vượt giới hạn tốc độ của nhà cung cấp, hãy thử lại sau",
}


def _user_message(exc: Exception, model: str) -> str:
    """Short Vietnamese reason for a failed call, never the provider URL (shown on M11)."""
    if isinstance(exc, httpx.HTTPStatusError):
        status = exc.response.status_code
        hint = _STATUS_HINTS.get(status) or (
            "lỗi phía nhà cung cấp" if status >= 500 else "yêu cầu không được chấp nhận"
        )
        return f"Nhà cung cấp LLM trả lỗi HTTP {status} cho model '{model}': {hint}."
    if isinstance(exc, httpx.TimeoutException):
        return "Nhà cung cấp LLM không trả lời trong thời gian cho phép (timeout)."
    if isinstance(exc, httpx.HTTPError):
        return "Không kết nối được tới nhà cung cấp LLM."
    return "Nhà cung cấp LLM trả dữ liệu không phải JSON."


def _retry_wait(response: httpx.Response, attempt: int) -> float:
    """Seconds to wait before retrying a 429/503, as the provider asks.

    Retry-After (seconds) first, then the longest x-ratelimit-reset-requests/-tokens duration
    (Groq, OpenAI), else exponential backoff. Always within [0.1, _MAX_WAIT_SECONDS].
    """
    hints: list[float] = []
    for header in ("retry-after", "x-ratelimit-reset-requests", "x-ratelimit-reset-tokens"):
        value = response.headers.get(header)
        if value is not None:
            parsed = _parse_duration(value)
            if parsed is not None:
                hints.append(parsed)
    wait = max(hints) if hints else _DEFAULT_BACKOFF_SECONDS * (2**attempt)
    return min(max(wait, 0.1), _MAX_WAIT_SECONDS)


def _parse_duration(value: str) -> float | None:
    """Parse "1m2.5s", "7.66s", "250ms" or a bare number of seconds; None if unreadable."""
    text = value.strip()
    try:
        return float(text)
    except ValueError:
        pass
    parts = _DURATION_PART.findall(text)
    if not parts or "".join(n + u for n, u in parts) != text:
        return None
    factor = {"ms": 0.001, "s": 1.0, "m": 60.0, "h": 3600.0}
    return sum(float(number) * factor[unit] for number, unit in parts)


def _parse_completion(data: Any, target_model: str) -> LlmCompletion:
    """Turn a chat-completion body into an LlmCompletion or raise LlmProviderError.

    Any malformed shape is a provider fault (502 LLM_PROVIDER_ERROR), never a 500.
    """
    try:
        if not isinstance(data, dict):
            raise TypeError(f"response body must be a JSON object, got {type(data).__name__}")
        content = data["choices"][0]["message"]["content"]
        if not isinstance(content, str):
            raise TypeError(f"message.content must be a string, got {type(content).__name__}")
        usage = data.get("usage")
        if not isinstance(usage, dict):
            usage = {}
        model = data.get("model")
        return LlmCompletion(
            content=content,
            model=model if isinstance(model, str) else target_model,
            prompt_tokens=_token_count(usage.get("prompt_tokens")),
            completion_tokens=_token_count(usage.get("completion_tokens")),
            raw=data,
        )
    except (KeyError, IndexError, TypeError, AttributeError, ValueError) as exc:
        raise LlmProviderError(f"Malformed provider response: {exc}") from exc


def _token_count(value: Any) -> int:
    """Coerce a reported token count; missing, null or non-numeric values become 0."""
    if isinstance(value, bool) or not isinstance(value, int | float):
        return 0
    if isinstance(value, float) and not math.isfinite(value):
        return 0
    return int(value)
