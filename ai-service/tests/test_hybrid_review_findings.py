"""Regression tests verifying PR #91 review findings P2.

Findings covered:
- Finding 1 [P2]: HYBRID_SYSTEM_PROMPT and SYSTEM_PROMPT assertion examples conform to DSL schema.
- Finding 2 [P2]: Seed test case summary preserves execution context (body, query, headers, etc.).
  Specifically verifies explicit null body is preserved and distinguished from absent body.
- Finding 3 [P2]: Clean code formatting without ternary or syntax discrepancies.
"""

from __future__ import annotations

import copy
import json
import re
from typing import Any

import jsonschema
from fastapi.testclient import TestClient

from app.api.deps import get_provider
from app.config import Settings, get_settings
from app.main import create_app
from app.providers.fake import FakeLlmProvider
from app.schemas.generate import GenerateOptions, GenerateRequest, NormalizedEndpoint
from app.services.prompts import (
    HYBRID_SYSTEM_PROMPT,
    SYSTEM_PROMPT,
    build_user_prompt,
)
from tests.repo_files import REPO_ROOT

# GenerateOptions / NormalizedEndpoint keys the contract requires (S13-03).
_OPTIONS: dict[str, Any] = {
    "max_cases_per_endpoint": 1,
    "techniques": [],
    "model": None,
    "temperature": 0.2,
    "seed": None,
    "max_repair_attempts": 2,
}
_ENDPOINT_NULLS: dict[str, Any] = {
    "description": None,
    "parameters": None,
    "responses": {},
    "security": None,
}


def _load_dsl_assertion_validator() -> jsonschema.Draft202012Validator:
    schema_file = REPO_ROOT / "docs" / "contracts" / "test-dsl.schema.json"
    schema_doc = json.loads(schema_file.read_text(encoding="utf-8"))
    assertion_schema = schema_doc["$defs"]["assertion"]
    validator_schema = {
        "$schema": schema_doc.get("$schema", "https://json-schema.org/draft/2020-12/schema"),
        "$defs": schema_doc["$defs"],
        **assertion_schema,
    }
    return jsonschema.Draft202012Validator(validator_schema)


def test_finding_1_prompt_assertion_examples_conform_to_schema() -> None:
    """Assertion examples in HYBRID_SYSTEM_PROMPT and SYSTEM_PROMPT must be valid Test DSL v1.0."""
    validator = _load_dsl_assertion_validator()

    for prompt_text, prompt_name in [
        (HYBRID_SYSTEM_PROMPT, "HYBRID_SYSTEM_PROMPT"),
        (SYSTEM_PROMPT, "SYSTEM_PROMPT"),
    ]:
        assertion_matches = re.findall(r"\{\s*\"type\":[^\n]+\}", prompt_text)
        assert len(assertion_matches) >= 4, (
            f"Expected assertion examples in {prompt_name}, found {len(assertion_matches)}"
        )

        for raw_json in assertion_matches:
            brace_count = 0
            json_str = ""
            for char in raw_json:
                if char == "{":
                    brace_count += 1
                elif char == "}":
                    brace_count -= 1
                json_str += char
                if brace_count == 0:
                    break

            try:
                assertion_obj = json.loads(json_str)
            except json.JSONDecodeError:
                continue

            errors = list(validator.iter_errors(assertion_obj))
            assert errors == [], (
                f"Assertion example {json_str} in {prompt_name} failed schema: {errors}"
            )


def test_finding_1_invalid_prompt_dialects_are_rejected_by_schema() -> None:
    """Verify that the previous invalid assertion examples fail schema validation."""
    validator = _load_dsl_assertion_validator()

    # 1. Header with 'header' instead of 'name', 'op': 'equals'
    old_header = {"type": "header", "header": "Content-Type", "op": "equals", "value": "json"}
    assert list(validator.iter_errors(old_header)) != []

    # 2. JSONPath with 'op': 'equals'
    old_json_path = {"type": "json_path", "path": "$.id", "op": "equals", "value": "1"}
    assert list(validator.iter_errors(old_json_path)) != []

    # 3. Response time with 'max_ms' instead of 'lte_ms'
    old_response_time = {"type": "response_time", "max_ms": 1000}
    assert list(validator.iter_errors(old_response_time)) != []


