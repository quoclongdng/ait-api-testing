import pytest
from fastapi.testclient import TestClient

from app.config import Settings, get_settings
from app.main import create_app
from tests.repo_files import repo_file

RELEASE_VERSION = "1.0.0"


def test_health_reports_provider_and_version(client: TestClient) -> None:
    response = client.get("/health")

    assert response.status_code == 200
    body = response.json()
    assert body["status"] == "ok"
    assert body["provider"] == "fake"
    # Effective setting, so a local ai-service/.env (AI_APP_VERSION) cannot break this test;
    # the release value itself is pinned by the two tests below.
    assert body["version"] == Settings().app_version


def test_code_default_version_is_the_release_version(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.delenv("AI_APP_VERSION", raising=False)
    settings = Settings(_env_file=None)

    assert settings.app_version == RELEASE_VERSION


def test_env_example_version_matches_the_code_default() -> None:
    """PR #137 review: `make setup` copies .env.example to .env, which overrides the default."""
    values = [
        line.split("=", 1)[1].strip()
        for line in repo_file("ai-service", ".env.example").read_text(encoding="utf-8").splitlines()
        if line.startswith("AI_APP_VERSION=")
    ]

    assert values in ([], [RELEASE_VERSION])


def test_health_reports_non_secret_llm_defaults_for_m04() -> None:
    """S10-01 web-ui r2: M04 must show the system model (docs/DESIGN.md §8), read from /health."""
    app = create_app()
    app.dependency_overrides[get_settings] = lambda: Settings(
        llm_provider="openai_compatible",
        llm_model="qwen2.5-coder:7b",
        llm_temperature=0.3,
        llm_seed=1024,
        llm_api_key="do-not-leak",
    )
    with TestClient(app) as test_client:
        body = test_client.get("/health").json()

    assert body["model"] == "qwen2.5-coder:7b"
    assert body["temperature"] == 0.3
    assert body["seed"] == 1024
    assert "do-not-leak" not in str(body)
    assert set(body) == {
        "status",
        "provider",
        "version",
        "model",
        "temperature",
        "seed",
        "generate_budget_seconds",
    }


def test_health_reports_generate_budget_for_the_web_timeout_guard() -> None:
    """S11-02: the web compares its AI_SERVICE_TIMEOUT with this budget before generating."""
    app = create_app()
    app.dependency_overrides[get_settings] = lambda: Settings(generate_budget_seconds=900.0)
    with TestClient(app) as test_client:
        body = test_client.get("/health").json()

    assert body["generate_budget_seconds"] == 900.0


def test_health_reports_the_default_generate_budget(client: TestClient) -> None:
    body = client.get("/health").json()
    assert body["generate_budget_seconds"] == Settings().generate_budget_seconds
