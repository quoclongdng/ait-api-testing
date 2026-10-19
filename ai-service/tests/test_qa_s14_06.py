"""S14-06 regressions from the live QA of the production site.

1. The prompts only allow ``{{auth_token_<role>}}`` when the request has a credential context
   (ADR-0006). Outside experiments a secured endpoint only gets AUTH cases without credentials,
   as RULE does (S10-01), instead of cases that are INVALID or FAIL with 401.
2. JSONPath outside the subset the web Assertion Engine evaluates ($[*], .., filters) makes a
   case INVALID, and the prompt states the supported syntax.
3. A provider HTTP error reaches users as a short Vietnamese message without the provider URL;
   the redacted raw text stays in llm_calls[].error (D09).
4. Validation and repair errors shown on M10/M11 are Vietnamese, without configuration names.
"""

import copy
import json
import os
import shutil
import subprocess
from pathlib import Path
from typing import Any

import httpx
import pytest
from fastapi.testclient import TestClient

from app.api.deps import get_provider
from app.config import Settings, get_settings
from app.contracts.llm import ChatMessage, LlmProviderError
from app.main import create_app
from app.providers.fake import FakeLlmProvider
from app.providers.openai_compatible import OpenAICompatibleProvider
from app.schemas.generate import GenerateRequest
from app.services.prompts import (
    build_generation_messages,
    build_repair_messages,
    build_system_prompt,
    build_user_prompt,
    technique_plan,
)
from app.services.validator import DslValidator, is_supported_json_path
from tests.repo_files import REPO_ROOT
from tests.test_auth_context import FORBIDDEN_CASE, PAYLOAD
from tests.test_fingerprint import php_cross_runtime_prerequisites
from tests.test_generate import VALID_DSL_CASE, VALID_REQUEST_PAYLOAD, make_client_with_provider


def _request(with_auth: bool) -> GenerateRequest:
    payload = copy.deepcopy(PAYLOAD)
    payload["options"]["techniques"] = []
    payload["options"]["max_cases_per_endpoint"] = 3
    if not with_auth:
        del payload["auth"]
    return GenerateRequest.model_validate(payload)


# 1. Credential placeholders only with a credential context ---------------------------------


def test_prompts_without_auth_never_mention_auth_token_variables() -> None:
    request = _request(with_auth=False)
    messages = build_generation_messages(request, request.endpoints[0], 3)
    text = "\n".join(m.content for m in messages)

    assert "auth_token_" not in text
    assert "### Secured Endpoint Without Credentials" in text
    assert "Write only AUTH cases" in text
    assert "expect 401" in text  # 401 is declared by GET /admin/orders
    repair = build_repair_messages(VALID_DSL_CASE, ["x"], strategy="LLM")
    assert "auth_token_" not in "\n".join(m.content for m in repair)


def test_prompts_with_auth_keep_the_placeholder_rule() -> None:
    request = _request(with_auth=True)
    messages = build_generation_messages(request, request.endpoints[0], 3)

    assert '"auth_token_<role>" variables listed under Authentication' in messages[0].content
    assert "### Authentication" in messages[1].content
    assert "### Secured Endpoint Without Credentials" not in messages[1].content
    repair = build_repair_messages(FORBIDDEN_CASE, ["x"], auth=request.auth)
    assert '"auth_token_<role>" variables' in repair[0].content
    assert "auth_token_<role>" in build_system_prompt("HYBRID", with_auth=True)
    assert "auth_token_<role>" not in build_system_prompt("HYBRID")


def test_secured_endpoint_without_auth_is_planned_as_auth_cases_only() -> None:
    request = _request(with_auth=False)
    assert technique_plan(request, request.endpoints[0], 3, has_related=True) == ["AUTH", "AUTH"]
    with_auth = _request(with_auth=True)
    plan = technique_plan(with_auth, with_auth.endpoints[0], 3, has_related=True)
    assert plan != ["AUTH", "AUTH"]


def test_secured_endpoint_without_401_asks_for_the_declared_status() -> None:
    payload = copy.deepcopy(PAYLOAD)
    del payload["auth"]
    request = GenerateRequest.model_validate(payload)
    # GET /orders/{order_id} declares 403 but not 401.
    prompt = build_user_prompt(request, request.endpoints[1], 2)
    assert "declared status for a missing credential" in prompt


def test_repair_without_auth_tells_the_model_to_drop_credential_placeholders() -> None:
    (_, user) = build_repair_messages(FORBIDDEN_CASE, ["Biến '{{auth_token_customer}}' ..."])
    assert "No credential variables exist in this run" in user.content
    assert "never write a literal token" in user.content


