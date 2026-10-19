import copy
import json
from typing import Any

import httpx
import pytest
from fastapi.testclient import TestClient

from app.api.deps import get_provider
from app.config import Settings, get_settings
from app.contracts.llm import ChatMessage, LlmCompletion, LlmProviderError
from app.main import create_app
from app.providers.fake import FakeLlmProvider
from app.providers.openai_compatible import OpenAICompatibleProvider
from app.services.fingerprint import DslFingerprintService
from app.services.prompts import build_repair_user_prompt

VALID_DSL_CASE: dict[str, Any] = {
    "dsl_version": "1.0",
    "title": "Create item with valid payload returns 201",
    "endpoint": {"method": "POST", "path": "/items"},
    "technique": "HAPPY_PATH",
    "source": "LLM",
    "steps": [
        {
            "name": "create item",
            "request": {
                "method": "POST",
                "path": "/items",
                "headers": {"Content-Type": "application/json"},
                "body": {"name": "Pen", "price": 10},
            },
            "assertions": [
                {"type": "status", "equals": 201},
            ],
        }
    ],
}

VALID_REQUEST_PAYLOAD: dict[str, Any] = {
    "job_id": 1,
    "strategy": "LLM",
    "endpoints": [
        {
            "id": 1,
            "method": "POST",
            "path": "/items",
            "operation_id": "createItem",
            "summary": "Create an item",
            "description": "Creates a new item",
            "parameters": None,
            "request_body": {
                "content": {
                    "application/json": {
                        "schema": {
                            "type": "object",
                            "properties": {
                                "name": {"type": "string"},
                                "price": {"type": "number"},
                            },
                            "required": ["name"],
                        }
                    }
                }
            },
            "responses": {
                "201": {"description": "Created"},
                "422": {"description": "Validation failed"},
            },
            "security": None,
        }
    ],
    "business_rules": [
        {
            "code": "BR01",
            "description": "Item name must be unique",
            "endpoint_id": 1,
        }
    ],
    "seed_cases": [],
    "options": {
        "max_cases_per_endpoint": 5,
        "techniques": ["HAPPY_PATH", "INVALID_INPUT"],
        "model": None,
        "temperature": 0.2,
        "seed": 42,
        "max_repair_attempts": 2,
    },
}


def make_client_with_provider(provider: Any) -> TestClient:
    app = create_app()
    app.dependency_overrides[get_settings] = lambda: Settings(llm_provider="fake")
    app.dependency_overrides[get_provider] = lambda: provider
    return TestClient(app)


def test_generate_happy_path_with_fake_provider() -> None:
    llm_output = json.dumps({"test_cases": [VALID_DSL_CASE]})
    provider = FakeLlmProvider(responses=[llm_output])
    client = make_client_with_provider(provider)

    response = client.post("/v1/generate", json=VALID_REQUEST_PAYLOAD)

    assert response.status_code == 200
    data = response.json()

    # 1. Test cases validation
    assert len(data["test_cases"]) == 1
    tc = data["test_cases"][0]
    assert tc["status"] == "VALID"
    assert tc["validation_errors"] == []
    assert tc["repair_attempts"] == 0
    assert tc["dsl"]["title"] == "Create item with valid payload returns 201"

    # 2. LLM calls verification (all required fields present)
    assert len(data["llm_calls"]) == 1
    call = data["llm_calls"][0]
    assert call["purpose"] == "GENERATE"
    assert call["provider"] == "fake"
    assert call["model"] == "fake"
    assert len(call["prompt"]) == 2
    assert call["prompt"][0]["role"] == "system"
    assert call["prompt"][1]["role"] == "user"
    assert "### Target Endpoints" in call["prompt"][1]["content"]
    assert "BR01" in call["prompt"][1]["content"]
    assert call["response_raw"] == llm_output
    assert call["temperature"] == 0.2
    assert call["seed"] == 42
    assert call["prompt_tokens"] == 0
    assert call["completion_tokens"] == 0
    assert call["latency_ms"] >= 0
    assert call["error"] is None

    # 3. Generation stats verification
    assert data["stats"] == {
        "generated": 1,
        "valid": 1,
        "invalid": 0,
        "repaired": 0,
        "duplicates": 0,
    }


def test_generate_marks_invalid_dsl_cases() -> None:
    invalid_dsl = {
        "dsl_version": "1.0",
        "title": "Bad case missing steps and endpoint",
        "technique": "HAPPY_PATH",
        "source": "LLM",
    }
    llm_output = json.dumps({"test_cases": [invalid_dsl]})
    provider = FakeLlmProvider(responses=[llm_output])
    client = make_client_with_provider(provider)

    response = client.post("/v1/generate", json=VALID_REQUEST_PAYLOAD)

    assert response.status_code == 200
    data = response.json()
    assert len(data["test_cases"]) == 1
    tc = data["test_cases"][0]
    assert tc["status"] == "INVALID"
    assert len(tc["validation_errors"]) > 0
    assert data["stats"]["generated"] == 1
    assert data["stats"]["valid"] == 0
    assert data["stats"]["invalid"] == 1


def test_generate_handles_markdown_code_fences() -> None:
    llm_output = f"```json\n{json.dumps({'test_cases': [VALID_DSL_CASE]})}\n```"
    provider = FakeLlmProvider(responses=[llm_output])
    client = make_client_with_provider(provider)

    response = client.post("/v1/generate", json=VALID_REQUEST_PAYLOAD)

    assert response.status_code == 200
    data = response.json()
    assert len(data["test_cases"]) == 1
    assert data["test_cases"][0]["status"] == "VALID"
    assert data["stats"]["valid"] == 1


def test_generate_handles_malformed_json_response() -> None:
    llm_output = "Sorry, I am an AI and cannot generate test cases for this."
    provider = FakeLlmProvider(responses=[llm_output])
    client = make_client_with_provider(provider)

    response = client.post("/v1/generate", json=VALID_REQUEST_PAYLOAD)

    assert response.status_code == 200
    data = response.json()
    assert len(data["test_cases"]) == 1
    tc = data["test_cases"][0]
    assert tc["status"] == "INVALID"
    assert any("JSON" in err for err in tc["validation_errors"])
    assert data["stats"]["invalid"] == 1
    assert data["stats"]["valid"] == 0


def test_generate_handles_empty_test_cases_array() -> None:
    llm_output = json.dumps({"test_cases": []})
    provider = FakeLlmProvider(responses=[llm_output])
    client = make_client_with_provider(provider)

    response = client.post("/v1/generate", json=VALID_REQUEST_PAYLOAD)

    assert response.status_code == 200
    data = response.json()
    assert data["test_cases"] == []
    assert data["stats"]["generated"] == 0


class ErrorProvider:
    name = "failing_provider"

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
        raise LlmProviderError("Connection timeout to Ollama backend")


def test_generate_returns_502_on_provider_error() -> None:
    app = create_app()
    app.dependency_overrides[get_settings] = lambda: Settings(llm_provider="fake")
    app.dependency_overrides[get_provider] = lambda: ErrorProvider()
    client = TestClient(app)

    response = client.post(
        "/v1/generate",
        json=VALID_REQUEST_PAYLOAD,
        headers={"X-Request-Id": "trace-test-502"},
    )

    assert response.status_code == 502
    data = response.json()
    assert data["error"]["code"] == "LLM_PROVIDER_ERROR"
    assert "Connection timeout" in data["error"]["message"]
    assert data["trace_id"] == "trace-test-502"


def test_generate_returns_422_on_missing_required_request_fields() -> None:
    provider = FakeLlmProvider()
    client = make_client_with_provider(provider)

    response = client.post(
        "/v1/generate",
        json={"job_id": 1},
        headers={"X-Request-Id": "trace-test-422"},
    )

    assert response.status_code == 422
    data = response.json()
    assert data["error"]["code"] == "VALIDATION_FAILED"
    assert data["trace_id"] == "trace-test-422"


def test_generate_rejects_hybrid_strategy_without_seed_cases() -> None:
    provider = FakeLlmProvider()
    client = make_client_with_provider(provider)

    payload = dict(VALID_REQUEST_PAYLOAD)
    payload["strategy"] = "HYBRID"
    payload["seed_cases"] = []

    response = client.post("/v1/generate", json=payload)
    assert response.status_code == 422
    data = response.json()
    assert data["error"]["code"] == "VALIDATION_FAILED"
    assert any("seed_cases" in msg for msgs in data["error"]["details"].values() for msg in msgs)


