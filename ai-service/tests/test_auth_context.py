"""ADR-0006: credential context of experiment runs (role names + placeholders, never tokens)."""

import copy
import json
from typing import Any

import pytest
from pydantic import ValidationError

from app.providers.fake import FakeLlmProvider
from app.schemas.generate import AuthContext, GenerateRequest
from app.services.prompts import build_repair_user_prompt, build_user_prompt
from app.services.validator import DslValidator
from tests.contract_payloads import ENDPOINT_DEFAULTS
from tests.test_generate import make_client_with_provider

AUTH: dict[str, Any] = {
    "scheme": "bearer",
    "default_role": "customer",
    "roles": [
        {"name": "customer", "description": "CUSTOMER alice"},
        {"name": "customer_bob", "description": "CUSTOMER bob"},
        {"name": "admin", "description": "ADMIN"},
    ],
    "operations": [
        {
            "method": "GET",
            "path": "/admin/orders",
            "role": "admin",
            "forbidden_roles": ["customer"],
        }
    ],
}

SECURED: list[dict[str, Any]] = [{"bearerAuth": []}]

PAYLOAD: dict[str, Any] = {
    "job_id": 7,
    "strategy": "LLM",
    "endpoints": [
        {
            **ENDPOINT_DEFAULTS,
            "id": 1,
            "method": "GET",
            "path": "/admin/orders",
            "responses": {"200": {"description": "ok"}, "401": {}, "403": {}},
            "security": SECURED,
        },
        {
            **ENDPOINT_DEFAULTS,
            "id": 2,
            "method": "GET",
            "path": "/orders/{order_id}",
            "parameters": [
                {"name": "order_id", "in": "path", "required": True, "schema": {"type": "integer"}}
            ],
            "responses": {"200": {"description": "ok"}, "403": {}, "404": {}},
            "security": SECURED,
        },
    ],
    "business_rules": [],
    "seed_cases": [],
    "options": {
        "max_cases_per_endpoint": 1,
        "techniques": ["AUTH"],
        "model": None,
        "temperature": 0.2,
        "seed": 42,
        "max_repair_attempts": 0,
    },
    "auth": AUTH,
}

FORBIDDEN_CASE: dict[str, Any] = {
    "dsl_version": "1.0",
    "title": "Customer cannot list every order",
    "endpoint": {"method": "GET", "path": "/admin/orders"},
    "technique": "AUTH",
    "source": "LLM",
    "steps": [
        {
            "name": "list as customer",
            "request": {
                "method": "GET",
                "path": "/admin/orders",
                "headers": {"Authorization": "Bearer {{auth_token_customer}}"},
            },
            "assertions": [{"type": "status", "equals": 403}],
        }
    ],
}


def test_auth_context_never_accepts_tokens() -> None:
    bad = copy.deepcopy(AUTH)
    bad["roles"][0].update({"token": "synthetic-sandbox-value"})
    with pytest.raises(ValidationError):
        AuthContext.model_validate(bad)


@pytest.mark.parametrize(
    "mutate",
    [
        lambda a: a.update(default_role="ghost"),
        lambda a: a["operations"][0].update(role="ghost"),
        lambda a: a["roles"].append({"name": "admin"}),
        lambda a: a.update(scheme="basic"),
        lambda a: a["roles"].append({"name": "Bad-Role"}),
    ],
)
def test_auth_context_rejects_inconsistent_roles(mutate: Any) -> None:
    bad = copy.deepcopy(AUTH)
    mutate(bad)
    with pytest.raises(ValidationError):
        AuthContext.model_validate(bad)


def test_auth_context_roles_and_variables() -> None:
    auth = AuthContext.model_validate(AUTH)
    assert auth.variable_names() == [
        "auth_token_customer",
        "auth_token_customer_bob",
        "auth_token_admin",
    ]
    assert auth.role_for("GET", "/admin/orders") == "admin"
    assert auth.role_for("GET", "/orders/{order_id}") == "customer"
    assert auth.forbidden_roles_for("get", "/admin/orders") == ["customer"]


def test_validator_accepts_context_variables_only_when_declared() -> None:
    validator = DslValidator()
    assert not validator.validate(FORBIDDEN_CASE).is_valid
    result = validator.validate(FORBIDDEN_CASE, context_variables=["auth_token_customer"])
    assert result.is_valid, result.errors
    # Other undefined variables are still rejected (D04).
    case = copy.deepcopy(FORBIDDEN_CASE)
    case["steps"][0]["request"]["headers"]["X-Trace"] = "{{trace_id}}"
    errors = validator.validate(case, context_variables=["auth_token_customer"]).errors
    assert any("trace_id" in e for e in errors)


def test_prompt_lists_roles_and_placeholders_but_no_token() -> None:
    request = GenerateRequest.model_validate(PAYLOAD)
    prompt = build_user_prompt(request, request.endpoints[0], 1)
    assert "### Authentication" in prompt
    assert "auth_token_customer_bob" in prompt
    assert 'Bearer {{auth_token_admin}}"' in prompt  # role of the operation
    assert "expect 403): customer" in prompt
    other = build_user_prompt(request, request.endpoints[1], 1)
    assert 'Bearer {{auth_token_customer}}"' in other


def test_prompt_without_auth_has_no_authentication_section() -> None:
    payload = copy.deepcopy(PAYLOAD)
    del payload["auth"]
    request = GenerateRequest.model_validate(payload)
    assert "### Authentication" not in build_user_prompt(request, request.endpoints[0], 1)


def test_public_endpoint_is_described_as_public() -> None:
    payload = copy.deepcopy(PAYLOAD)
    payload["endpoints"][0]["security"] = []
    request = GenerateRequest.model_validate(payload)
    assert "is public" in build_user_prompt(request, request.endpoints[0], 1)


def test_repair_prompt_lists_credential_variables() -> None:
    auth = AuthContext.model_validate(AUTH)
    prompt = build_repair_user_prompt(FORBIDDEN_CASE, ["x"], auth=auth)
    assert "{{auth_token_admin}}" in prompt
    assert "{{auth_token_admin}}" not in build_repair_user_prompt(FORBIDDEN_CASE, ["x"])


def test_generate_keeps_cases_that_use_credential_placeholders() -> None:
    provider = FakeLlmProvider(responses=[json.dumps({"test_cases": [FORBIDDEN_CASE]})] * 2)
    client = make_client_with_provider(provider)

    response = client.post("/v1/generate", json=PAYLOAD)
    assert response.status_code == 200, response.text
    statuses = [case["status"] for case in response.json()["test_cases"]]
    assert "VALID" in statuses

    # Without the credential context the same answer is INVALID (undefined variable).
    payload = copy.deepcopy(PAYLOAD)
    del payload["auth"]
    provider = FakeLlmProvider(responses=[json.dumps({"test_cases": [FORBIDDEN_CASE]})] * 2)
    response = make_client_with_provider(provider).post("/v1/generate", json=payload)
    assert response.status_code == 200, response.text
    assert all(case["status"] == "INVALID" for case in response.json()["test_cases"])


def test_hybrid_seed_cases_may_use_credential_placeholders() -> None:
    payload = copy.deepcopy(PAYLOAD)
    payload["strategy"] = "HYBRID"
    seed = copy.deepcopy(FORBIDDEN_CASE)
    seed["source"] = "RULE"
    payload["seed_cases"] = [seed]
    assert GenerateRequest.model_validate(payload).context_variables()
    del payload["auth"]
    with pytest.raises(ValidationError):
        GenerateRequest.model_validate(payload)
