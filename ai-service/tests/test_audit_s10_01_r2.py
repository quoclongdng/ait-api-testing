"""Regression tests for the S10-01 ai-service audit findings (round 2).

Each test reproduces one verified finding and fails on the code before the fix.
"""

import copy
import json
import re
from typing import Any

import jsonschema
import pytest
from fastapi.testclient import TestClient

from app.api.deps import get_provider
from app.config import Settings, get_settings
from app.contracts.llm import ChatMessage, LlmCompletion
from app.main import create_app
from app.providers.fake import FakeLlmProvider
from app.providers.openai_compatible import OpenAICompatibleProvider
from app.schemas.generate import GenerateRequest
from app.services.generator import GeneratorService
from app.services.prompts import (
    HYBRID_SYSTEM_PROMPT,
    SYSTEM_PROMPT,
    build_generation_messages,
)
from app.services.validator import DslValidator
from tests.repo_files import REPO_ROOT, repo_file
from tests.test_generate import VALID_DSL_CASE, VALID_REQUEST_PAYLOAD


def _dsl_validator() -> jsonschema.Draft202012Validator:
    schema = json.loads(
        (REPO_ROOT / "docs" / "contracts" / "test-dsl.schema.json").read_text(encoding="utf-8")
    )
    return jsonschema.Draft202012Validator(schema)


def _hybrid_payload() -> dict[str, Any]:
    payload = copy.deepcopy(VALID_REQUEST_PAYLOAD)
    payload["strategy"] = "HYBRID"
    seed = copy.deepcopy(VALID_DSL_CASE)
    seed["source"] = "RULE"
    seed["description"] = "Rule-based happy path"
    payload["seed_cases"] = [{"dsl": seed}]
    return payload


def _data_block(content: str, name: str) -> Any:
    match = re.search(rf"<<<DATA:{name}>>>\n(.*?)\n<<<END DATA:{name}>>>", content, re.DOTALL)
    assert match is not None
    return json.loads(match.group(1))


# Finding: prompt advertised json_schema schema_ref, which list specs always reject -----


@pytest.mark.parametrize("prompt", [SYSTEM_PROMPT, HYBRID_SYSTEM_PROMPT], ids=["llm", "hybrid"])
def test_prompt_json_schema_example_is_accepted_by_the_validator(prompt: str) -> None:
    line = next(ln for ln in prompt.splitlines() if '"json_schema"' in ln)
    example = json.loads(line[line.index("{") :])
    assert "schema_ref" not in example

    case = copy.deepcopy(VALID_DSL_CASE)
    case["steps"][0]["assertions"].append(example)
    spec = list(VALID_REQUEST_PAYLOAD["endpoints"])
    result = DslValidator().validate(case, spec=spec)
    assert result.is_valid, result.errors


# Finding: LLM strategy kept a model-emitted source (RULE/MANUAL) -------------------------


@pytest.mark.parametrize("emitted", ["RULE", "MANUAL", "HYBRID"])
def test_llm_strategy_forces_source_llm(emitted: str) -> None:
    case = copy.deepcopy(VALID_DSL_CASE)
    case["source"] = emitted
    provider = FakeLlmProvider([json.dumps({"test_cases": [case]})])
    service = GeneratorService(provider=provider, settings=Settings(llm_provider="fake"))

    response = service.generate(GenerateRequest.model_validate(VALID_REQUEST_PAYLOAD))

    assert [(tc.status, tc.dsl["source"]) for tc in response.test_cases] == [("VALID", "LLM")]


def test_llm_strategy_forces_source_llm_on_repaired_case() -> None:
    bad = copy.deepcopy(VALID_DSL_CASE)
    bad["dsl_version"] = "bad"
    repaired = copy.deepcopy(VALID_DSL_CASE)
    repaired["source"] = "MANUAL"
    provider = FakeLlmProvider([json.dumps({"test_cases": [bad]}), json.dumps(repaired)])
    service = GeneratorService(provider=provider, settings=Settings(llm_provider="fake"))

    response = service.generate(GenerateRequest.model_validate(VALID_REQUEST_PAYLOAD))

    assert [(tc.status, tc.dsl["source"]) for tc in response.test_cases] == [("VALID", "LLM")]


# Finding: HYBRID seeds were summarised in a non-DSL shape (index, step_count) ------------