def _make_base_seed_case() -> dict[str, Any]:
    return {
        "dsl_version": "1.0",
        "title": "Base Seed Case",
        "endpoint": {"method": "POST", "path": "/items"},
        "technique": "BOUNDARY",
        "source": "RULE",
        "steps": [
            {
                "name": "create item step",
                "request": {
                    "method": "POST",
                    "path": "/items",
                    "headers": {"Content-Type": "application/json"},
                    "query": {},
                    "body": {"name": "Widget", "price": 10},
                },
                "assertions": [{"type": "status", "equals": 201}],
            }
        ],
    }


def _make_base_hybrid_request() -> GenerateRequest:
    return GenerateRequest(
        job_id=1,
        strategy="HYBRID",
        options=GenerateOptions(**_OPTIONS),
        endpoints=[
            NormalizedEndpoint(
                **_ENDPOINT_NULLS,
                id=1,
                method="POST",
                path="/items",
                summary="Create item",
                operation_id="createItem",
                request_body={
                    "content": {
                        "application/json": {
                            "schema": {
                                "type": "object",
                            }
                        }
                    }
                },
            )
        ],
        business_rules=[],
        seed_cases=[_make_base_seed_case()],
    )


def test_finding_2_seed_summary_preserves_body_context() -> None:
    """Prompt must distinguish seed cases differing only by request body."""
    req_a = _make_base_hybrid_request()
    seed_a = _make_base_seed_case()
    seed_a["steps"][0]["request"]["body"] = {"price": 10}
    req_a.seed_cases = [seed_a]

    req_b = _make_base_hybrid_request()
    seed_b = _make_base_seed_case()
    seed_b["steps"][0]["request"]["body"] = {"price": 20}
    req_b.seed_cases = [seed_b]

    prompt_a = build_user_prompt(req_a)
    prompt_b = build_user_prompt(req_b)

    assert prompt_a != prompt_b
    assert '"price": 10' in prompt_a
    assert '"price": 20' in prompt_b


def test_finding_2_seed_summary_preserves_explicit_null_body_vs_absent_body() -> None:
    """Prompt must distinguish seed with explicit body=null from seed with absent body."""
    req_null = _make_base_hybrid_request()
    seed_null = _make_base_seed_case()
    # Explicit null body
    seed_null["steps"][0]["request"]["body"] = None
    req_null.seed_cases = [seed_null]

    req_absent = _make_base_hybrid_request()
    seed_absent = _make_base_seed_case()
    # Absent body: remove "body" key completely
    del seed_absent["steps"][0]["request"]["body"]
    req_absent.seed_cases = [seed_absent]

    prompt_null = build_user_prompt(req_null)
    prompt_absent = build_user_prompt(req_absent)

    assert prompt_null != prompt_absent
    # prompt_null must explicitly record "body": null
    assert '"body": null' in prompt_null
    # prompt_absent must NOT contain "body" key in step request
    assert '"body": null' not in prompt_absent
    assert '"body"' not in prompt_absent


def test_finding_2_seed_summary_preserves_falsy_and_empty_bodies() -> None:
    """All falsy / empty payloads (False, 0, empty string, [], {}) are preserved."""
    values = [False, 0, "", [], {}]
    prompts: list[str] = []

    for val in values:
        req = _make_base_hybrid_request()
        seed = _make_base_seed_case()
        seed["steps"][0]["request"]["body"] = val
        req.seed_cases = [seed]
        p = build_user_prompt(req)
        prompts.append(p)

    # Every value must produce a unique prompt
    assert len(set(prompts)) == len(values)


def test_finding_2_seed_summary_preserves_query_context() -> None:
    """Prompt must distinguish seed cases differing only by query parameters."""
    req_a = _make_base_hybrid_request()
    seed_a = _make_base_seed_case()
    seed_a["steps"][0]["request"]["query"] = {"q": "A"}
    req_a.seed_cases = [seed_a]

    req_b = _make_base_hybrid_request()
    seed_b = _make_base_seed_case()
    seed_b["steps"][0]["request"]["query"] = {"q": "B"}
    req_b.seed_cases = [seed_b]

    prompt_a = build_user_prompt(req_a)
    prompt_b = build_user_prompt(req_b)

    assert prompt_a != prompt_b
    assert '"q": "A"' in prompt_a
    assert '"q": "B"' in prompt_b