def test_generate_rejects_unsupported_strategy() -> None:
    provider = FakeLlmProvider()
    client = make_client_with_provider(provider)

    payload = dict(VALID_REQUEST_PAYLOAD)
    payload["strategy"] = "UNSUPPORTED_STRATEGY"

    response = client.post("/v1/generate", json=payload)
    assert response.status_code == 422
    data = response.json()
    assert data["error"]["code"] == "VALIDATION_FAILED"


def test_generate_hybrid_strategy_success() -> None:
    hybrid_case = copy.deepcopy(VALID_DSL_CASE)
    hybrid_case["title"] = "Verify business rule BR01 uniqueness"
    hybrid_case["technique"] = "BUSINESS_RULE"
    hybrid_case["source"] = "HYBRID"

    llm_output = json.dumps({"test_cases": [hybrid_case]})
    provider = FakeLlmProvider(responses=[llm_output])
    client = make_client_with_provider(provider)

    payload = dict(VALID_REQUEST_PAYLOAD)
    payload["strategy"] = "HYBRID"
    payload["seed_cases"] = [VALID_DSL_CASE]

    response = client.post("/v1/generate", json=payload)
    assert response.status_code == 200
    data = response.json()

    assert len(data["test_cases"]) == 1
    tc = data["test_cases"][0]
    assert tc["status"] == "VALID"
    assert tc["dsl"]["source"] == "HYBRID"
    assert tc["dsl"]["technique"] == "BUSINESS_RULE"
    assert data["stats"]["valid"] == 1
    assert data["stats"]["generated"] == 1

    # Check LLM call prompts contain HYBRID instructions and seed cases
    assert len(data["llm_calls"]) == 1
    prompt_msgs = data["llm_calls"][0]["prompt"]
    sys_msg = next(m["content"] for m in prompt_msgs if m["role"] == "system")
    user_msg = next(m["content"] for m in prompt_msgs if m["role"] == "user")
    assert "HYBRID" in sys_msg
    assert "Existing Seed Test Cases (Rule-based)" in user_msg
    assert "Business Rules to Test" in user_msg
    assert "Hybrid Instructions:" in user_msg


def test_generate_hybrid_enforces_source_hybrid_even_if_llm_returns_llm() -> None:
    case_from_llm = copy.deepcopy(VALID_DSL_CASE)
    case_from_llm["title"] = "Complimentary auth check"
    case_from_llm["technique"] = "AUTH"
    case_from_llm["source"] = "LLM"  # LLM returned "LLM" instead of "HYBRID"

    llm_output = json.dumps({"test_cases": [case_from_llm]})
    provider = FakeLlmProvider(responses=[llm_output])
    client = make_client_with_provider(provider)

    payload = dict(VALID_REQUEST_PAYLOAD)
    payload["strategy"] = "HYBRID"
    payload["seed_cases"] = [VALID_DSL_CASE]

    response = client.post("/v1/generate", json=payload)
    assert response.status_code == 200
    data = response.json()

    assert len(data["test_cases"]) == 1
    tc = data["test_cases"][0]
    assert tc["status"] == "VALID"
    # Generator MUST enforce source == "HYBRID"
    assert tc["dsl"]["source"] == "HYBRID"


def test_generate_hybrid_deduplicates_against_seed_cases() -> None:
    # Seed case
    seed_case = copy.deepcopy(VALID_DSL_CASE)
    seed_case["source"] = "RULE"
    seed_case["technique"] = "BOUNDARY"

    # LLM produces a case with identical execution semantics as seed case
    duplicate_case = copy.deepcopy(VALID_DSL_CASE)
    duplicate_case["title"] = "Different title but same request and assertions"
    duplicate_case["source"] = "HYBRID"
    duplicate_case["technique"] = "BOUNDARY"

    llm_output = json.dumps({"test_cases": [duplicate_case]})
    provider = FakeLlmProvider(responses=[llm_output])
    client = make_client_with_provider(provider)

    payload = dict(VALID_REQUEST_PAYLOAD)
    payload["strategy"] = "HYBRID"
    # Seed case provided as dict with dsl
    payload["seed_cases"] = [{"dsl": seed_case}]

    response = client.post("/v1/generate", json=payload)
    assert response.status_code == 200
    data = response.json()

    assert len(data["test_cases"]) == 1
    tc = data["test_cases"][0]
    assert tc["status"] == "DUPLICATE"
    assert data["stats"]["duplicates"] == 1
    assert data["stats"]["valid"] == 0


def test_generate_hybrid_deduplicates_with_matching_wrapper_fingerprint() -> None:
    seed_case = copy.deepcopy(VALID_DSL_CASE)
    seed_case["source"] = "RULE"
    duplicate_case = copy.deepcopy(VALID_DSL_CASE)
    duplicate_case["title"] = "Same test with different title"
    duplicate_case["source"] = "HYBRID"
    fingerprint = DslFingerprintService().calculate(seed_case)
    provider = FakeLlmProvider(responses=[json.dumps({"test_cases": [duplicate_case]})])
    client = make_client_with_provider(provider)

    payload = dict(VALID_REQUEST_PAYLOAD)
    payload["strategy"] = "HYBRID"
    payload["seed_cases"] = [
        {"dsl": seed_case, "id": 5, "status": "VALID", "fingerprint": fingerprint}
    ]

    response = client.post("/v1/generate", json=payload)

    assert response.status_code == 200
    data = response.json()
    assert data["test_cases"][0]["status"] == "DUPLICATE"
    assert data["stats"]["duplicates"] == 1
    assert data["stats"]["valid"] == 0


def test_generate_hybrid_ignores_stale_wrapper_fingerprint() -> None:
    seed_case = copy.deepcopy(VALID_DSL_CASE)
    seed_case["source"] = "RULE"
    generated_case = copy.deepcopy(VALID_DSL_CASE)
    generated_case["source"] = "HYBRID"
    generated_case["steps"][0]["request"]["body"]["price"] = 20
    stale_fingerprint = DslFingerprintService().calculate(generated_case)
    assert stale_fingerprint != DslFingerprintService().calculate(seed_case)
    provider = FakeLlmProvider(responses=[json.dumps({"test_cases": [generated_case]})])
    client = make_client_with_provider(provider)

    payload = dict(VALID_REQUEST_PAYLOAD)
    payload["strategy"] = "HYBRID"
    payload["seed_cases"] = [
        {"dsl": seed_case, "id": 5, "status": "VALID", "fingerprint": stale_fingerprint}
    ]

    response = client.post("/v1/generate", json=payload)

    assert response.status_code == 200
    data = response.json()
    assert data["test_cases"][0]["status"] == "VALID"
    assert data["stats"]["generated"] == 1
    assert data["stats"]["valid"] == 1
    assert data["stats"]["invalid"] == 0
    assert data["stats"]["duplicates"] == 0


def test_generate_hybrid_repair_preserves_source_hybrid() -> None:
    # Invalid case missing required endpoint
    invalid_case = copy.deepcopy(VALID_DSL_CASE)
    del invalid_case["endpoint"]
    invalid_case["technique"] = "BUSINESS_RULE"
    invalid_case["steps"][0]["request"]["body"] = {"name": "Repaired Item", "price": 99}
    invalid_case["source"] = "HYBRID"

    # Repaired case returned by provider on second attempt
    repaired_case = copy.deepcopy(invalid_case)
    repaired_case["endpoint"] = {"method": "POST", "path": "/items"}
    repaired_case["source"] = "LLM"  # LLM erroneously answered source=LLM in repair

    llm_initial = json.dumps({"test_cases": [invalid_case]})
    llm_repair = json.dumps(repaired_case)
    provider = FakeLlmProvider(responses=[llm_initial, llm_repair])
    client = make_client_with_provider(provider)

    payload = dict(VALID_REQUEST_PAYLOAD)
    payload["strategy"] = "HYBRID"
    payload["seed_cases"] = [VALID_DSL_CASE]

    response = client.post("/v1/generate", json=payload)
    assert response.status_code == 200
    data = response.json()

    assert len(data["test_cases"]) == 1
    tc = data["test_cases"][0]
    assert tc["status"] == "VALID"
    assert tc["repair_attempts"] == 1
    assert tc["dsl"]["source"] == "HYBRID"
    assert tc["dsl"]["technique"] == "BUSINESS_RULE"
    assert data["stats"]["repaired"] == 1


