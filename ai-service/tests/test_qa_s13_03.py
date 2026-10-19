"""Regression tests for the S13-03 QA findings on ai-service.

1. Every key the contract lists in ``required`` (GenerateRequest, GenerateOptions,
   NormalizedEndpoint, BusinessRule) is required here too; nullable keys are sent as null.
   ``business_rules`` and ``seed_cases`` are required by the contract
   (ai-service.openapi.yaml GenerateRequest.required) and must be rejected when absent.
2. Unknown paths (404) and wrong methods (405) answer with the standard error envelope
   ``{error: {code, message, details}, trace_id}`` instead of FastAPI's ``{detail: ...}``.
"""

import copy
from typing import Any

import pytest
from fastapi.testclient import TestClient

from tests.test_generate import VALID_REQUEST_PAYLOAD


def _assert_envelope(data: dict[str, Any], code: str) -> None:
    assert "detail" not in data
    assert set(data.keys()) == {"error", "trace_id"}
    assert data["error"]["code"] == code
    assert isinstance(data["error"]["message"], str) and data["error"]["message"]
    assert data["error"]["details"] == {}
    assert isinstance(data["trace_id"], str) and data["trace_id"]


def test_empty_payload_lists_business_rules_and_seed_cases_as_missing(
    client: TestClient,
) -> None:
    response = client.post("/v1/generate", json={})

    assert response.status_code == 422
    data = response.json()
    assert data["error"]["code"] == "VALIDATION_FAILED"
    assert set(data["error"]["details"]) == {
        "job_id",
        "strategy",
        "endpoints",
        "business_rules",
        "seed_cases",
        "options",
    }


@pytest.mark.parametrize("field", ["business_rules", "seed_cases"])
def test_missing_required_list_field_is_rejected(client: TestClient, field: str) -> None:
    payload = copy.deepcopy(VALID_REQUEST_PAYLOAD)
    payload.pop(field, None)

    response = client.post("/v1/generate", json=payload)

    assert response.status_code == 422
    data = response.json()
    assert data["error"]["code"] == "VALIDATION_FAILED"
    assert field in data["error"]["details"]


@pytest.mark.parametrize(
    "field",
    [
        "max_cases_per_endpoint",
        "techniques",
        "model",
        "temperature",
        "seed",
        "max_repair_attempts",
    ],
)
def test_missing_options_key_is_rejected(client: TestClient, field: str) -> None:
    payload = copy.deepcopy(VALID_REQUEST_PAYLOAD)
    assert field in payload["options"]
    del payload["options"][field]

    response = client.post("/v1/generate", json=payload)

    assert response.status_code == 422
    data = response.json()
    assert data["error"]["code"] == "VALIDATION_FAILED"
    assert f"options.{field}" in data["error"]["details"]


def test_empty_options_object_lists_every_option_as_missing(client: TestClient) -> None:
    payload = copy.deepcopy(VALID_REQUEST_PAYLOAD)
    payload["options"] = {}

    response = client.post("/v1/generate", json=payload)

    assert response.status_code == 422
    assert set(response.json()["error"]["details"]) == {
        "options.max_cases_per_endpoint",
        "options.techniques",
        "options.model",
        "options.temperature",
        "options.seed",
        "options.max_repair_attempts",
    }


@pytest.mark.parametrize(
    "field",
    [
        "operation_id",
        "summary",
        "description",
        "parameters",
        "request_body",
        "responses",
        "security",
    ],
)
def test_missing_endpoint_key_is_rejected(client: TestClient, field: str) -> None:
    payload = copy.deepcopy(VALID_REQUEST_PAYLOAD)
    assert field in payload["endpoints"][0]
    del payload["endpoints"][0][field]

    response = client.post("/v1/generate", json=payload)

    assert response.status_code == 422
    data = response.json()
    assert data["error"]["code"] == "VALIDATION_FAILED"
    assert f"endpoints.0.{field}" in data["error"]["details"]


@pytest.mark.parametrize("field", ["operation_id", "parameters", "request_body", "security"])
def test_nullable_endpoint_key_sent_as_null_is_accepted(client: TestClient, field: str) -> None:
    payload = copy.deepcopy(VALID_REQUEST_PAYLOAD)
    payload["endpoints"][0][field] = None

    response = client.post("/v1/generate", json=payload)

    assert response.status_code == 200


def test_missing_business_rule_endpoint_id_is_rejected(client: TestClient) -> None:
    payload = copy.deepcopy(VALID_REQUEST_PAYLOAD)
    assert "endpoint_id" in payload["business_rules"][0]
    del payload["business_rules"][0]["endpoint_id"]

    response = client.post("/v1/generate", json=payload)

    assert response.status_code == 422
    data = response.json()
    assert data["error"]["code"] == "VALIDATION_FAILED"
    assert "business_rules.0.endpoint_id" in data["error"]["details"]


def test_project_wide_business_rule_with_null_endpoint_id_is_accepted(
    client: TestClient,
) -> None:
    payload = copy.deepcopy(VALID_REQUEST_PAYLOAD)
    payload["business_rules"][0]["endpoint_id"] = None

    response = client.post("/v1/generate", json=payload)

    assert response.status_code == 200


def test_empty_lists_are_still_accepted_for_llm(client: TestClient) -> None:
    payload = copy.deepcopy(VALID_REQUEST_PAYLOAD)
    payload["business_rules"] = []
    payload["seed_cases"] = []

    response = client.post("/v1/generate", json=payload)

    assert response.status_code == 200


def test_unknown_path_returns_not_found_envelope(client: TestClient) -> None:
    response = client.get("/v2/nothing", headers={"X-Request-Id": "trace-404"})

    assert response.status_code == 404
    data = response.json()
    _assert_envelope(data, "NOT_FOUND")
    assert data["trace_id"] == "trace-404"


def test_wrong_method_returns_method_not_allowed_envelope(client: TestClient) -> None:
    response = client.get("/v1/generate")

    assert response.status_code == 405
    _assert_envelope(response.json(), "METHOD_NOT_ALLOWED")
    assert "POST" in response.headers.get("allow", "")


def test_wrong_method_on_health_returns_envelope(client: TestClient) -> None:
    response = client.post("/health")

    assert response.status_code == 405
    _assert_envelope(response.json(), "METHOD_NOT_ALLOWED")