def test_case_with_auth_token_placeholder_stays_invalid_without_auth() -> None:
    payload = copy.deepcopy(PAYLOAD)
    del payload["auth"]
    payload["options"]["max_repair_attempts"] = 1
    provider = FakeLlmProvider(
        [json.dumps({"test_cases": [FORBIDDEN_CASE]}), json.dumps(FORBIDDEN_CASE)] * 2
    )
    data = make_client_with_provider(provider).post("/v1/generate", json=payload).json()

    assert all(case["status"] == "INVALID" for case in data["test_cases"])
    repair_prompts = [
        call[1].content
        for call in provider.calls
        if call[1].content.startswith("The following generated API test case failed")
    ]
    assert repair_prompts
    assert all("No credential variables exist" in prompt for prompt in repair_prompts)


# 2. JSONPath subset ------------------------------------------------------------------------


@pytest.mark.parametrize("path", ["$", "$.a.b", "$[0].id", "$['x-y']", '$["a b"].c', "$.items[2]"])
def test_supported_json_paths(path: str) -> None:
    assert is_supported_json_path(path)


@pytest.mark.parametrize("path", ["$[*].id", "$..id", "$[?(@.a)]", "$.items[0:2]", "$.*", "$.a."])
def test_unsupported_json_paths_make_the_case_invalid(path: str) -> None:
    case = copy.deepcopy(VALID_DSL_CASE)
    case["steps"][0]["assertions"].append({"type": "json_path", "path": path, "op": "exists"})
    result = DslValidator().validate(case)

    assert not result.is_valid
    assert any("không được hỗ trợ" in e and "assertion #2" in e for e in result.errors)


def test_unsupported_extract_path_makes_the_case_invalid() -> None:
    case = copy.deepcopy(VALID_DSL_CASE)
    case["steps"][0]["extract"] = [{"var": "ids", "from": "body", "path": "$[*]"}]
    result = DslValidator().validate(case)

    assert not result.is_valid
    assert any("extract #1" in e and "không được hỗ trợ" in e for e in result.errors)


def test_json_path_with_a_runtime_variable_is_not_checked() -> None:
    case = copy.deepcopy(VALID_DSL_CASE)
    case["variables"] = {"key": "name"}
    case["steps"][0]["assertions"].append(
        {"type": "json_path", "path": "$.{{key}}", "op": "exists"}
    )
    assert DslValidator().validate(case).is_valid


def test_prompts_state_the_supported_json_path_syntax() -> None:
    assert "never [*], .., filters" in build_system_prompt("LLM")
    repair = build_repair_messages(VALID_DSL_CASE, ["x"])
    assert "never [*], .., filters" in repair[0].content


# 3. Provider errors --------------------------------------------------------------------------


def _not_found(request: httpx.Request) -> httpx.Response:
    return httpx.Response(404, json={"error": {"message": "model not found"}})


def test_provider_http_error_message_has_no_url() -> None:
    provider = OpenAICompatibleProvider(
        base_url="https://api.provider.test/openai/v1",
        model="qwen2.5-coder:7b",
        transport=httpx.MockTransport(_not_found),
        rate_limit_retries=0,
    )
    with pytest.raises(LlmProviderError) as excinfo:
        provider.complete([ChatMessage(role="user", content="hi")], temperature=0.0)

    message = str(excinfo.value)
    assert "https://" not in message
    assert "404" in message
    assert "qwen2.5-coder:7b" in message
    assert "model không tồn tại" in message
    # The raw (redacted) httpx text is kept for llm_calls[].error (D09).
    assert "api.provider.test" in excinfo.value.detail


def test_provider_error_reaches_the_502_in_vietnamese_with_the_raw_text_in_the_log() -> None:
    app = create_app()
    app.dependency_overrides[get_settings] = lambda: Settings(llm_provider="openai_compatible")
    app.dependency_overrides[get_provider] = lambda: OpenAICompatibleProvider(
        base_url="https://api.provider.test/openai/v1",
        model="qwen2.5-coder:7b",
        transport=httpx.MockTransport(_not_found),
        rate_limit_retries=0,
    )
    response = TestClient(app).post("/v1/generate", json=VALID_REQUEST_PAYLOAD)

    assert response.status_code == 502
    error = response.json()["error"]
    assert "https://" not in error["message"]
    assert error["message"].startswith("Nhà cung cấp LLM trả lỗi HTTP 404")
    (call,) = error["details"]["llm_calls"]
    assert "404 Not Found" in call["error"]


# 4. Vietnamese validation and repair errors ------------------------------------------------


def test_schema_errors_are_vietnamese_with_the_location() -> None:
    case: dict[str, Any] = copy.deepcopy(VALID_DSL_CASE)
    del case["steps"][0]["assertions"]
    case["steps"][0]["request"]["query_params"] = {"a": 1}
    errors = DslValidator().validate(case).errors

    assert "Bước 1 ('create item'): thiếu trường bắt buộc assertions" in errors
    assert "Bước 1 ('create item'), request: có trường không được phép: query_params" in errors
    assert not any("is a required property" in e or "were unexpected" in e for e in errors)