def test_generate_hybrid_with_raw_dsl_seed_case() -> None:
    # Seed case passed directly as raw DSL dict
    raw_seed_case = copy.deepcopy(VALID_DSL_CASE)
    raw_seed_case["source"] = "RULE"

    unique_case = copy.deepcopy(VALID_DSL_CASE)
    unique_case["title"] = "Multi-step sequence"
    unique_case["technique"] = "SEQUENCE"
    unique_case["source"] = "HYBRID"
    unique_case["steps"].append(
        {
            "name": "create second item",
            "request": {
                "method": "POST",
                "path": "/items",
                "headers": {"Content-Type": "application/json"},
                "body": {"name": "Notebook", "price": 25},
            },
            "assertions": [{"type": "status", "equals": 201}],
        }
    )

    llm_output = json.dumps({"test_cases": [unique_case]})
    provider = FakeLlmProvider(responses=[llm_output])
    client = make_client_with_provider(provider)

    payload = dict(VALID_REQUEST_PAYLOAD)
    payload["strategy"] = "HYBRID"
    payload["seed_cases"] = [raw_seed_case]

    response = client.post("/v1/generate", json=payload)
    assert response.status_code == 200
    data = response.json()

    assert len(data["test_cases"]) == 1
    tc = data["test_cases"][0]
    assert tc["status"] == "VALID"
    assert tc["dsl"]["source"] == "HYBRID"
    assert tc["dsl"]["technique"] == "SEQUENCE"


def test_generate_marks_hallucinated_endpoint_as_invalid() -> None:
    hallucinated_case = dict(VALID_DSL_CASE)
    hallucinated_case["endpoint"] = {"method": "GET", "path": "/hallucinated"}
    hallucinated_case["steps"] = [
        {
            "name": "call non existent endpoint",
            "request": {"method": "GET", "path": "/hallucinated"},
            "assertions": [{"type": "status", "equals": 200}],
        }
    ]
    llm_output = json.dumps({"test_cases": [hallucinated_case]})
    provider = FakeLlmProvider(responses=[llm_output])
    client = make_client_with_provider(provider)

    response = client.post("/v1/generate", json=VALID_REQUEST_PAYLOAD)

    assert response.status_code == 200
    data = response.json()
    assert len(data["test_cases"]) == 1
    tc = data["test_cases"][0]
    assert tc["status"] == "INVALID"
    assert any(
        "endpoint" in err.lower() or "/hallucinated" in err for err in tc["validation_errors"]
    )
    assert data["stats"]["valid"] == 0
    assert data["stats"]["invalid"] == 1


def test_generate_handles_malformed_structure_empty_object() -> None:
    llm_output = "{}"
    provider = FakeLlmProvider(responses=[llm_output])
    client = make_client_with_provider(provider)

    response = client.post("/v1/generate", json=VALID_REQUEST_PAYLOAD)

    assert response.status_code == 200
    data = response.json()
    assert len(data["test_cases"]) == 1
    tc = data["test_cases"][0]
    assert tc["status"] == "INVALID"
    assert any("test_cases" in err for err in tc["validation_errors"])
    assert data["stats"]["invalid"] == 1
    assert data["stats"]["valid"] == 0


def test_generate_handles_malformed_structure_non_list_test_cases() -> None:
    llm_output = json.dumps({"test_cases": "invalid_not_an_array"})
    provider = FakeLlmProvider(responses=[llm_output])
    client = make_client_with_provider(provider)

    response = client.post("/v1/generate", json=VALID_REQUEST_PAYLOAD)

    assert response.status_code == 200
    data = response.json()
    assert len(data["test_cases"]) == 1
    tc = data["test_cases"][0]
    assert tc["status"] == "INVALID"
    assert any("mảng" in err for err in tc["validation_errors"])
    assert data["stats"]["invalid"] == 1


def test_generate_enforces_max_cases_per_endpoint() -> None:
    case1 = dict(VALID_DSL_CASE)
    case1["title"] = "Case 1"
    case2 = dict(VALID_DSL_CASE)
    case2["title"] = "Case 2"
    case3 = dict(VALID_DSL_CASE)
    case3["title"] = "Case 3"

    llm_output = json.dumps({"test_cases": [case1, case2, case3]})
    provider = FakeLlmProvider(responses=[llm_output])
    client = make_client_with_provider(provider)

    payload = dict(VALID_REQUEST_PAYLOAD)
    payload["options"] = dict(payload["options"])
    payload["options"]["max_cases_per_endpoint"] = 1

    response = client.post("/v1/generate", json=payload)

    assert response.status_code == 200
    data = response.json()
    assert len(data["test_cases"]) == 1
    assert data["test_cases"][0]["dsl"]["title"] == "Case 1"
    assert data["stats"]["generated"] == 1
    assert data["stats"]["valid"] == 1


class UnhandledCrashProvider:
    name = "crash_provider"

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
        raise RuntimeError("Unexpected server crash")


def test_generate_returns_500_on_unhandled_internal_error() -> None:
    app = create_app()
    app.dependency_overrides[get_settings] = lambda: Settings(llm_provider="fake")
    app.dependency_overrides[get_provider] = lambda: UnhandledCrashProvider()
    client = TestClient(app, raise_server_exceptions=False)

    response = client.post(
        "/v1/generate",
        json=VALID_REQUEST_PAYLOAD,
        headers={"X-Request-Id": "trace-test-500"},
    )

    assert response.status_code == 500
    data = response.json()
    assert data["error"]["code"] == "INTERNAL_ERROR"
    assert data["error"]["message"] == "An internal error occurred."
    assert data["trace_id"] == "trace-test-500"


def test_generate_repairs_invalid_case_on_first_attempt() -> None:
    invalid_case = {
        "dsl_version": "1.0",
        "title": "Invalid case missing endpoint and steps",
        "technique": "HAPPY_PATH",
        "source": "LLM",
    }
    initial_output = json.dumps({"test_cases": [invalid_case]})
    repaired_output = json.dumps(VALID_DSL_CASE)

    provider = FakeLlmProvider(responses=[initial_output, repaired_output])
    client = make_client_with_provider(provider)

    response = client.post("/v1/generate", json=VALID_REQUEST_PAYLOAD)

    assert response.status_code == 200
    data = response.json()

    assert len(data["test_cases"]) == 1
    tc = data["test_cases"][0]
    assert tc["status"] == "VALID"
    assert tc["repair_attempts"] == 1
    assert tc["validation_errors"] == []
    assert tc["dsl"]["title"] == VALID_DSL_CASE["title"]

    assert data["stats"]["generated"] == 1
    assert data["stats"]["valid"] == 1
    assert data["stats"]["repaired"] == 1
    assert data["stats"]["invalid"] == 0

    assert len(data["llm_calls"]) == 2
    assert data["llm_calls"][0]["purpose"] == "GENERATE"
    assert data["llm_calls"][1]["purpose"] == "REPAIR"
    repair_call = data["llm_calls"][1]
    assert any("Validation Errors" in m["content"] for m in repair_call["prompt"])
    assert repair_call["response_raw"] == repaired_output


def test_generate_repairs_invalid_case_on_second_attempt() -> None:
    invalid_case_1 = {
        "dsl_version": "1.0",
        "title": "Invalid case 1",
        "technique": "HAPPY_PATH",
        "source": "LLM",
    }
    invalid_case_2 = {
        "dsl_version": "1.0",
        "title": "Still invalid missing steps",
        "endpoint": {"method": "POST", "path": "/items"},
        "technique": "HAPPY_PATH",
        "source": "LLM",
    }
    initial_output = json.dumps({"test_cases": [invalid_case_1]})
    repair_1_output = json.dumps(invalid_case_2)
    repair_2_output = json.dumps(VALID_DSL_CASE)

    provider = FakeLlmProvider(responses=[initial_output, repair_1_output, repair_2_output])
    client = make_client_with_provider(provider)

    response = client.post("/v1/generate", json=VALID_REQUEST_PAYLOAD)

    assert response.status_code == 200
    data = response.json()

    assert len(data["test_cases"]) == 1
    tc = data["test_cases"][0]
    assert tc["status"] == "VALID"
    assert tc["repair_attempts"] == 2
    assert tc["validation_errors"] == []

    assert data["stats"]["valid"] == 1
    assert data["stats"]["repaired"] == 1
    assert data["stats"]["invalid"] == 0

    assert len(data["llm_calls"]) == 3
    assert data["llm_calls"][0]["purpose"] == "GENERATE"
    assert data["llm_calls"][1]["purpose"] == "REPAIR"
    assert data["llm_calls"][2]["purpose"] == "REPAIR"


