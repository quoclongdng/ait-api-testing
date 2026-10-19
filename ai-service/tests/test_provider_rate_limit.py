"""HTTP 429/503 from an OpenAI-compatible provider: wait as asked, retry within the call timeout."""

import httpx
import pytest

from app.config import Settings
from app.contracts.llm import ChatMessage, LlmProviderError
from app.providers.factory import make_provider
from app.providers.openai_compatible import OpenAICompatibleProvider, _parse_duration, _retry_wait

MESSAGES = [ChatMessage(role="user", content="generate tests")]
OK_BODY = {
    "model": "qwen",
    "choices": [{"message": {"content": "{}"}}],
    "usage": {"prompt_tokens": 3, "completion_tokens": 1},
}


class FakeClock:
    """Monotonic clock advanced only by the provider's sleep, so tests never really wait."""

    def __init__(self) -> None:
        self.now = 0.0
        self.sleeps: list[float] = []

    def clock(self) -> float:
        return self.now

    def sleep(self, seconds: float) -> None:
        self.sleeps.append(seconds)
        self.now += seconds


def _provider(
    responses: list[httpx.Response], clock: FakeClock, retries: int = 3, timeout: float = 120.0
) -> tuple[OpenAICompatibleProvider, list[int]]:
    calls: list[int] = []

    def handler(_: httpx.Request) -> httpx.Response:
        calls.append(len(calls))
        return responses[min(len(calls) - 1, len(responses) - 1)]

    provider = OpenAICompatibleProvider(
        base_url="http://llm.local/v1",
        model="qwen",
        api_key="gsk_secret_value",
        timeout_seconds=timeout,
        transport=httpx.MockTransport(handler),
        rate_limit_retries=retries,
        sleep=clock.sleep,
        clock=clock.clock,
    )
    return provider, calls


def test_a_429_is_retried_after_the_wait_the_provider_asks_for() -> None:
    clock = FakeClock()
    provider, calls = _provider(
        [httpx.Response(429, headers={"retry-after": "7"}), httpx.Response(200, json=OK_BODY)],
        clock,
    )

    completion = provider.complete(MESSAGES, temperature=0)

    assert completion.content == "{}"
    assert len(calls) == 2
    assert clock.sleeps == [7.0]


def test_groq_reset_headers_are_used_and_the_longest_one_wins() -> None:
    clock = FakeClock()
    provider, calls = _provider(
        [
            httpx.Response(
                429,
                headers={
                    "x-ratelimit-reset-requests": "2.5s",
                    "x-ratelimit-reset-tokens": "1m2.5s",
                },
            ),
            httpx.Response(503),
            httpx.Response(200, json=OK_BODY),
        ],
        clock,
    )

    provider.complete(MESSAGES, temperature=0)

    assert len(calls) == 3
    assert clock.sleeps == [
        60.0,
        4.0,
    ]  # 62.5 s capped at 60 s; then backoff 2 * 2**1 without a hint


def test_retries_stop_after_the_configured_number_and_the_error_is_redacted() -> None:
    clock = FakeClock()
    provider, calls = _provider(
        [httpx.Response(429, text="limit for key gsk_secret_value")], clock, retries=2
    )

    with pytest.raises(LlmProviderError) as excinfo:
        provider.complete(MESSAGES, temperature=0)

    assert len(calls) == 3
    assert "429" in str(excinfo.value)
    assert "gsk_secret_value" not in str(excinfo.value)


def test_no_retry_when_the_wait_would_pass_the_call_timeout() -> None:
    clock = FakeClock()
    provider, calls = _provider(
        [httpx.Response(429, headers={"retry-after": "30"}), httpx.Response(200, json=OK_BODY)],
        clock,
    )

    with pytest.raises(LlmProviderError):
        provider.complete(MESSAGES, temperature=0, timeout=20)

    assert len(calls) == 1
    assert clock.sleeps == []


def test_zero_retries_keeps_the_old_behaviour_and_other_errors_are_not_retried() -> None:
    clock = FakeClock()
    provider, calls = _provider([httpx.Response(429)], clock, retries=0)
    with pytest.raises(LlmProviderError):
        provider.complete(MESSAGES, temperature=0)
    assert len(calls) == 1

    provider, calls = _provider([httpx.Response(400)], clock)
    with pytest.raises(LlmProviderError):
        provider.complete(MESSAGES, temperature=0)
    assert len(calls) == 1


def test_duration_parsing_and_wait_bounds() -> None:
    assert _parse_duration("7.66s") == pytest.approx(7.66)
    assert _parse_duration("1m2.5s") == pytest.approx(62.5)
    assert _parse_duration("250ms") == pytest.approx(0.25)
    assert _parse_duration("3") == pytest.approx(3.0)
    assert _parse_duration("soon") is None
    assert _retry_wait(httpx.Response(429), 0) == pytest.approx(2.0)
    assert _retry_wait(httpx.Response(429, headers={"retry-after": "0"}), 0) == pytest.approx(0.1)
    assert _retry_wait(httpx.Response(429, headers={"retry-after": "999"}), 0) == pytest.approx(
        60.0
    )


def test_factory_passes_the_retry_setting() -> None:
    provider = make_provider(Settings(llm_provider="openai_compatible", llm_rate_limit_retries=5))
    assert isinstance(provider, OpenAICompatibleProvider)
    assert provider._rate_limit_retries == 5
