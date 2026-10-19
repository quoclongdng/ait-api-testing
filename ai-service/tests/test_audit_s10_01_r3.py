"""Regression tests for the S10-01 ai-service audit findings (round 3).

Each test reproduces one verified finding and fails on the code before the fix.
"""

import copy
import json
import re
from pathlib import Path
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
from app.schemas.generate import GenerateRequest
from app.services.generator import GeneratorService
from app.services.path_normalizer import normalize_request_paths
from app.services.prompts import build_generation_messages, build_user_prompt, technique_plan
from app.services.validator import DslValidator
from tests.contract_payloads import contract_endpoint, contract_options
from tests.repo_files import REPO_ROOT
from tests.test_generate import VALID_DSL_CASE, VALID_REQUEST_PAYLOAD

EXAMPLES_DIR = REPO_ROOT / "docs" / "contracts" / "examples"
SECRET = "sk-do-not-leak"  # noqa: S105 - fake key, must never be echoed


def _data_block(content: str, name: str) -> Any:
    match = re.search(rf"<<<DATA:{name}>>>\n(.*?)\n<<<END DATA:{name}>>>", content, re.DOTALL)
    return json.loads(match.group(1)) if match else None


def _service(provider: Any, **settings: Any) -> GeneratorService:
    return GeneratorService(provider=provider, settings=Settings(llm_provider="fake", **settings))


# A shop API shaped like benchmark ecommerce-order: checkout and coupons consume the cart
# built through /cart/items (another path root); every endpoint but the catalogue and
# /health is secured.
_SECURED: list[dict[str, Any]] = [{"bearerAuth": []}]
_LONG = "Detailed description of the behaviour of this endpoint. " * 12


def _ep(idx: int, method: str, path: str, **extra: Any) -> dict[str, Any]:
    ep: dict[str, Any] = {
        "id": idx,
        "method": method,
        "path": path,
        "summary": f"{method} {path}",
        "description": _LONG,
        "responses": {"200": {"description": "OK " + _LONG}, "401": {"description": "No"}},
    }
    if "{" in path:
        ep["parameters"] = [
            {
                "name": name,
                "in": "path",
                "required": True,
                "description": _LONG,
                "schema": {"type": "integer", "examples": [1]},
            }
            for name in re.findall(r"\{([^}]+)\}", path)
        ]
    ep.update(extra)
    return contract_endpoint(ep)


_BODY = {
    "required": True,
    "content": {
        "application/json": {
            "schema": {
                "type": "object",
                "description": _LONG,
                "properties": {
                    "product_id": {"type": "integer", "description": _LONG},
                    "quantity": {"type": "integer", "minimum": 1, "description": _LONG},
                    "code": {"type": "string", "description": _LONG},
                },
                "required": ["product_id"],
            }
        }
    },
}


def _shop_endpoints() -> list[dict[str, Any]]:
    return [
        _ep(1, "GET", "/health"),
        _ep(2, "GET", "/products"),
        _ep(3, "GET", "/products/{product_id}"),
        _ep(4, "GET", "/cart", security=_SECURED),
        _ep(5, "POST", "/cart/items", security=_SECURED, request_body=_BODY),
        _ep(6, "DELETE", "/cart/items/{item_id}", security=_SECURED),
        _ep(7, "POST", "/checkout", security=_SECURED, request_body=_BODY),
        _ep(8, "GET", "/orders", security=_SECURED),
        _ep(9, "GET", "/orders/{order_id}", security=_SECURED),
        _ep(10, "POST", "/orders/{order_id}/pay", security=_SECURED, request_body=_BODY),
        _ep(11, "POST", "/orders/{order_id}/cancel", security=_SECURED),
        _ep(12, "GET", "/admin/orders", security=_SECURED),
        _ep(13, "POST", "/coupons/apply", security=_SECURED, request_body=_BODY),
    ]