def test_generate_exhausts_repair_attempts_and_marks_invalid() -> None:
    invalid_case = {
        "dsl_version": "1.0",
        "title": "Permanently broken case",
        "technique": "HAPPY_PATH",
        "source": "LLM",
    }
    initial_output = json.dumps({"test_cases": [invalid_case]})
    repair_1_output = json.dumps({"test_cases": [invalid_case]})
    repair_2_output = json.dumps({"test_cases": [invalid_case]})

    provider = FakeLlmProvider(responses=[initial_output, repair_1_output, repair_2_output])
    client = make_client_with_provider(provider)

    response = client.post("/v1/generate", json=VALID_REQUEST_PAYLOAD)

    assert response.status_code == 200
    data = response.json()

    assert len(data["test_cases"]) == 1
    tc = data["test_cases"][0]
    assert tc["status"] == "INVALID"
    assert tc["repair_attempts"] == 2
    assert len(tc["validation_errors"]) > 0

    assert data["stats"]["valid"] == 0
    assert data["stats"]["repaired"] == 0
    assert data["stats"]["invalid"] == 1

    assert len(data["llm_calls"]) == 3
    assert data["llm_calls"][0]["purpose"] == "GENERATE"
    assert data["llm_calls"][1]["purpose"] == "REPAIR"
    assert data["llm_calls"][2]["purpose"] == "REPAIR"


def test_generate_respects_max_repair_attempts_zero() -> None:
    invalid_case = {
        "dsl_version": "1.0",
        "title": "Invalid case with repair disabled",
        "technique": "HAPPY_PATH",
        "source": "LLM",
    }
    initial_output = json.dumps({"test_cases": [invalid_case]})

    provider = FakeLlmProvider(responses=[initial_output])
    client = make_client_with_provider(provider)

    payload = dict(VALID_REQUEST_PAYLOAD)
    payload["options"] = dict(payload["options"])
    payload["options"]["max_repair_attempts"] = 0

    response = client.post("/v1/generate", json=payload)

    assert response.status_code == 200
    data = response.json()

    assert len(data["test_cases"]) == 1
    tc = data["test_cases"][0]
    assert tc["status"] == "INVALID"
    assert tc["repair_attempts"] == 0
    assert len(data["llm_calls"]) == 1
    assert data["llm_calls"][0]["purpose"] == "GENERATE"


def test_generate_mixed_valid_and_repaired_and_unrepaired_cases() -> None:
    case_valid = dict(VALID_DSL_CASE)
    case_valid["title"] = "Initially valid case"

    case_needs_repair = {
        "dsl_version": "1.0",
        "title": "Needs repair",
        "technique": "HAPPY_PATH",
        "source": "LLM",
    }

    case_never_repaired = {
        "dsl_version": "1.0",
        "title": "Hopeless case",
        "technique": "HAPPY_PATH",
        "source": "LLM",
    }

    initial_output = json.dumps(
        {"test_cases": [case_valid, case_needs_repair, case_never_repaired]}
    )

    # Reparation sequence:
    # 1. case_needs_repair attempt 1 -> fixed!
    case_repaired = copy.deepcopy(VALID_DSL_CASE)
    case_repaired["steps"][0]["request"]["body"]["name"] = "Eraser"
    case_repaired["title"] = "Repaired case"
    repair_output_1 = json.dumps(case_repaired)

    # 2. case_never_repaired attempt 1 -> still invalid
    repair_output_2 = json.dumps(case_never_repaired)
    # 3. case_never_repaired attempt 2 -> still invalid
    repair_output_3 = json.dumps(case_never_repaired)

    provider = FakeLlmProvider(
        responses=[initial_output, repair_output_1, repair_output_2, repair_output_3]
    )
    client = make_client_with_provider(provider)

    response = client.post("/v1/generate", json=VALID_REQUEST_PAYLOAD)

    assert response.status_code == 200
    data = response.json()

    assert len(data["test_cases"]) == 3
    assert data["test_cases"][0]["status"] == "VALID"
    assert data["test_cases"][0]["repair_attempts"] == 0

    assert data["test_cases"][1]["status"] == "VALID"
    assert data["test_cases"][1]["repair_attempts"] == 1

    assert data["test_cases"][2]["status"] == "INVALID"
    assert data["test_cases"][2]["repair_attempts"] == 2

    assert data["stats"]["generated"] == 3
    assert data["stats"]["valid"] == 2
    assert data["stats"]["repaired"] == 1
    assert data["stats"]["invalid"] == 1

    # 1 GENERATE call + 1 REPAIR for case 2 + 2 REPAIRs for case 3 = 4 total calls
    assert len(data["llm_calls"]) == 4
    assert [c["purpose"] for c in data["llm_calls"]] == ["GENERATE", "REPAIR", "REPAIR", "REPAIR"]


def test_repair_calls_carry_the_index_of_their_test_case() -> None:
    """QA S13-03: REPAIR logs had no link to a case, so M10 listed every repair of the job."""
    case_needs_repair = {"dsl_version": "1.0", "title": "Needs repair", "technique": "HAPPY_PATH"}
    case_hopeless = {"dsl_version": "1.0", "title": "Hopeless", "technique": "HAPPY_PATH"}
    case_repaired = copy.deepcopy(VALID_DSL_CASE)
    case_repaired["steps"][0]["request"]["body"]["name"] = "Eraser"
    provider = FakeLlmProvider(
        responses=[
            json.dumps({"test_cases": [VALID_DSL_CASE, case_needs_repair, case_hopeless]}),
            json.dumps(case_repaired),
            json.dumps(case_hopeless),
            json.dumps(case_hopeless),
        ]
    )

    data = (
        make_client_with_provider(provider).post("/v1/generate", json=VALID_REQUEST_PAYLOAD).json()
    )

    assert [c["purpose"] for c in data["llm_calls"]] == ["GENERATE", "REPAIR", "REPAIR", "REPAIR"]
    assert [c["case_index"] for c in data["llm_calls"]] == [None, 1, 2, 2]
    for call in data["llm_calls"][1:]:
        assert data["test_cases"][call["case_index"]]["repair_attempts"] > 0
    assert data["test_cases"][0]["repair_attempts"] == 0


def test_repair_of_a_dropped_case_has_no_case_index() -> None:
    payload = copy.deepcopy(VALID_REQUEST_PAYLOAD)
    payload["options"]["max_cases_per_endpoint"] = 1
    bad = {"dsl_version": "bad", "title": "Missing endpoint"}
    provider = FakeLlmProvider(
        [json.dumps({"test_cases": [VALID_DSL_CASE, bad]}), json.dumps(VALID_DSL_CASE)]
    )

    data = make_client_with_provider(provider).post("/v1/generate", json=payload).json()

    assert len(data["test_cases"]) == 1
    assert [(c["purpose"], c["case_index"]) for c in data["llm_calls"]] == [
        ("GENERATE", None),
        ("REPAIR", None),
    ]


@pytest.mark.parametrize("system_limit,request_limit,expected", [(0, 2, 0), (1, 2, 1), (2, 1, 1)])
def test_repair_uses_system_cap_and_lower_request_limit(
    system_limit: int,
    request_limit: int,
    expected: int,
) -> None:
    bad = copy.deepcopy(VALID_DSL_CASE)
    bad["dsl_version"] = "bad"
    provider = FakeLlmProvider(
        [json.dumps({"test_cases": [bad]}), json.dumps(bad), json.dumps(bad)]
    )
    app = create_app()
    app.dependency_overrides[get_settings] = lambda: Settings(max_repair_attempts=system_limit)
    app.dependency_overrides[get_provider] = lambda: provider
    payload = copy.deepcopy(VALID_REQUEST_PAYLOAD)
    payload["options"]["max_repair_attempts"] = request_limit
    data = TestClient(app).post("/v1/generate", json=payload).json()
    assert len(provider.calls) == expected + 1
    assert data["test_cases"][0]["repair_attempts"] == expected
    assert data["test_cases"][0]["status"] == "INVALID"