def test_finding_2_seed_summary_preserves_headers_context() -> None:
    """Prompt must distinguish seed cases differing only by headers."""
    req_a = _make_base_hybrid_request()
    seed_a = _make_base_seed_case()
    seed_a["steps"][0]["request"]["headers"] = {"X-Tenant": "TenantA"}
    req_a.seed_cases = [seed_a]

    req_b = _make_base_hybrid_request()
    seed_b = _make_base_seed_case()
    seed_b["steps"][0]["request"]["headers"] = {"X-Tenant": "TenantB"}
    req_b.seed_cases = [seed_b]

    prompt_a = build_user_prompt(req_a)
    prompt_b = build_user_prompt(req_b)

    assert prompt_a != prompt_b
    assert '"X-Tenant": "TenantA"' in prompt_a
    assert '"X-Tenant": "TenantB"' in prompt_b


def test_finding_2_seed_summary_preserves_assertions_context() -> None:
    """Prompt must distinguish seed cases differing only by assertions."""
    req_a = _make_base_hybrid_request()
    seed_a = _make_base_seed_case()
    seed_a["steps"][0]["assertions"] = [{"type": "status", "equals": 201}]
    req_a.seed_cases = [seed_a]

    req_b = _make_base_hybrid_request()
    seed_b = _make_base_seed_case()
    seed_b["steps"][0]["assertions"] = [{"type": "status", "equals": 422}]
    req_b.seed_cases = [seed_b]

    prompt_a = build_user_prompt(req_a)
    prompt_b = build_user_prompt(req_b)

    assert prompt_a != prompt_b
    assert '"equals": 201' in prompt_a
    assert '"equals": 422' in prompt_b


def test_finding_2_seed_summary_preserves_variables_and_extract() -> None:
    """Prompt must preserve variables and extraction definitions from seed cases."""
    req = _make_base_hybrid_request()
    seed = _make_base_seed_case()
    seed["variables"] = {"unit_price": 100}
    seed["steps"][0]["extract"] = [{"var": "item_id", "from": "body", "path": "$.id"}]
    req.seed_cases = [seed]

    prompt = build_user_prompt(req)

    assert '"unit_price": 100' in prompt
    assert '"var": "item_id"' in prompt
    assert '"path": "$.id"' in prompt


def test_finding_2_e2e_hybrid_generation_explicit_null_vs_absent_body() -> None:
    """E2E /v1/generate verifies distinct prompts and dedup accuracy for null vs absent body."""
    # Case with explicit null body
    case_null = _make_base_seed_case()
    case_null["source"] = "HYBRID"
    case_null["steps"][0]["request"]["body"] = None

    llm_output = json.dumps({"test_cases": [case_null]})

    def _make_client(provider: Any) -> TestClient:
        app = create_app()
        app.dependency_overrides[get_settings] = lambda: Settings(llm_provider="fake")
        app.dependency_overrides[get_provider] = lambda: provider
        return TestClient(app)

    # 1. Request with Seed having explicit body=null
    provider_a = FakeLlmProvider(responses=[llm_output])
    client_a = _make_client(provider_a)

    seed_null = copy.deepcopy(case_null)
    seed_null["source"] = "RULE"

    payload_a = {
        "job_id": 1,
        "strategy": "HYBRID",
        "endpoints": [
            {
                **_ENDPOINT_NULLS,
                "id": 1,
                "method": "POST",
                "path": "/items",
                "summary": "Create item",
                "operation_id": "createItem",
                "request_body": {
                    "content": {
                        "application/json": {
                            "schema": {
                                "type": "object",
                            }
                        }
                    }
                },
            }
        ],
        "options": dict(_OPTIONS),
        "business_rules": [],
        "seed_cases": [seed_null],
    }

    resp_a = client_a.post("/v1/generate", json=payload_a)
    assert resp_a.status_code == 200
    data_a = resp_a.json()
    prompt_a = data_a["llm_calls"][0]["prompt"][1]["content"]

    # Since LLM output matched seed_null's execution semantics, it is DUPLICATE
    assert data_a["test_cases"][0]["status"] == "DUPLICATE"

    # 2. Request with Seed having absent body
    provider_b = FakeLlmProvider(responses=[llm_output])
    client_b = _make_client(provider_b)

    seed_absent = copy.deepcopy(case_null)
    seed_absent["source"] = "RULE"
    del seed_absent["steps"][0]["request"]["body"]

    payload_b = copy.deepcopy(payload_a)
    payload_b["seed_cases"] = [seed_absent]

    resp_b = client_b.post("/v1/generate", json=payload_b)
    assert resp_b.status_code == 200
    data_b = resp_b.json()
    prompt_b = data_b["llm_calls"][0]["prompt"][1]["content"]

    # Prompts received by provider MUST be distinct!
    assert prompt_a != prompt_b
    assert '"body": null' in prompt_a
    assert '"body": null' not in prompt_b

    # Since seed_absent had NO body, the LLM output with body=null is a new valid variant!
    assert data_b["test_cases"][0]["status"] == "VALID"


