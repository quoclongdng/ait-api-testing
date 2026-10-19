"""ADR-0007 production profile AI_LLM_PROVIDER=disabled: ai-service runs without any LLM."""

import pytest
from fastapi.testclient import TestClient

from app.config import Settings, get_settings
from app.contracts.llm import ChatMessage, LlmProviderError
from app.main import create_app
from app.providers.disabled import LLM_DISABLED_MESSAGE, DisabledLlmProvider
from app.providers.factory import make_provider
from tests.test_generate import VALID_REQUEST_PAYLOAD


def _client(settings: Settings) -> TestClient:
    app = create_app()
    app.dependency_overrides[get_settings] = lambda: settings
    return TestClient(app)


def test_factory_returns_the_disabled_provider_for_disabled() -> None:
    provider = make_provider(Settings(llm_provider="disabled"))
    assert provider.name == "disabled"
    with pytest.raises(LlmProviderError, match="AI_LLM_PROVIDER=disabled"):
        provider.complete([ChatMessage(role="user", content="hi")], temperature=0.2)


def test_disabled_provider_message_is_vietnamese_and_points_to_rule() -> None:
    with pytest.raises(LlmProviderError) as raised:
        DisabledLlmProvider().complete([], temperature=0.0)
    assert "RULE" in str(raised.value)
    assert "chỉ dùng được" in str(raised.value)


def test_health_reports_provider_disabled_for_the_web() -> None:
    with _client(Settings(llm_provider="disabled")) as client:
        body = client.get("/health").json()
    assert body["status"] == "ok"
    assert body["provider"] == "disabled"


def test_generate_refuses_without_calling_a_model() -> None:
    with _client(Settings(llm_provider="disabled")) as client:
        response = client.post("/v1/generate", json=VALID_REQUEST_PAYLOAD)
    assert response.status_code == 502
    body = response.json()
    assert body["error"]["code"] == "LLM_PROVIDER_ERROR"
    assert body["error"]["message"] == LLM_DISABLED_MESSAGE
    assert body["error"]["details"] == {"reason": "LLM_DISABLED", "llm_calls": []}