class RepairTimeoutProvider(FakeLlmProvider):
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
        if self.calls:
            self.calls.append(messages)
            raise LlmProviderError("repair timeout")
        return super().complete(
            messages, temperature=temperature, seed=seed, json_mode=json_mode, model=model
        )


def test_repair_timeout_preserves_valid_cases_and_records_failure() -> None:
    bad = copy.deepcopy(VALID_DSL_CASE)
    bad["dsl_version"] = "bad"
    provider = RepairTimeoutProvider([json.dumps({"test_cases": [VALID_DSL_CASE, bad]})])
    response = make_client_with_provider(provider).post("/v1/generate", json=VALID_REQUEST_PAYLOAD)
    assert response.status_code == 200
    data = response.json()
    assert [tc["status"] for tc in data["test_cases"]] == ["VALID", "INVALID"]
    assert data["test_cases"][1]["repair_attempts"] == 1
    assert "repair timeout" in data["test_cases"][1]["validation_errors"][-1]
    assert [call["purpose"] for call in data["llm_calls"]] == ["GENERATE", "REPAIR"]
    failed = data["llm_calls"][1]
    assert failed["error"] == "repair timeout"
    assert failed["response_raw"] == ""
    assert failed["prompt_tokens"] is None
    assert failed["completion_tokens"] is None
    assert data["stats"]["valid"] == 1
    assert data["stats"]["invalid"] == 1


@pytest.mark.parametrize(
    "initial", ["{broken", "{}", '{"test_cases":"bad"}', '{"test_cases":[null]}']
)
def test_malformed_initial_output_enters_repair(initial: str) -> None:
    provider = FakeLlmProvider([initial, json.dumps(VALID_DSL_CASE)])
    response = make_client_with_provider(provider).post("/v1/generate", json=VALID_REQUEST_PAYLOAD)
    assert response.status_code == 200
    data = response.json()
    assert data["test_cases"][0]["status"] == "VALID"
    assert data["test_cases"][0]["repair_attempts"] == 1
    assert data["stats"]["repaired"] == 1
    assert len(data["llm_calls"]) == 2
    assert initial == data["llm_calls"][0]["response_raw"]


def test_repair_cannot_change_a_selected_target_endpoint() -> None:
    payload = copy.deepcopy(VALID_REQUEST_PAYLOAD)
    payload["options"]["max_cases_per_endpoint"] = 1
    second = copy.deepcopy(payload["endpoints"][0])
    second["id"] = 2
    second["path"] = "/other"
    payload["endpoints"].append(second)
    other = copy.deepcopy(VALID_DSL_CASE)
    other["dsl_version"] = "bad"
    other["endpoint"]["path"] = "/other"
    other["steps"][0]["request"]["path"] = "/other"
    provider = FakeLlmProvider(
        [
            json.dumps({"test_cases": [VALID_DSL_CASE, other]}),
            json.dumps(VALID_DSL_CASE),
            json.dumps(VALID_DSL_CASE),
        ]
    )
    data = make_client_with_provider(provider).post("/v1/generate", json=payload).json()
    assert [tc["status"] for tc in data["test_cases"]] == ["VALID", "INVALID"]
    assert data["test_cases"][1]["dsl"]["endpoint"]["path"] == "/other"
    assert any(
        "giữ nguyên endpoint đích" in error for error in data["test_cases"][1]["validation_errors"]
    )


def test_repaired_case_without_initial_endpoint_obeys_final_endpoint_cap() -> None:
    payload = copy.deepcopy(VALID_REQUEST_PAYLOAD)
    payload["options"]["max_cases_per_endpoint"] = 1
    bad = {"dsl_version": "bad", "title": "Missing endpoint"}
    provider = FakeLlmProvider(
        [
            json.dumps({"test_cases": [VALID_DSL_CASE, bad]}),
            json.dumps(VALID_DSL_CASE),
        ]
    )
    data = make_client_with_provider(provider).post("/v1/generate", json=payload).json()
    assert len(data["test_cases"]) == 1
    assert data["stats"]["valid"] == 1
    assert len(data["llm_calls"]) == 2


def test_invalid_repair_json_is_reported_in_next_prompt() -> None:
    bad = copy.deepcopy(VALID_DSL_CASE)
    bad["dsl_version"] = "bad"
    provider = FakeLlmProvider(
        [
            json.dumps({"test_cases": [bad]}),
            "{broken",
            json.dumps(VALID_DSL_CASE),
        ]
    )
    data = (
        make_client_with_provider(provider).post("/v1/generate", json=VALID_REQUEST_PAYLOAD).json()
    )
    assert data["test_cases"][0]["status"] == "VALID"
    assert data["test_cases"][0]["repair_attempts"] == 2
    assert "Không đọc được JSON trong câu trả lời sửa lỗi" in provider.calls[2][1].content


def test_repair_metadata_negative_tokens_with_openai_provider_preserves_valid_cases() -> None:
    bad = copy.deepcopy(VALID_DSL_CASE)
    bad["dsl_version"] = "bad"
    calls: list[httpx.Request] = []

    def handler(request: httpx.Request) -> httpx.Response:
        calls.append(request)
        if len(calls) == 1:
            return httpx.Response(
                200,
                json={
                    "model": "qwen",
                    "choices": [
                        {"message": {"content": json.dumps({"test_cases": [VALID_DSL_CASE, bad]})}}
                    ],
                    "usage": {"prompt_tokens": 100, "completion_tokens": 50},
                },
            )
        return httpx.Response(
            200,
            json={
                "model": "qwen",
                "choices": [{"message": {"content": json.dumps(VALID_DSL_CASE)}}],
                "usage": {"prompt_tokens": -1, "completion_tokens": 20},
            },
        )

    provider = OpenAICompatibleProvider(
        base_url="http://llm.local/v1",
        model="qwen",
        transport=httpx.MockTransport(handler),
    )
    client = make_client_with_provider(provider)
    response = client.post("/v1/generate", json=VALID_REQUEST_PAYLOAD)

    assert response.status_code == 200
    data = response.json()
    assert len(data["test_cases"]) == 2
    assert data["test_cases"][0]["status"] == "VALID"
    assert data["test_cases"][1]["status"] == "INVALID"
    assert any("không dùng được" in err for err in data["test_cases"][1]["validation_errors"])
    assert any("prompt_tokens" in (call["error"] or "") for call in data["llm_calls"])
    assert data["stats"]["valid"] == 1
    assert data["stats"]["invalid"] == 1
    assert data["stats"]["repaired"] == 0
    assert len(data["llm_calls"]) == 2
    assert data["llm_calls"][0]["purpose"] == "GENERATE"
    assert data["llm_calls"][1]["purpose"] == "REPAIR"
    assert data["llm_calls"][1]["error"] is not None
    assert "prompt_tokens" in data["llm_calls"][1]["error"]
    assert data["llm_calls"][1]["prompt_tokens"] is None


def test_repair_metadata_empty_model_preserves_valid_cases() -> None:
    bad = copy.deepcopy(VALID_DSL_CASE)
    bad["dsl_version"] = "bad"
    calls: list[httpx.Request] = []

    def handler(request: httpx.Request) -> httpx.Response:
        calls.append(request)
        if len(calls) == 1:
            return httpx.Response(
                200,
                json={
                    "model": "qwen",
                    "choices": [
                        {"message": {"content": json.dumps({"test_cases": [VALID_DSL_CASE, bad]})}}
                    ],
                    "usage": {"prompt_tokens": 100, "completion_tokens": 50},
                },
            )
        return httpx.Response(
            200,
            json={
                "model": "",
                "choices": [{"message": {"content": json.dumps(VALID_DSL_CASE)}}],
                "usage": {"prompt_tokens": 10, "completion_tokens": 20},
            },
        )

    provider = OpenAICompatibleProvider(
        base_url="http://llm.local/v1",
        model="qwen",
        transport=httpx.MockTransport(handler),
    )
    client = make_client_with_provider(provider)
    response = client.post("/v1/generate", json=VALID_REQUEST_PAYLOAD)

    assert response.status_code == 200
    data = response.json()
    assert len(data["test_cases"]) == 2
    assert data["test_cases"][0]["status"] == "VALID"
    assert data["test_cases"][1]["status"] == "INVALID"
    assert any("không dùng được" in err for err in data["test_cases"][1]["validation_errors"])
    assert any("empty model name" in (call["error"] or "") for call in data["llm_calls"])
    assert data["stats"]["valid"] == 1
    assert data["stats"]["invalid"] == 1
    assert len(data["llm_calls"]) == 2
    assert data["llm_calls"][1]["error"] is not None
    assert data["llm_calls"][1]["model"] != ""