# Credential context of an experiment run (ADR-0006). Without one, a secured endpoint only
# gets AUTH cases (S14-06), so multi-step prompts for secured targets need it.
_SHOP_AUTH: dict[str, Any] = {
    "scheme": "bearer",
    "default_role": "customer",
    "roles": [{"name": "customer", "description": "CUSTOMER alice"}],
    "operations": [],
}


def _shop_request(
    strategy: str = "LLM", with_auth: bool = False, **options: Any
) -> GenerateRequest:
    payload: dict[str, Any] = {
        "job_id": 1,
        "strategy": strategy,
        "endpoints": _shop_endpoints(),
        "business_rules": [],
        "seed_cases": [],
        "options": contract_options(
            **{"max_cases_per_endpoint": 3, "max_repair_attempts": 2, **options}
        ),
    }
    if with_auth:
        payload["auth"] = _SHOP_AUTH
    if strategy == "HYBRID":
        seed = copy.deepcopy(VALID_DSL_CASE)
        seed.update(endpoint={"method": "GET", "path": "/products"})
        seed["steps"][0]["request"] = {"method": "GET", "path": "/products"}
        seed["steps"][0]["assertions"] = [{"type": "status", "equals": 200}]
        payload["seed_cases"] = [seed]
    return GenerateRequest.model_validate(payload)


def _endpoint(request: GenerateRequest, method: str, path: str) -> Any:
    return next(ep for ep in request.endpoints if (ep.method, ep.path) == (method, path))


# Finding: no test kept AI_LLM_API_KEY out of llm_calls (security-review proposal 5) -----


def _key_client(handler: Any) -> TestClient:
    app = create_app()
    settings = Settings(llm_provider="openai_compatible", llm_api_key=SECRET)
    app.dependency_overrides[get_settings] = lambda: settings
    app.dependency_overrides[get_provider] = lambda: OpenAICompatibleProvider(
        base_url="http://llm.test/v1",
        model="qwen2.5-coder:7b",
        api_key=SECRET,
        transport=httpx.MockTransport(handler),
    )
    return TestClient(app)


def _echo_401(request: httpx.Request) -> httpx.Response:
    # The upstream echoes the credentials it rejected.
    return httpx.Response(401, json={"error": f"bad {request.headers['Authorization']}"})


def _echoing_connect_error(request: httpx.Request) -> httpx.Response:
    raise httpx.ConnectError(f"proxy refused {request.headers['Authorization']}")


def _bad_body(request: httpx.Request) -> httpx.Response:
    return httpx.Response(200, json={"error": request.headers["Authorization"]})


@pytest.mark.parametrize("handler", [_echo_401, _echoing_connect_error, _bad_body])
def test_api_key_is_not_returned_in_502_llm_calls(handler: Any) -> None:
    response = _key_client(handler).post("/v1/generate", json=VALID_REQUEST_PAYLOAD)

    assert response.status_code == 502
    calls = response.json()["error"]["details"]["llm_calls"]
    assert calls and all(call["error"] for call in calls)
    assert SECRET not in response.text


def test_api_key_is_not_returned_in_200_llm_calls() -> None:
    def ok(request: httpx.Request) -> httpx.Response:
        assert request.headers["Authorization"] == f"Bearer {SECRET}"
        content = json.dumps({"test_cases": [VALID_DSL_CASE]})
        return httpx.Response(
            200,
            json={
                "model": "qwen2.5-coder:7b",
                "choices": [{"message": {"content": content}}],
                "usage": {"prompt_tokens": 10, "completion_tokens": 5},
            },
        )

    response = _key_client(ok).post("/v1/generate", json=VALID_REQUEST_PAYLOAD)

    assert response.status_code == 200
    assert response.json()["llm_calls"]
    assert SECRET not in response.text