def test_hybrid_seed_cases_in_prompt_are_valid_dsl() -> None:
    request = GenerateRequest.model_validate(_hybrid_payload())
    user_prompt = build_generation_messages(request)[1].content
    seeds = _data_block(user_prompt, "seed_cases")

    assert isinstance(seeds, list) and len(seeds) == 1
    validator = _dsl_validator()
    for seed in seeds:
        assert list(validator.iter_errors(seed)) == []
        assert "index" not in seed and "step_count" not in seed
        assert seed["dsl_version"] == "1.0"


# Finding: provider silently truncates prompts beyond its context -------------------------


class _TokenReportingProvider(FakeLlmProvider):
    """Fake provider reporting a fixed prompt token count, as Ollama does."""

    def __init__(self, responses: list[str], prompt_tokens: int | None) -> None:
        super().__init__(responses)
        self.prompt_tokens = prompt_tokens

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
        base = super().complete(
            messages, temperature=temperature, seed=seed, json_mode=json_mode, model=model
        )
        tokens = self.prompt_tokens
        if tokens is None:
            tokens = sum(len(m.content) for m in messages) // 4
        return LlmCompletion(
            content=base.content, model=base.model, prompt_tokens=tokens, completion_tokens=10
        )


def _long_payload() -> dict[str, Any]:
    payload = copy.deepcopy(VALID_REQUEST_PAYLOAD)
    payload["endpoints"][0]["description"] = "Creates a new item in the catalogue. " * 400
    return payload


def _client(provider: Any) -> TestClient:
    app = create_app()
    # 60000 characters (above the 36000 default) so the 40000-character truncation case
    # still reaches the provider; it fits the 16384-token context with the GENERATE reserve.
    app.dependency_overrides[get_settings] = lambda: Settings(
        llm_provider="fake", max_prompt_chars=60000
    )
    app.dependency_overrides[get_provider] = lambda: provider
    return TestClient(app)


def test_truncated_generation_prompt_is_rejected_with_logged_call() -> None:
    # 2050 prompt tokens for a ~15k-character prompt: the head was dropped.
    provider = _TokenReportingProvider([json.dumps({"test_cases": [VALID_DSL_CASE]})], 2050)

    response = _client(provider).post("/v1/generate", json=_long_payload())

    assert response.status_code == 502
    error = response.json()["error"]
    assert error["code"] == "LLM_PROVIDER_ERROR"
    assert "truncated" in error["message"]
    calls = error["details"]["llm_calls"]
    assert len(calls) == 1
    assert calls[0]["purpose"] == "GENERATE"
    assert calls[0]["prompt_tokens"] == 2050
    assert "truncated" in calls[0]["error"]


def test_untruncated_long_prompt_is_accepted() -> None:
    provider = _TokenReportingProvider([json.dumps({"test_cases": [VALID_DSL_CASE]})], None)

    response = _client(provider).post("/v1/generate", json=_long_payload())

    assert response.status_code == 200
    assert response.json()["test_cases"][0]["status"] == "VALID"


def test_compose_ollama_context_covers_the_prompt_budget() -> None:
    compose = repo_file("docker", "docker-compose.yml").read_text(encoding="utf-8")
    ollama = compose[compose.index("\n  ollama:") :]
    match = re.search(r'OLLAMA_CONTEXT_LENGTH:\s*"?(\d+)"?', ollama)
    assert match is not None
    # About 4 characters per token, plus room for the completion.
    assert int(match.group(1)) * 4 > Settings().max_prompt_chars + 8000


# Review PR #111: partial truncations slipped through the loose 7.0 ratio --------------