@pytest.mark.parametrize("bad_val", ["NaN", "Infinity", "-Infinity", "1e400", "-1e400"])
def test_repair_rejects_non_finite_number_and_marks_invalid_when_exhausted(bad_val: str) -> None:
    bad = copy.deepcopy(VALID_DSL_CASE)
    bad["dsl_version"] = "bad"
    repair_raw = json.dumps(VALID_DSL_CASE).replace('"price": 10', f'"price": {bad_val}')
    provider = FakeLlmProvider(
        [
            json.dumps({"test_cases": [bad]}),
            repair_raw,
            repair_raw,
        ]
    )
    client = make_client_with_provider(provider)
    response = client.post("/v1/generate", json=VALID_REQUEST_PAYLOAD)

    assert response.status_code == 200
    data = response.json()
    assert len(data["test_cases"]) == 1
    case = data["test_cases"][0]
    assert case["status"] == "INVALID"
    assert data["stats"]["valid"] == 0
    assert data["stats"]["repaired"] == 0
    assert data["stats"]["invalid"] == 1
    assert any(
        "Không đọc được JSON trong câu trả lời sửa lỗi" in err for err in case["validation_errors"]
    )


@pytest.mark.parametrize("bad_val", ["NaN", "Infinity", "1e400"])
def test_generate_rejects_non_finite_in_initial_response_and_repairs(bad_val: str) -> None:
    initial_raw = json.dumps({"test_cases": [VALID_DSL_CASE]}).replace(
        '"price": 10', f'"price": {bad_val}'
    )
    provider = FakeLlmProvider(
        [
            initial_raw,
            json.dumps(VALID_DSL_CASE),
        ]
    )
    client = make_client_with_provider(provider)
    response = client.post("/v1/generate", json=VALID_REQUEST_PAYLOAD)

    assert response.status_code == 200
    data = response.json()
    assert len(data["test_cases"]) == 1
    case = data["test_cases"][0]
    assert case["status"] == "VALID"
    assert case["repair_attempts"] == 1
    assert data["stats"]["valid"] == 1
    assert data["stats"]["repaired"] == 1
    assert case["dsl"]["steps"][0]["request"]["body"]["price"] == 10


def test_repair_retries_after_non_finite_repair_output() -> None:
    bad = copy.deepcopy(VALID_DSL_CASE)
    bad["dsl_version"] = "bad"
    repair_with_nan = json.dumps(VALID_DSL_CASE).replace('"price": 10', '"price": NaN')
    provider = FakeLlmProvider(
        [
            json.dumps({"test_cases": [bad]}),
            repair_with_nan,
            json.dumps(VALID_DSL_CASE),
        ]
    )
    client = make_client_with_provider(provider)
    response = client.post("/v1/generate", json=VALID_REQUEST_PAYLOAD)

    assert response.status_code == 200
    data = response.json()
    assert len(data["test_cases"]) == 1
    case = data["test_cases"][0]
    assert case["status"] == "VALID"
    assert case["repair_attempts"] == 2
    assert data["stats"]["repaired"] == 1
    assert case["dsl"]["steps"][0]["request"]["body"]["price"] == 10


@pytest.mark.parametrize("bad_val", ["NaN", "Infinity", "-Infinity", "1e400", "-1e400"])
def test_generate_repairs_non_finite_json_string_case_and_preserves_valid_case(
    bad_val: str,
) -> None:
    repaired_case = copy.deepcopy(VALID_DSL_CASE)
    repaired_case["steps"][0]["request"]["body"]["name"] = "Eraser"
    bad_string = json.dumps(VALID_DSL_CASE).replace('"price": 10', f'"price": {bad_val}')
    initial_raw = json.dumps({"test_cases": [VALID_DSL_CASE, bad_string]})
    provider = FakeLlmProvider([initial_raw, json.dumps(repaired_case)])

    response = make_client_with_provider(provider).post("/v1/generate", json=VALID_REQUEST_PAYLOAD)

    assert response.status_code == 200
    data = response.json()
    first, repaired = data["test_cases"]
    assert first["dsl"] == VALID_DSL_CASE
    assert first["status"] == "VALID"
    assert first["repair_attempts"] == 0
    assert repaired["status"] == "VALID"
    assert repaired["repair_attempts"] == 1
    assert repaired["dsl"]["steps"][0]["request"]["body"]["price"] == 10
    assert data["stats"]["valid"] == 2
    assert data["stats"]["repaired"] == 1
    assert [call["purpose"] for call in data["llm_calls"]] == ["GENERATE", "REPAIR"]
    assert data["llm_calls"][0]["response_raw"] == initial_raw
    assert "Không đọc được JSON của test case" in provider.calls[1][1].content
    # The raw string is embedded as one JSON string literal (S10-01 marker hardening).
    assert json.dumps(bad_string, ensure_ascii=False) in provider.calls[1][1].content


@pytest.mark.parametrize("bad_val", ["NaN", "Infinity", "-Infinity", "1e400", "-1e400"])
@pytest.mark.parametrize("max_attempts", [0, 2])
def test_generate_rejects_non_finite_json_string_when_repair_cannot_succeed(
    bad_val: str, max_attempts: int
) -> None:
    bad_string = json.dumps(VALID_DSL_CASE).replace('"price": 10', f'"price": {bad_val}')
    initial_raw = json.dumps({"test_cases": [VALID_DSL_CASE, bad_string]})
    provider = FakeLlmProvider([initial_raw, *([bad_string] * max_attempts)])
    payload = copy.deepcopy(VALID_REQUEST_PAYLOAD)
    payload["options"]["max_repair_attempts"] = max_attempts

    response = make_client_with_provider(provider).post("/v1/generate", json=payload)

    assert response.status_code == 200
    data = response.json()
    first, invalid = data["test_cases"]
    assert first["status"] == "VALID"
    assert first["dsl"] == VALID_DSL_CASE
    assert invalid["status"] == "INVALID"
    assert invalid["dsl"] == {}
    assert invalid["repair_attempts"] == max_attempts
    assert any(
        "Không đọc được JSON của test case" in error for error in invalid["validation_errors"]
    )
    assert data["stats"]["valid"] == 1
    assert data["stats"]["invalid"] == 1
    assert data["stats"]["repaired"] == 0
    assert len(provider.calls) == 1 + max_attempts
    assert len(data["llm_calls"]) == 1 + max_attempts


def test_generate_handles_json_string_test_case_and_returns_normalized_dsl_object() -> None:
    llm_output = json.dumps({"test_cases": [json.dumps(VALID_DSL_CASE)]})
    provider = FakeLlmProvider(responses=[llm_output])
    client = make_client_with_provider(provider)
    response = client.post("/v1/generate", json=VALID_REQUEST_PAYLOAD)

    assert response.status_code == 200
    data = response.json()
    assert len(data["test_cases"]) == 1
    case = data["test_cases"][0]
    assert case["status"] == "VALID"
    assert isinstance(case["dsl"], dict)
    assert case["dsl"]["title"] == VALID_DSL_CASE["title"]
    assert case["dsl"]["dsl_version"] == "1.0"
    assert case["dsl"] != {}
    assert case["repair_attempts"] == 0
    assert data["stats"]["valid"] == 1


def test_generate_non_object_test_case_triggers_repair_and_never_returns_empty_valid_dsl() -> None:
    llm_output = json.dumps({"test_cases": ["invalid non-json string"]})
    repair_fail_output = "still not a valid json object"
    provider = FakeLlmProvider(responses=[llm_output, repair_fail_output, repair_fail_output])
    client = make_client_with_provider(provider)
    response = client.post("/v1/generate", json=VALID_REQUEST_PAYLOAD)

    assert response.status_code == 200
    data = response.json()
    assert len(data["test_cases"]) == 1
    case = data["test_cases"][0]
    assert case["status"] == "INVALID"
    assert case["dsl"] == {}
    assert case["validation_errors"]
    assert data["stats"]["invalid"] == 1
    assert data["stats"]["valid"] == 0