# ---------------------------------------------------------------------------
# Finding P2 Regression Tests: Seed cases schema & semantic validation gate
# ---------------------------------------------------------------------------


def _make_valid_test_payload() -> dict[str, Any]:
    return {
        "job_id": 1,
        "strategy": "HYBRID",
        "endpoints": [
            {
                **_ENDPOINT_NULLS,
                "id": 1,
                "method": "POST",
                "path": "/items",
                "summary": "Create item",
                "operation_id": "createItem",
                "request_body": {
                    "content": {
                        "application/json": {
                            "schema": {
                                "type": "object",
                            }
                        }
                    }
                },
            }
        ],
        "options": dict(_OPTIONS),
        "business_rules": [],
        "seed_cases": [_make_base_seed_case()],
    }


def test_finding_p2_five_raw_invalid_seeds_rejected_with_422_and_index() -> None:
    """All 5 invalid raw seeds identified in review must return 422 with seed_cases[0]."""
    provider = FakeLlmProvider(responses=['{"test_cases": []}'])
    app = create_app()
    app.dependency_overrides[get_settings] = lambda: Settings(llm_provider="fake")
    app.dependency_overrides[get_provider] = lambda: provider
    client = TestClient(app)

    # 1. {} (empty dict)
    # 2. dsl_version='2.0' (invalid version / missing required properties)
    # 3. title='x' (too short, minLength=3)
    # 4. missing assertions in step
    # 5. steps is string instead of array
    invalid_raw_seeds = [
        {},
        {"dsl_version": "2.0"},
        {
            "dsl_version": "1.0",
            "title": "x",
            "endpoint": {"method": "POST", "path": "/items"},
            "technique": "BOUNDARY",
            "source": "RULE",
            "steps": [
                {
                    "name": "s1",
                    "request": {"method": "POST", "path": "/items"},
                    "assertions": [{"type": "status", "equals": 200}],
                }
            ],
        },
        {
            "dsl_version": "1.0",
            "title": "Valid Title Here",
            "endpoint": {"method": "POST", "path": "/items"},
            "technique": "BOUNDARY",
            "source": "RULE",
            "steps": [
                {
                    "name": "s1",
                    "request": {"method": "POST", "path": "/items"},
                }
            ],
        },
        {
            "dsl_version": "1.0",
            "title": "Valid Title Here",
            "endpoint": {"method": "POST", "path": "/items"},
            "technique": "BOUNDARY",
            "source": "RULE",
            "steps": "step string not an array",
        },
    ]

    for seed in invalid_raw_seeds:
        payload = _make_valid_test_payload()
        payload["seed_cases"] = [seed]
        resp = client.post("/v1/generate", json=payload)
        assert resp.status_code == 422, f"Expected 422 for seed {seed}, got {resp.status_code}"
        err_text = json.dumps(resp.json(), ensure_ascii=False)
        assert "seed_cases[0]" in err_text, f"Expected 'seed_cases[0]' in error details: {err_text}"
        # Crucially: Provider must NOT be called for invalid seed cases!
        assert len(provider.calls) == 0, f"Provider was called unexpectedly for seed: {seed}"