def test_assertion_errors_report_the_branch_of_the_declared_type() -> None:
    case = copy.deepcopy(VALID_DSL_CASE)
    case["steps"][0]["assertions"] = [{"type": "json_path", "path": "$.a"}]
    errors = DslValidator().validate(case).errors
    assert "Bước 1 ('create item'), assertion #1: thiếu trường bắt buộc op" in errors

    case["steps"][0]["assertions"] = [{"type": "bogus"}]
    (error,) = DslValidator().validate(case).errors
    assert error.startswith(
        "Bước 1 ('create item'), assertion #1: loại \"bogus\" không hợp lệ, chỉ chấp nhận: status"
    )


def test_inline_schema_meta_errors_are_vietnamese() -> None:
    case = copy.deepcopy(VALID_DSL_CASE)
    case["steps"][0]["assertions"].append(
        {"type": "json_schema", "schema": {"type": "object", "properties": []}}
    )
    errors = DslValidator().validate(case).errors
    assert any("$.properties: sai kiểu dữ liệu: cần đối tượng, nhận mảng" in e for e in errors)


def test_repair_errors_are_vietnamese_without_configuration_names() -> None:
    bad = copy.deepcopy(VALID_DSL_CASE)
    bad["dsl_version"] = "bad"
    moved = copy.deepcopy(VALID_DSL_CASE)
    moved["endpoint"] = {"method": "GET", "path": "/other"}
    moved["steps"][0]["request"] = {"method": "GET", "path": "/other"}
    payload = copy.deepcopy(VALID_REQUEST_PAYLOAD)
    payload["options"]["max_repair_attempts"] = 2
    provider = FakeLlmProvider([json.dumps({"test_cases": [bad]}), "{broken", json.dumps(moved)])
    data = make_client_with_provider(provider).post("/v1/generate", json=payload).json()

    (case,) = data["test_cases"]
    assert case["status"] == "INVALID"
    errors = case["validation_errors"]
    assert any("Không đọc được JSON trong câu trả lời sửa lỗi" in e for e in errors)
    assert any("giữ nguyên endpoint đích" in e for e in errors)
    for error in errors:
        assert "AI_" not in error
        assert "Repair" not in error and "Failed" not in error


# Review of S14-06: the ai-service JSONPath check must accept exactly what the web engine
# evaluates, or a case is repaired for nothing (ai-service stricter) or VALID but ERROR/FAIL at
# run time (ai-service looser). Compared with the real web class, like the fingerprint test.
JSONPATH_PROBE = Path(__file__).parent / "fixtures" / "reference_jsonpath.php"
WEB_JSONPATH_SUBSET = REPO_ROOT / "web" / "app" / "Services" / "Execution" / "JsonPathSubset.php"
CROSS_RUNTIME_PATHS = [
    "$",
    "",
    "$.a",
    "$a",
    "a.b",
    "$.items[0].id",
    "$[01]",
    "$[ 0 ]",
    "$['x-y']",
    '$["a b"]',
    "$['it\\'s']",
    '$["a\\"b"]',
    '$["\\u0069d"]',
    '$["bad\\q"]',
    "$['bad\\q']",
    '$["\\ud800"]',
    "$[*]",
    "$.*",
    "$..id",
    "$[?(@.a)]",
    "$[0:2]",
    "$[-1]",
    "$['a','b']",
    "$.a.",
    "$.a b",
    " $.a ",
    "$.a\u00a0b",
    "\u00a0$.a",
    "$.\u00e9",
    "$.a\tb",
    "$.['a']",
    "$.a$",
    "$.a-b",
    "$.0",
]


def test_json_path_support_matches_the_web_class() -> None:
    php_bin, web_class = php_cross_runtime_prerequisites(
        shutil.which("php"),
        WEB_JSONPATH_SUBSET,
        required=os.environ.get("REQUIRE_PHP_CROSS_TEST") == "1",
    )
    result = subprocess.run(  # noqa: S603
        [php_bin, str(JSONPATH_PROBE)],
        input=json.dumps(CROSS_RUNTIME_PATHS),
        capture_output=True,
        text=True,
        check=True,
        env={**os.environ, "AIT_WEB_JSONPATH_SUBSET": str(web_class)},
    )
    php_answers = json.loads(result.stdout)

    python_answers = [is_supported_json_path(path) for path in CROSS_RUNTIME_PATHS]

    assert dict(zip(CROSS_RUNTIME_PATHS, python_answers, strict=True)) == dict(
        zip(CROSS_RUNTIME_PATHS, php_answers, strict=True)
    )


@pytest.mark.parametrize("path", ['$["bad\\q"]', "$['bad\\q']", '$["\\ud800"]'])
def test_quoted_key_with_an_invalid_escape_is_not_supported(path: str) -> None:
    assert not is_supported_json_path(path)