def test_build_repair_user_prompt_includes_all_referenced_endpoints_across_steps() -> None:
    multi_step_dsl = {
        "dsl_version": "1.0",
        "title": "Multi-step order workflow",
        "endpoint": {"method": "POST", "path": "/orders"},
        "technique": "SEQUENCE",
        "source": "LLM",
        "steps": [
            {
                "name": "create order",
                "request": {"method": "POST", "path": "/orders"},
                "assertions": [{"type": "status", "equals": 201}],
            },
            {
                "name": "get order",
                "request": {"method": "GET", "path": "/orders/{id}"},
                "assertions": [{"type": "status", "equals": 200}],
            },
            {
                "name": "cancel order",
                "request": {"method": "DELETE", "path": "/orders/{id}"},
                "assertions": [{"type": "status", "equals": 204}],
            },
        ],
    }

    spec_endpoints = [
        {"method": "POST", "path": "/orders", "summary": "Create order"},
        {"method": "GET", "path": "/orders/{id}", "summary": "Get order"},
        {"method": "DELETE", "path": "/orders/{id}", "summary": "Delete order"},
        {"method": "GET", "path": "/unrelated", "summary": "Unrelated endpoint"},
    ]

    prompt = build_repair_user_prompt(
        invalid_dsl=multi_step_dsl,
        validation_errors=["Some error in step 2"],
        spec_endpoints=spec_endpoints,
    )

    assert "### Target Endpoints Specifications:" in prompt
    assert '"POST"' in prompt and '"/orders"' in prompt
    assert '"GET"' in prompt and '"/orders/{id}"' in prompt
    assert '"DELETE"' in prompt and '"/orders/{id}"' in prompt
    assert "/unrelated" not in prompt


@pytest.mark.parametrize("phase", ["initial", "repair"])
def test_regression_deep_json_does_not_crash_or_discard_logs(phase: str) -> None:
    deep = "[" * 1000 + "0" + "]" * 1000
    good = json.dumps(VALID_DSL_CASE)
    outputs = (
        [deep, good]
        if phase == "initial"
        else [json.dumps({"test_cases": [VALID_DSL_CASE, {"title": "Broken"}]}), deep, good]
    )
    provider = FakeLlmProvider(responses=outputs)
    client = make_client_with_provider(provider)
    response = client.post("/v1/generate", json=VALID_REQUEST_PAYLOAD)

    assert response.status_code == 200, (
        f"{phase}: status={response.status_code}, body={response.text}"
    )
    data = response.json()
    assert data["stats"]["valid"] >= 1
    assert len(data["llm_calls"]) == len(provider.calls)


@pytest.mark.parametrize("phase", ["initial", "repair"])
def test_regression_surrogate_in_model_output_does_not_break_response(phase: str) -> None:
    repaired_case = copy.deepcopy(VALID_DSL_CASE)
    repaired_case["steps"][0]["request"]["body"]["name"] = "Eraser"
    unsafe = copy.deepcopy(VALID_DSL_CASE)
    unsafe["title"] = "Surrogate \ud800 case"
    outputs = (
        [json.dumps({"test_cases": [unsafe]})]
        if phase == "initial"
        else [
            json.dumps({"test_cases": [VALID_DSL_CASE, {"title": "Broken"}]}),
            json.dumps(unsafe),
            json.dumps(repaired_case),
        ]
    )
    provider = FakeLlmProvider(responses=outputs)
    client = make_client_with_provider(provider)
    response = client.post("/v1/generate", json=VALID_REQUEST_PAYLOAD)

    assert response.status_code == 200, (
        f"{phase}: status={response.status_code}, body={response.text}"
    )
    data = response.json()
    assert len(data["llm_calls"]) == len(provider.calls)
    if phase == "initial":
        assert data["test_cases"][0]["status"] == "INVALID"
        has_surrogate_err = any(
            "surrogate" in err.lower() for err in data["test_cases"][0]["validation_errors"]
        )
        assert has_surrogate_err
    else:
        assert data["stats"]["valid"] == 2


@pytest.mark.parametrize("bad_val", ["NaN", "Infinity", "-Infinity", "1e999", "-1e999"])
def test_regression_nonfinite_json_rejected_and_repaired(bad_val: str) -> None:
    initial_raw = json.dumps({"test_cases": [VALID_DSL_CASE]}).replace(
        '"price": 10', f'"price": {bad_val}'
    )
    provider = FakeLlmProvider([initial_raw, json.dumps(VALID_DSL_CASE)])
    client = make_client_with_provider(provider)
    response = client.post("/v1/generate", json=VALID_REQUEST_PAYLOAD)

    assert response.status_code == 200
    data = response.json()
    assert len(data["test_cases"]) == 1
    case = data["test_cases"][0]
    assert case["status"] == "VALID"
    assert case["repair_attempts"] == 1
    assert data["stats"]["valid"] == 1
    assert data["stats"]["repaired"] == 1


class ExceptionalUnicodeProvider(FakeLlmProvider):
    message: str = "Upstream error \ud800"

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
        if self.calls:
            self.calls.append(messages)
            raise LlmProviderError(self.message)
        return super().complete(
            messages, temperature=temperature, seed=seed, json_mode=json_mode, model=model
        )


class MetadataUnicodeProvider(FakeLlmProvider):
    returned_model: str = "model-\ud800"

    def __init__(self, phase: str) -> None:
        super().__init__(
            responses=[
                json.dumps({"test_cases": [VALID_DSL_CASE, {"title": "Broken"}]}),
                json.dumps(VALID_DSL_CASE),
            ]
        )
        self.phase = phase

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
        output = super().complete(
            messages, temperature=temperature, seed=seed, json_mode=json_mode, model=model
        )
        if (self.phase == "initial" and len(self.calls) == 1) or (
            self.phase == "repair" and len(self.calls) == 2
        ):
            return LlmCompletion(content=output.content, model=self.returned_model)
        return output


@pytest.mark.parametrize("phase", ["initial", "repair"])
@pytest.mark.parametrize("position", ["root-key", "body-key", "nested-key"])
def test_regression_surrogate_key_errors_are_serializable(phase: str, position: str) -> None:
    bad = copy.deepcopy(VALID_DSL_CASE)
    if position == "root-key":
        bad["\ud800"] = "x"
    elif position == "body-key":
        bad["steps"][0]["request"]["body"]["\ud800"] = "x"
    else:
        bad["steps"][0]["request"]["body"]["meta"] = {"\ud800": "x"}
    raw = json.dumps(bad)
    if phase == "initial":
        outputs = [json.dumps({"test_cases": [VALID_DSL_CASE, bad]})]
        budget = 0
    else:
        outputs = [json.dumps({"test_cases": [VALID_DSL_CASE, {"title": "Broken"}]}), raw]
        budget = 1
    provider = FakeLlmProvider(responses=outputs)
    req = copy.deepcopy(VALID_REQUEST_PAYLOAD)
    req["options"]["max_repair_attempts"] = budget
    client = make_client_with_provider(provider)
    response = client.post("/v1/generate", json=req)
    assert response.status_code == 200, f"{phase}/{position}: {response.text}"
    data = response.json()
    assert data["stats"]["valid"] == 1 and data["stats"]["invalid"] == 1
    assert len(data["llm_calls"]) == len(provider.calls)
    assert data["test_cases"][1]["validation_errors"]
    assert data["test_cases"][1]["status"] == "INVALID"
    json.dumps(data).encode("utf-8")


def test_regression_provider_exception_unicode_keeps_partial_results_and_logs() -> None:
    provider = ExceptionalUnicodeProvider(
        responses=[json.dumps({"test_cases": [VALID_DSL_CASE, {"title": "Broken"}]})]
    )
    req = copy.deepcopy(VALID_REQUEST_PAYLOAD)
    req["options"]["max_repair_attempts"] = 1
    client = make_client_with_provider(provider)
    response = client.post("/v1/generate", json=req)
    assert response.status_code == 200, response.text
    data = response.json()
    assert data["stats"]["valid"] == 1 and data["stats"]["invalid"] == 1
    assert len(data["llm_calls"]) == 2
    assert data["llm_calls"][1]["error"]
    assert data["test_cases"][1]["validation_errors"]
    json.dumps(data).encode("utf-8")