def test_provider_error_redacts_the_key_and_url_query() -> None:
    def fail(request: httpx.Request) -> httpx.Response:
        raise httpx.ConnectError(f"failed {request.url}?api_key={SECRET} {SECRET}")

    provider = OpenAICompatibleProvider(
        base_url="http://llm.test/v1",
        model="m",
        api_key=SECRET,
        transport=httpx.MockTransport(fail),
    )
    with pytest.raises(LlmProviderError) as exc:
        provider.complete([ChatMessage(role="user", content="hi")], temperature=0.0)
    assert SECRET not in str(exc.value)
    # The technical text kept for llm_calls[].error (D09) is redacted too (S14-06).
    assert SECRET not in exc.value.detail
    assert "***" in exc.value.detail


# Finding: Python validator lacked the `matches` regex / `type` name / extract-path checks -


def _case_with_assertions(*assertions: dict[str, Any]) -> dict[str, Any]:
    case = copy.deepcopy(VALID_DSL_CASE)
    case["steps"][0]["assertions"] = [{"type": "status", "equals": 201}, *assertions]
    return case


@pytest.mark.parametrize(
    "assertion",
    [
        {"type": "json_path", "path": "$.name", "op": "matches", "value": r"^[A-Z]+-(\d{3}$"},
        {"type": "json_path", "path": "$.name", "op": "matches", "value": "("},
        {"type": "header", "name": "X-Id", "op": "matches", "value": "[a-"},
        {"type": "json_path", "path": "$.name", "op": "matches", "value": 5},
        {"type": "json_path", "path": "$.id", "op": "type", "value": "uuid"},
        {"type": "json_path", "path": "$.id", "op": "type", "value": "banana"},
        {"type": "json_path", "path": "$.id", "op": "type", "value": 1},
    ],
)
def test_unevaluable_operator_values_are_invalid(assertion: dict[str, Any]) -> None:
    result = DslValidator().validate(
        _case_with_assertions(assertion), spec=VALID_REQUEST_PAYLOAD["endpoints"]
    )
    assert result.is_valid is False
    assert any("assertion #2" in e for e in result.semantic_errors), result.errors


@pytest.mark.parametrize(
    "assertion",
    [
        {"type": "json_path", "path": "$.name", "op": "matches", "value": r"^[A-Z]+-\d{3}$"},
        {"type": "json_path", "path": "$.name", "op": "matches", "value": "{{pattern}}"},
        {"type": "json_path", "path": "$.id", "op": "type", "value": "Integer"},
        {"type": "json_path", "path": "$.id", "op": "type", "value": "null"},
    ],
)
def test_evaluable_operator_values_stay_valid(assertion: dict[str, Any]) -> None:
    case = _case_with_assertions(assertion)
    if "{{pattern}}" in json.dumps(assertion):
        case["variables"] = {"pattern": "^P"}
    result = DslValidator().validate(case, spec=VALID_REQUEST_PAYLOAD["endpoints"])
    assert result.is_valid, result.errors


def test_placeholder_in_extract_path_must_be_defined() -> None:
    case = copy.deepcopy(VALID_DSL_CASE)
    case["steps"][0]["extract"] = [{"var": "item_id", "from": "body", "path": "$.{{field}}"}]
    result = DslValidator().validate(case, spec=VALID_REQUEST_PAYLOAD["endpoints"])
    assert result.is_valid is False
    assert any("{{field}}" in e for e in result.semantic_errors)

    case["variables"] = {"field": "id"}
    assert DslValidator().validate(case, spec=VALID_REQUEST_PAYLOAD["endpoints"]).is_valid


@pytest.mark.parametrize(
    "path", sorted(EXAMPLES_DIR.glob("semantic-invalid-*.json")), ids=lambda p: p.name
)
def test_shared_semantic_invalid_fixtures_fail_semantic_validation(path: Path) -> None:
    """Same fixtures as the web DslValidatorTest: both validators must reject them."""
    result = DslValidator().validate(path.read_text(encoding="utf-8"))
    assert result.schema_errors == []
    assert result.is_valid is False
    assert result.semantic_errors


def test_shared_semantic_invalid_fixtures_exist() -> None:
    assert len(sorted(EXAMPLES_DIR.glob("semantic-invalid-*.json"))) >= 3