def _payload_with_chars(target_chars: int) -> dict[str, Any]:
    payload = copy.deepcopy(VALID_REQUEST_PAYLOAD)
    request = GenerateRequest.model_validate(payload)
    base = sum(len(m.content) for m in build_generation_messages(request))
    # The description is JSON-encoded once in the prompt, so one char adds one char.
    filler = "Creates a new item in the catalogue. "
    payload["endpoints"][0]["description"] = (filler * (target_chars // len(filler) + 1))[
        : max(0, target_chars - base)
    ]
    return payload


def _prompt_len(payload: dict[str, Any]) -> int:
    request = GenerateRequest.model_validate(payload)
    return sum(len(m.content) for m in build_generation_messages(request))


@pytest.mark.parametrize(
    ("chars", "tokens"),
    [(20000, 4096), (18800, 4096), (9500, 2048), (12000, 2048), (40000, 8192)],
)
def test_partial_truncation_at_context_size_is_rejected(chars: int, tokens: int) -> None:
    payload = _payload_with_chars(chars)
    assert abs(_prompt_len(payload) - chars) < 200
    provider = _TokenReportingProvider([json.dumps({"test_cases": [VALID_DSL_CASE]})], tokens)

    response = _client(provider).post("/v1/generate", json=payload)

    assert response.status_code == 502, response.json()
    error = response.json()["error"]
    assert error["code"] == "LLM_PROVIDER_ERROR"
    assert "truncated" in error["message"]
    assert error["details"]["llm_calls"][0]["prompt_tokens"] == tokens


def test_ratio_above_5_5_is_rejected_off_context_sizes() -> None:
    # 30000 characters reported as 5000 tokens (ratio 6.0) is not a power of two.
    provider = _TokenReportingProvider([json.dumps({"test_cases": [VALID_DSL_CASE]})], 5000)

    response = _client(provider).post("/v1/generate", json=_payload_with_chars(30000))

    assert response.status_code == 502
    assert "truncated" in response.json()["error"]["message"]


@pytest.mark.parametrize(("chars", "tokens"), [(20000, 5000), (16300, 4096), (9000, 2300)])
def test_consistent_token_counts_are_accepted(chars: int, tokens: int) -> None:
    provider = _TokenReportingProvider([json.dumps({"test_cases": [VALID_DSL_CASE]})], tokens)

    response = _client(provider).post("/v1/generate", json=_payload_with_chars(chars))

    assert response.status_code == 200, response.json()


def test_prompt_beyond_configured_context_is_not_sent() -> None:
    provider = _TokenReportingProvider([json.dumps({"test_cases": [VALID_DSL_CASE]})], None)
    app = create_app()
    app.dependency_overrides[get_settings] = lambda: Settings(
        llm_provider="fake", llm_context_tokens=4096
    )
    app.dependency_overrides[get_provider] = lambda: provider

    # 4096-token context: reserve 1024, so at most 3072 * 3 = 9216 characters.
    response = TestClient(app).post("/v1/generate", json=_payload_with_chars(12000))

    assert response.status_code == 422
    error = response.json()["error"]
    assert error["code"] == "VALIDATION_FAILED"
    assert "AI_LLM_CONTEXT_TOKENS=4096" in error["message"]
    assert provider.calls == []


def test_repair_prompt_beyond_configured_context_is_skipped() -> None:
    bad = copy.deepcopy(VALID_DSL_CASE)
    bad["dsl_version"] = "bad"
    bad["description"] = "y" * 6000
    provider = _TokenReportingProvider([json.dumps({"test_cases": [bad]})], None)
    service = GeneratorService(
        provider=provider, settings=Settings(llm_provider="fake", llm_context_tokens=4096)
    )

    response = service.generate(GenerateRequest.model_validate(VALID_REQUEST_PAYLOAD))

    assert len(provider.calls) == 1
    assert response.test_cases[0].status == "INVALID"
    assert any("ngữ cảnh (context)" in e for e in response.test_cases[0].validation_errors)


def test_default_prompt_budget_fits_the_default_context() -> None:
    settings = Settings()
    compose = repo_file("docker", "docker-compose.yml").read_text(encoding="utf-8")
    ollama = compose[compose.index("\n  ollama:") :]
    match = re.search(r'OLLAMA_CONTEXT_LENGTH:\s*"?(\d+)"?', ollama)
    assert match is not None
    assert int(match.group(1)) == settings.llm_context_tokens
    # AI_MAX_PROMPT_CHARS must never be cut by the pre-call context check by default.
    estimate = -(-settings.max_prompt_chars // 3)
    assert estimate + min(4096, settings.llm_context_tokens // 4) <= settings.llm_context_tokens


# Finding: a new httpx.Client per request was never closed -------------------------------


def test_get_provider_closes_the_http_client_after_the_request() -> None:
    settings = Settings(llm_provider="openai_compatible", llm_base_url="http://llm.local/v1")
    dependency = get_provider(settings)
    provider = next(dependency)
    assert isinstance(provider, OpenAICompatibleProvider)
    assert not provider._client.is_closed

    with pytest.raises(StopIteration):
        next(dependency)

    assert provider._client.is_closed