@pytest.mark.parametrize("phase", ["initial", "repair"])
def test_regression_model_metadata_unicode_is_handled(phase: str) -> None:
    provider = MetadataUnicodeProvider(phase)
    req = copy.deepcopy(VALID_REQUEST_PAYLOAD)
    req["options"]["max_repair_attempts"] = 1
    client = make_client_with_provider(provider)
    response = client.post("/v1/generate", json=req)
    if phase == "initial":
        assert response.status_code in (200, 502), response.text
        if response.status_code == 502:
            assert response.json()["error"]["code"] != "INTERNAL_ERROR"
            return
    else:
        assert response.status_code == 200, response.text
    data = response.json()
    assert len(data["llm_calls"]) == len(provider.calls)
    json.dumps(data).encode("utf-8")


def test_regression_provider_exception_ascii_control() -> None:
    provider = ExceptionalUnicodeProvider(
        responses=[json.dumps({"test_cases": [VALID_DSL_CASE, {"title": "Broken"}]})]
    )
    provider.message = "Upstream error: timeout"
    req = copy.deepcopy(VALID_REQUEST_PAYLOAD)
    req["options"]["max_repair_attempts"] = 1
    client = make_client_with_provider(provider)
    response = client.post("/v1/generate", json=req)
    assert response.status_code == 200, response.text
    data = response.json()
    assert data["stats"]["valid"] == 1
    assert len(data["llm_calls"]) == 2
    json.dumps(data).encode("utf-8")


@pytest.mark.parametrize("phase", ["initial", "repair"])
def test_regression_model_metadata_valid_unicode_control(phase: str) -> None:
    provider = MetadataUnicodeProvider(phase)
    provider.returned_model = "model-日本語"
    req = copy.deepcopy(VALID_REQUEST_PAYLOAD)
    req["options"]["max_repair_attempts"] = 1
    client = make_client_with_provider(provider)
    response = client.post("/v1/generate", json=req)
    assert response.status_code == 200, response.text
    data = response.json()
    assert len(data["llm_calls"]) == 2
    json.dumps(data).encode("utf-8")


def test_repaired_case_is_deduplicated_and_keeps_selected_model_in_logs() -> None:
    provider = FakeLlmProvider(
        responses=[
            json.dumps({"test_cases": [VALID_DSL_CASE, {"title": "Broken"}]}),
            json.dumps(VALID_DSL_CASE),
        ]
    )
    payload = copy.deepcopy(VALID_REQUEST_PAYLOAD)
    payload["options"]["model"] = "custom-repair-model"
    response = make_client_with_provider(provider).post("/v1/generate", json=payload)
    assert response.status_code == 200
    data = response.json()
    first, repaired = data["test_cases"]
    assert first["status"] == "VALID"
    assert repaired["status"] == "DUPLICATE"
    assert repaired["fingerprint"] == first["fingerprint"]
    assert repaired["repair_attempts"] == 1
    assert repaired["validation_errors"] == []
    assert data["stats"] == {
        "generated": 2,
        "valid": 1,
        "invalid": 0,
        "repaired": 0,
        "duplicates": 1,
    }
    assert [call["purpose"] for call in data["llm_calls"]] == ["GENERATE", "REPAIR"]
    assert all(call["model"] == "custom-repair-model" for call in data["llm_calls"])


def test_generate_selects_model_and_records_seed_temperature_from_experiment() -> None:
    """S9-03: Chọn model và ghi seed/temperature theo experiment (M15)."""
    custom_model = "qwen-2.5-coder-32b-instruct"
    custom_seed = 12345
    custom_temperature = 0.75

    payload = json.loads(json.dumps(VALID_REQUEST_PAYLOAD))
    payload["options"]["model"] = custom_model
    payload["options"]["seed"] = custom_seed
    payload["options"]["temperature"] = custom_temperature

    app = create_app()
    app.dependency_overrides[get_settings] = lambda: Settings(llm_provider="fake")
    client = TestClient(app)

    response = client.post("/v1/generate", json=payload)
    assert response.status_code == 200

    data = response.json()
    assert len(data["llm_calls"]) == 1
    llm_call = data["llm_calls"][0]

    # Verify model, seed and temperature are accurately captured from experiment options
    assert llm_call["model"] == custom_model
    assert llm_call["seed"] == custom_seed
    assert llm_call["temperature"] == custom_temperature
    assert llm_call["provider"] == "fake"


def test_generate_records_fallback_model_when_options_model_is_null() -> None:
    """S9-03: Khi options.model là null, dùng model mặc định của provider và ghi nhận."""
    payload = json.loads(json.dumps(VALID_REQUEST_PAYLOAD))
    payload["options"]["model"] = None
    payload["options"]["seed"] = 42
    payload["options"]["temperature"] = 0.2

    app = create_app()
    app.dependency_overrides[get_settings] = lambda: Settings(llm_provider="fake")
    client = TestClient(app)

    response = client.post("/v1/generate", json=payload)
    assert response.status_code == 200

    data = response.json()
    assert len(data["llm_calls"]) == 1
    llm_call = data["llm_calls"][0]

    assert llm_call["model"] == "fake"
    assert llm_call["seed"] == 42
    assert llm_call["temperature"] == 0.2


def test_generate_deduplicates_identical_cases_and_populates_fingerprints() -> None:
    """Two identical valid DSL test cases returned by the provider must be deduplicated."""
    case1 = dict(VALID_DSL_CASE)
    case1["title"] = "Create item version A"
    case2 = dict(VALID_DSL_CASE)
    case2["title"] = "Create item version B with different title but identical execution"

    llm_output = json.dumps({"test_cases": [case1, case2]})
    provider = FakeLlmProvider(responses=[llm_output])
    client = make_client_with_provider(provider)

    response = client.post("/v1/generate", json=VALID_REQUEST_PAYLOAD)

    assert response.status_code == 200
    data = response.json()
    assert len(data["test_cases"]) == 2

    first_case = data["test_cases"][0]
    second_case = data["test_cases"][1]

    # First case is VALID with populated 64-char fingerprint
    assert first_case["status"] == "VALID"
    assert first_case["fingerprint"] is not None
    assert len(first_case["fingerprint"]) == 64

    # Second identical case is recognized as DUPLICATE with the same fingerprint
    assert second_case["status"] == "DUPLICATE"
    assert second_case["fingerprint"] == first_case["fingerprint"]

    # Stats accurately reflect 1 valid and 1 duplicate
    assert data["stats"]["generated"] == 2
    assert data["stats"]["valid"] == 1
    assert data["stats"]["duplicates"] == 1
    assert data["stats"]["invalid"] == 0


def test_generate_deduplicates_large_integer_and_exponential_cases() -> None:
    """Cases with price represented as 1000000000000000000000 vs 1e21 must be deduplicated."""
    case1 = json.loads(json.dumps(VALID_DSL_CASE))
    case1["title"] = "Create item with large integer price"
    case1["steps"][0]["request"]["body"]["price"] = 1000000000000000000000

    case2 = json.loads(json.dumps(VALID_DSL_CASE))
    case2["title"] = "Create item with exponential notation price"
    case2["steps"][0]["request"]["body"]["price"] = 1e21

    llm_output = json.dumps({"test_cases": [case1, case2]})
    provider = FakeLlmProvider(responses=[llm_output])
    client = make_client_with_provider(provider)

    response = client.post("/v1/generate", json=VALID_REQUEST_PAYLOAD)

    assert response.status_code == 200
    data = response.json()
    assert len(data["test_cases"]) == 2

    first_case = data["test_cases"][0]
    second_case = data["test_cases"][1]

    # First case is VALID with populated 64-char fingerprint
    assert first_case["status"] == "VALID"
    assert first_case["fingerprint"] is not None
    assert len(first_case["fingerprint"]) == 64

    # Second case with 1e21 must match first case with 10^21 and be marked DUPLICATE
    assert second_case["status"] == "DUPLICATE"
    assert second_case["fingerprint"] == first_case["fingerprint"]

    # Stats: duplicates must be 1, not 0
    assert data["stats"]["generated"] == 2
    assert data["stats"]["valid"] == 1
    assert data["stats"]["duplicates"] == 1
    assert data["stats"]["invalid"] == 0