def test_bad_matches_pattern_from_the_llm_goes_to_repair() -> None:
    bad = _case_with_assertions(
        {"type": "json_path", "path": "$.name", "op": "matches", "value": "("}
    )
    fixed = _case_with_assertions(
        {"type": "json_path", "path": "$.name", "op": "matches", "value": "^W"}
    )
    provider = FakeLlmProvider([json.dumps({"test_cases": [bad]}), json.dumps(fixed)])

    response = _service(provider).generate(GenerateRequest.model_validate(VALID_REQUEST_PAYLOAD))

    (case,) = response.test_cases
    assert (case.status, case.repair_attempts) == ("VALID", 1)
    assert [c.purpose for c in response.llm_calls] == ["GENERATE", "REPAIR"]


# Finding: failed calls logged model = provider name instead of the requested model ------


class _FailingProvider:
    name = "openai_compatible"

    def __init__(self, responses: list[str | None]) -> None:
        self._responses = list(responses)

    def complete(self, messages: list[ChatMessage], **_: Any) -> LlmCompletion:
        content = self._responses.pop(0) if self._responses else None
        if content is None:
            raise LlmProviderError("timed out")
        return LlmCompletion(content=content, model="qwen2.5-coder:7b")


def test_failed_generate_call_logs_the_requested_default_model() -> None:
    payload = copy.deepcopy(VALID_REQUEST_PAYLOAD)
    payload["endpoints"].append(
        contract_endpoint({"id": 99, "method": "GET", "path": "/health", "responses": {"200": {}}})
    )
    payload["options"]["model"] = None
    provider = _FailingProvider([json.dumps({"test_cases": [VALID_DSL_CASE]}), None])

    response = _service(provider, llm_model="qwen2.5-coder:7b").generate(
        GenerateRequest.model_validate(payload)
    )

    failed = [c for c in response.llm_calls if c.error]
    assert len(failed) == 1
    assert failed[0].model == "qwen2.5-coder:7b"
    assert {c.model for c in response.llm_calls} == {"qwen2.5-coder:7b"}


def test_failed_generate_call_logs_options_model_when_given() -> None:
    payload = copy.deepcopy(VALID_REQUEST_PAYLOAD)
    payload["options"]["model"] = "llama3.1:8b"
    client = TestClient(create_app())
    app = client.app
    app.dependency_overrides[get_settings] = lambda: Settings(llm_provider="fake")  # type: ignore[attr-defined]
    app.dependency_overrides[get_provider] = lambda: _FailingProvider([None])  # type: ignore[attr-defined]

    response = client.post("/v1/generate", json=payload)

    assert response.status_code == 502
    (call,) = response.json()["error"]["details"]["llm_calls"]
    assert call["model"] == "llama3.1:8b"


# Finding: AUTH planned for public endpoints of a secured API ---------------------------


def test_auth_is_not_planned_for_a_public_endpoint_of_a_secured_api() -> None:
    request = _shop_request("HYBRID")
    health = _endpoint(request, "GET", "/health")
    checkout = _endpoint(request, "POST", "/checkout")

    assert "AUTH" not in technique_plan(request, health, 3, has_related=True)
    assert "AUTH" in technique_plan(request, checkout, 3, has_related=True)


# Finding: resource-root relatedness hid the producers of /checkout's cart --------------


def test_cross_root_producers_are_listed_for_checkout_and_coupons() -> None:
    request = _shop_request("HYBRID", with_auth=True)
    for path in ("/checkout", "/coupons/apply"):
        prompt = build_user_prompt(request, _endpoint(request, "POST", path), 3)
        related = _data_block(prompt, "related_endpoints")
        assert related is not None, path
        keys = [(r["method"], r["path"]) for r in related]
        assert ("POST", "/cart/items") in keys
        assert "SEQUENCE" in prompt