def test_finding_p2_semantic_invalid_seed_rejected_with_422() -> None:
    """Seed targeting an undeclared endpoint must be rejected with 422 and index."""
    provider = FakeLlmProvider(responses=['{"test_cases": []}'])
    app = create_app()
    app.dependency_overrides[get_settings] = lambda: Settings(llm_provider="fake")
    app.dependency_overrides[get_provider] = lambda: provider
    client = TestClient(app)

    semantic_invalid_seed = {
        "dsl_version": "1.0",
        "title": "Seed targeting unknown endpoint",
        "endpoint": {"method": "DELETE", "path": "/unknown-resource"},
        "technique": "BUSINESS_RULE",
        "source": "RULE",
        "steps": [
            {
                "name": "delete step",
                "request": {"method": "DELETE", "path": "/unknown-resource"},
                "assertions": [{"type": "status", "equals": 200}],
            }
        ],
    }

    payload = _make_valid_test_payload()
    payload["seed_cases"] = [semantic_invalid_seed]
    resp = client.post("/v1/generate", json=payload)
    assert resp.status_code == 422
    err_text = json.dumps(resp.json(), ensure_ascii=False)
    assert "seed_cases[0]" in err_text
    assert len(provider.calls) == 0


def test_finding_p2_multi_seed_error_index_accuracy() -> None:
    """When a secondary seed is invalid, the error message points specifically to seed_cases[1]."""
    provider = FakeLlmProvider(responses=['{"test_cases": []}'])
    app = create_app()
    app.dependency_overrides[get_settings] = lambda: Settings(llm_provider="fake")
    app.dependency_overrides[get_provider] = lambda: provider
    client = TestClient(app)

    valid_seed = _make_base_seed_case()
    invalid_second_seed = {"dsl_version": "1.0", "title": "x"}  # invalid

    payload = _make_valid_test_payload()
    payload["seed_cases"] = [valid_seed, invalid_second_seed]

    resp = client.post("/v1/generate", json=payload)
    assert resp.status_code == 422
    err_text = json.dumps(resp.json(), ensure_ascii=False)
    assert "seed_cases[1]" in err_text
    assert len(provider.calls) == 0


def test_finding_p2_generator_service_direct_call_rejects_invalid_seed() -> None:
    """GeneratorService.generate directly raises AppError 422 before provider is invoked."""
    from app.schemas.errors import AppError
    from app.services.generator import GeneratorService

    provider = FakeLlmProvider(responses=['{"test_cases": []}'])
    service = GeneratorService(provider=provider)

    # Valid endpoint
    ep = NormalizedEndpoint(
        **_ENDPOINT_NULLS,
        id=1,
        method="POST",
        path="/items",
        summary="Create item",
        operation_id="createItem",
        request_body={"content": {"application/json": {"schema": {"type": "object"}}}},
    )

    # Construct request bypassing Pydantic validation if instantiated without schema validator
    req = GenerateRequest.model_construct(
        job_id=1,
        strategy="HYBRID",
        endpoints=[ep],
        business_rules=[],
        seed_cases=[{"title": "x"}],  # invalid seed
        options=GenerateOptions(**_OPTIONS),
    )

    import pytest

    with pytest.raises(AppError) as exc_info:
        service.generate(req)

    assert exc_info.value.status_code == 422
    assert "seed_cases[0]" in exc_info.value.message
    assert len(provider.calls) == 0


def test_finding_p2_positive_control_valid_seed_causes_duplicate_detection() -> None:
    """Valid seed case registers fingerprint; identical LLM output is correctly DUPLICATE."""
    valid_seed = _make_base_seed_case()
    valid_seed["source"] = "RULE"

    # Provider outputs identical scenario but with source HYBRID and valid title
    llm_case = copy.deepcopy(valid_seed)
    llm_case["source"] = "HYBRID"
    llm_case["title"] = "Different Title But Same Scenario"

    provider = FakeLlmProvider(responses=[json.dumps({"test_cases": [llm_case]})])
    app = create_app()
    app.dependency_overrides[get_settings] = lambda: Settings(llm_provider="fake")
    app.dependency_overrides[get_provider] = lambda: provider
    client = TestClient(app)

    payload = _make_valid_test_payload()
    payload["seed_cases"] = [valid_seed]

    resp = client.post("/v1/generate", json=payload)
    assert resp.status_code == 200
    data = resp.json()

    # Provider was called exactly once
    assert len(provider.calls) == 1
    # Test case was recognized as duplicate of valid seed
    assert data["test_cases"][0]["status"] == "DUPLICATE"
    assert data["stats"]["duplicates"] == 1
    assert data["stats"]["valid"] == 0
