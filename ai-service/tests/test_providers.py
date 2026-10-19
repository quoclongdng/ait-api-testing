import json

import httpx
import pytest

from app.config import Settings
from app.contracts.llm import ChatMessage, LlmProviderError
from app.providers.factory import make_provider
from app.providers.fake import FakeLlmProvider
from app.providers.openai_compatible import OpenAICompatibleProvider

MESSAGES = [ChatMessage(role="user", content="generate tests")]


def test_fake_provider_returns_queued_responses_then_default() -> None:
    provider = FakeLlmProvider(responses=['{"test_cases": [1]}'])

    assert provider.complete(MESSAGES, temperature=0).content == '{"test_cases": [1]}'
    assert provider.complete(MESSAGES, temperature=0).content == '{"test_cases": []}'
    assert len(provider.calls) == 2


def test_factory_selects_provider_from_settings() -> None:
    assert make_provider(Settings(llm_provider="fake")).name == "fake"
    assert make_provider(Settings(llm_provider="openai_compatible")).name == "openai_compatible"


def test_openai_compatible_provider_sends_chat_completion_request() -> None:
    captured: dict[str, object] = {}

    def handler(request: httpx.Request) -> httpx.Response:
        captured["url"] = str(request.url)
        captured["auth"] = request.headers.get("Authorization")
        captured["body"] = json.loads(request.content)
        return httpx.Response(
            200,
            json={
                "model": "qwen",
                "choices": [{"message": {"content": "{}"}}],
                "usage": {"prompt_tokens": 5, "completion_tokens": 1},
            },
        )

    provider = OpenAICompatibleProvider(
        base_url="http://llm.local/v1/",
        model="qwen",
        api_key="test-key",
        transport=httpx.MockTransport(handler),
    )
    completion = provider.complete(MESSAGES, temperature=0.2, seed=7)

    assert captured["url"] == "http://llm.local/v1/chat/completions"
    assert captured["auth"] == "Bearer test-key"
    body = captured["body"]
    assert isinstance(body, dict)
    assert body["seed"] == 7
    assert body["response_format"] == {"type": "json_object"}
    assert completion.content == "{}"
    assert completion.prompt_tokens == 5


def test_openai_compatible_provider_wraps_http_errors() -> None:
    provider = OpenAICompatibleProvider(
        base_url="http://llm.local/v1",
        model="qwen",
        transport=httpx.MockTransport(lambda _: httpx.Response(500)),
    )

    with pytest.raises(LlmProviderError):
        provider.complete(MESSAGES, temperature=0)