def test_generate_falls_back_to_resource_scope_when_all_endpoints_do_not_fit() -> None:
    request = _shop_request("HYBRID", with_auth=True)
    full = build_user_prompt(request, _endpoint(request, "POST", "/orders/{order_id}/pay"), 3)
    resource = build_user_prompt(
        request, _endpoint(request, "POST", "/orders/{order_id}/pay"), 3, "resource"
    )
    assert len(resource) < len(full)
    provider = FakeLlmProvider()

    def total(ep: Any, scope: str) -> int:
        return sum(len(m.content) for m in build_generation_messages(request, ep, 3, scope))

    # A limit every resource-scoped prompt fits but the full pay prompt does not.
    limit = max(total(ep, "resource") for ep in request.endpoints)
    pay = _endpoint(request, "POST", "/orders/{order_id}/pay")
    assert total(pay, "all") > limit
    service = _service(provider, max_prompt_chars=limit, llm_context_tokens=131072)

    service.generate(request)

    for call in provider.calls:
        assert sum(len(m.content) for m in call) <= limit
    pay_call = next(c for c in provider.calls if '"/orders/{order_id}/pay"' in c[1].content)
    related = _data_block(pay_call[1].content, "related_endpoints")
    assert related and all(r["path"].startswith("/orders") for r in related)


# Finding: unusable-endpoint bucket silently dropped failed endpoint batches ------------


def test_every_unparseable_endpoint_answer_is_returned_as_invalid() -> None:
    request = _shop_request("LLM", max_repair_attempts=0)
    cut = '{"test_cases": [{"dsl_version": "1.0", "title": "cut'
    provider = FakeLlmProvider([cut] * 13)

    response = _service(provider).generate(request)

    assert [c.purpose for c in response.llm_calls] == ["GENERATE"] * 13
    assert len(response.test_cases) == 13
    assert response.stats.generated == 13
    assert response.stats.invalid == 13
    skipped = [
        tc for tc in response.test_cases if any("hết lượt sửa" in e for e in tc.validation_errors)
    ]
    assert len(skipped) == 10
    assert all(tc.repair_attempts == 0 for tc in skipped)


def test_unusable_bucket_still_bounds_repair_calls() -> None:
    request = _shop_request("LLM", max_repair_attempts=1)
    cut = '{"test_cases": [{"dsl_version": "1.0", "title": "cut'
    provider = FakeLlmProvider([cut] * 13 + ["not json"] * 20)

    response = _service(provider).generate(request)

    assert sum(c.purpose == "REPAIR" for c in response.llm_calls) == 3
    assert len(response.test_cases) == 13


# Finding: repair prompt dumped the whole uncompacted spec ------------------------------


def test_cut_off_answer_on_a_large_spec_is_repaired_with_its_target_endpoint() -> None:
    request = _shop_request("LLM", max_repair_attempts=1)
    # ~4.8k-character cut-off answer for the first endpoint (GET /health).
    case = copy.deepcopy(VALID_DSL_CASE)
    case["description"] = "x" * 4700
    cut = json.dumps({"test_cases": [case]})[:4800]
    provider = FakeLlmProvider([cut] + ['{"test_cases": []}'] * 12 + ["{}"])

    response = _service(provider).generate(request)

    repairs = [c for c in response.llm_calls if c.purpose == "REPAIR"]
    assert len(repairs) == 1, [tc.validation_errors for tc in response.test_cases]
    user = repairs[0].prompt[1].content
    target = _data_block(user, "endpoints")
    assert (target["method"], target["path"]) == ("GET", "/health")
    # Compact views only: no descriptions of other endpoints, no annotations.
    assert "examples" not in user
    related = _data_block(user, "related_endpoints")
    assert len(related) == 12
    assert all("description" not in r for r in related)
    assert len(user) < 12000


# Finding: digit-only path segments became ints, losing leading zeros -------------------


_CODES: list[dict[str, Any]] = [
    {
        "method": "GET",
        "path": "/codes/{code}",
        "parameters": [{"name": "code", "in": "path", "schema": {"type": "string"}}],
    },
    {
        "method": "GET",
        "path": "/items/{id}",
        "parameters": [{"name": "id", "in": "path", "schema": {"type": "integer"}}],
    },
    {"method": "GET", "path": "/raw/{x}"},
]


@pytest.mark.parametrize(
    ("path", "expected"),
    [
        ("/codes/00042", {"code": "00042"}),
        ("/codes/42", {"code": "42"}),
        ("/items/42", {"id": 42}),
        ("/items/007", {"id": "007"}),
        ("/items/-0", {"id": "-0"}),
        ("/raw/7", {"x": 7}),
        ("/raw/0042", {"x": "0042"}),
    ],
)
def test_path_values_keep_their_declared_type(path: str, expected: dict[str, Any]) -> None:
    dsl: dict[str, Any] = {
        "endpoint": {"method": "GET", "path": path},
        "steps": [{"request": {"method": "GET", "path": path}}],
    }
    normalize_request_paths(dsl, _CODES)
    assert dsl["steps"][0]["request"]["path_params"] == expected


# Finding: REPAIR answers cut off at max_tokens=1056 looked like plain repair failures ---
# max_tokens itself is the ADR-0005 protocol value and is not changed here (see PR notes).

_REPAIR_MAX_TOKENS = 2 * Settings().llm_output_tokens_per_case + 256


class _CutRepairProvider:
    """GENERATE answers an invalid case; every REPAIR answer is cut at max_tokens."""

    name = "openai_compatible"

    def __init__(self, generate: str, finish_reason: str | None, tokens: int) -> None:
        self._generate = generate
        self._finish_reason = finish_reason
        self._tokens = tokens
        self.max_tokens: list[int | None] = []

    def complete(
        self, messages: list[ChatMessage], *, max_tokens: int | None = None, **_: Any
    ) -> LlmCompletion:
        self.max_tokens.append(max_tokens)
        if len(self.max_tokens) == 1:
            return LlmCompletion(content=self._generate, model="m")
        choice: dict[str, Any] = {"message": {"content": "{}"}}
        if self._finish_reason is not None:
            choice["finish_reason"] = self._finish_reason
        return LlmCompletion(
            content='{"dsl_version": "1.0", "steps": [{"assertions": [{"schema": {"properties":',
            model="m",
            completion_tokens=self._tokens,
            raw={"choices": [choice]},
        )


@pytest.mark.parametrize(("finish_reason", "tokens"), [("length", 900), (None, _REPAIR_MAX_TOKENS)])
def test_repair_answer_cut_at_max_tokens_is_logged_and_not_retried(
    finish_reason: str | None, tokens: int
) -> None:
    bad = copy.deepcopy(VALID_DSL_CASE)
    bad["steps"][0]["assertions"].append({"type": "status", "equals": 299})
    provider = _CutRepairProvider(json.dumps({"test_cases": [bad]}), finish_reason, tokens)

    response = _service(provider).generate(GenerateRequest.model_validate(VALID_REQUEST_PAYLOAD))

    repairs = [c for c in response.llm_calls if c.purpose == "REPAIR"]
    # max_repair_attempts is 2, but a second call would be cut at the same limit.
    assert len(repairs) == 1
    assert provider.max_tokens[1] == _REPAIR_MAX_TOKENS
    assert repairs[0].error is not None and "max_tokens" in repairs[0].error
    (case,) = response.test_cases
    assert case.status == "INVALID"
    assert any("giới hạn token đầu ra" in e for e in case.validation_errors)
    # Users see no configuration names; the call log keeps the technical reason (D09).
    assert not any("AI_LLM_" in e for e in case.validation_errors)


def test_complete_repair_answer_is_not_flagged() -> None:
    bad = copy.deepcopy(VALID_DSL_CASE)
    bad["steps"][0]["assertions"].append({"type": "status", "equals": 299})
    provider = FakeLlmProvider([json.dumps({"test_cases": [bad]}), json.dumps(VALID_DSL_CASE)])

    response = _service(provider).generate(GenerateRequest.model_validate(VALID_REQUEST_PAYLOAD))

    assert all(c.error is None for c in response.llm_calls)
    assert response.test_cases[0].status == "VALID"
