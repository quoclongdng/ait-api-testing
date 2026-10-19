"""Tests for DslExecutor step execution, variable extraction, and assertions."""

from benchmark.runner.dsl_executor import DslExecutor, _extract_json_path, _resolve_template
from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse
from fastapi.testclient import TestClient


def test_template_resolution() -> None:
    variables = {"token": "secret123", "id": 42}
    template = {
        "headers": {"Authorization": "Bearer {{token}}"},
        "path": "/items/{{id}}",
        "exact_id": "{{id}}",
    }
    resolved = _resolve_template(template, variables)
    assert resolved["headers"]["Authorization"] == "Bearer secret123"
    assert resolved["path"] == "/items/42"
    assert resolved["exact_id"] == 42


def test_json_path_extraction() -> None:
    data = {"user": {"profile": {"name": "Alice"}}, "items": [10, 20, 30]}
    assert _extract_json_path(data, "$.user.profile.name") == "Alice"
    assert _extract_json_path(data, "$.items[1]") == 20
    assert _extract_json_path(data, "$") == data
    assert _extract_json_path(data, "$.missing") is None


def test_executor_passes_valid_test_case() -> None:
    app = FastAPI()

    @app.post("/items")
    async def create_item(req: Request) -> JSONResponse:
        body = await req.json()
        return JSONResponse(
            status_code=201,
            content={"id": 99, "name": body.get("name")},
            headers={"X-Custom": "test-header"},
        )

    @app.get("/items/{item_id}")
    async def get_item(item_id: int) -> JSONResponse:
        return JSONResponse(status_code=200, content={"id": item_id, "name": "Item 99"})

    client = TestClient(app)
    executor = DslExecutor()

    case = {
        "id": "TC-CREATE-GET",
        "title": "Create and read item sequence",
        "technique": "SEQUENCE",
        "steps": [
            {
                "name": "create item",
                "request": {
                    "method": "POST",
                    "path": "/items",
                    "body": {"name": "Pen"},
                },
                "extract": [
                    {"var": "created_id", "from": "body", "path": "$.id"},
                    {"var": "hdr_val", "from": "header", "name": "x-custom"},
                    {"var": "st_code", "from": "status"},
                ],
                "assertions": [
                    {"type": "status", "equals": 201},
                    {
                        "type": "header",
                        "header": "X-Custom",
                        "op": "equals",
                        "value": "test-header",
                    },
                    {"type": "json_path", "path": "$.name", "op": "equals", "value": "Pen"},
                ],
            },
            {
                "name": "get created item",
                "request": {
                    "method": "GET",
                    "path": "/items/{{created_id}}",
                },
                "assertions": [
                    {"type": "status", "equals": 200},
                    {
                        "type": "json_path",
                        "path": "$.id",
                        "op": "equals",
                        "value": "{{created_id}}",
                    },
                ],
            },
        ],
    }

    result = executor.execute_test_case(case, client)
    assert result.verdict == "PASS"
    assert len(result.steps) == 2
    assert result.steps[0].passed
    assert result.steps[1].passed


def test_executor_fails_when_assertion_fails() -> None:
    app = FastAPI()

    @app.get("/status")
    async def get_status() -> JSONResponse:
        return JSONResponse(status_code=500, content={"error": "server error"})

    client = TestClient(app)
    executor = DslExecutor()

    case = {
        "id": "TC-FAIL",
        "title": "Expect 200 but got 500",
        "technique": "HAPPY_PATH",
        "steps": [
            {
                "name": "get status",
                "request": {"method": "GET", "path": "/status"},
                "assertions": [{"type": "status", "equals": 200}],
            }
        ],
    }

    result = executor.execute_test_case(case, client)
    assert result.verdict == "FAIL"
    assert not result.steps[0].passed
    assert "Expected status 200, got 500" in result.steps[0].error


def test_json_path_strict_equality_and_numeric_comparisons() -> None:
    app = FastAPI()

    @app.get("/data")
    async def get_data() -> JSONResponse:
        return JSONResponse(
            status_code=200,
            content={
                "str_one": "1",
                "int_one": 1,
                "score": 85.5,
                "tags": ["1", "admin"],
                "ids": [1, 2],
            },
        )

    client = TestClient(app)
    executor = DslExecutor()

    # 1. "1" eq 1 must FAIL (type-strict JSON equality)
    fail_case = {
        "title": "Strict eq test",
        "steps": [
            {
                "name": "check strict eq",
                "request": {"method": "GET", "path": "/data"},
                "assertions": [
                    {"type": "json_path", "path": "$.str_one", "op": "eq", "value": 1},
                ],
            }
        ],
    }
    res_fail = executor.execute_test_case(fail_case, client)
    assert res_fail.verdict == "FAIL"

    # 2. ["1"] contains 1 must FAIL, [1] contains 1 must PASS
    contains_fail_case = {
        "title": "Strict contains test",
        "steps": [
            {
                "name": "check strict contains",
                "request": {"method": "GET", "path": "/data"},
                "assertions": [
                    {"type": "json_path", "path": "$.tags", "op": "contains", "value": 1},
                ],
            }
        ],
    }
    assert executor.execute_test_case(contains_fail_case, client).verdict == "FAIL"

    # 3. Numeric comparisons gt, gte, lt, lte
    numeric_pass_case = {
        "title": "Numeric comparisons",
        "steps": [
            {
                "name": "check comparisons",
                "request": {"method": "GET", "path": "/data"},
                "assertions": [
                    {"type": "json_path", "path": "$.score", "op": "gt", "value": 80},
                    {"type": "json_path", "path": "$.score", "op": "gte", "value": 85.5},
                    {"type": "json_path", "path": "$.score", "op": "lt", "value": 90},
                    {"type": "json_path", "path": "$.score", "op": "lte", "value": 85.5},
                    {"type": "json_path", "path": "$.ids", "op": "contains", "value": 1},
                ],
            }
        ],
    }
    assert executor.execute_test_case(numeric_pass_case, client).verdict == "PASS"


def test_json_path_present_null_vs_missing() -> None:
    app = FastAPI()

    @app.get("/null-data")
    async def get_null_data() -> JSONResponse:
        return JSONResponse(status_code=200, content={"present_null": None})

    client = TestClient(app)
    executor = DslExecutor()

    # present_null exists -> PASS, not_exists -> FAIL
    pass_case = {
        "title": "Present null exists",
        "steps": [
            {
                "name": "check present null",
                "request": {"method": "GET", "path": "/null-data"},
                "assertions": [
                    {"type": "json_path", "path": "$.present_null", "op": "exists"},
                    {"type": "json_path", "path": "$.missing_field", "op": "not_exists"},
                ],
            }
        ],
    }
    assert executor.execute_test_case(pass_case, client).verdict == "PASS"

    fail_case = {
        "title": "Present null not_exists must fail",
        "steps": [
            {
                "name": "check present null not_exists",
                "request": {"method": "GET", "path": "/null-data"},
                "assertions": [
                    {"type": "json_path", "path": "$.present_null", "op": "not_exists"},
                ],
            }
        ],
    }
    assert executor.execute_test_case(fail_case, client).verdict == "FAIL"


def test_json_path_strict_boolean_type_check() -> None:
    app = FastAPI()

    @app.get("/bool-data")
    async def get_bool_data() -> JSONResponse:
        return JSONResponse(status_code=200, content={"flag": True})

    client = TestClient(app)
    executor = DslExecutor()

    # flag: true is boolean, MUST NOT pass as integer despite Python isinstance(True, int)
    int_fail_case = {
        "title": "Boolean as integer must fail",
        "steps": [
            {
                "name": "check bool as int",
                "request": {"method": "GET", "path": "/bool-data"},
                "assertions": [
                    {"type": "json_path", "path": "$.flag", "op": "type", "value": "integer"},
                ],
            }
        ],
    }
    assert executor.execute_test_case(int_fail_case, client).verdict == "FAIL"

    bool_pass_case = {
        "title": "Boolean as boolean must pass",
        "steps": [
            {
                "name": "check bool as bool",
                "request": {"method": "GET", "path": "/bool-data"},
                "assertions": [
                    {"type": "json_path", "path": "$.flag", "op": "type", "value": "boolean"},
                ],
            }
        ],
    }
    assert executor.execute_test_case(bool_pass_case, client).verdict == "PASS"


def test_header_contract_operators_and_name_field() -> None:
    app = FastAPI()

    @app.get("/hdr")
    async def get_hdr() -> JSONResponse:
        return JSONResponse(
            status_code=200,
            content={"ok": True},
            headers={"Content-Type": "application/json", "X-Request-ID": "req-12345"},
        )

    client = TestClient(app)
    executor = DslExecutor()

    pass_case = {
        "title": "Header contract operators",
        "steps": [
            {
                "name": "check header operators",
                "request": {"method": "GET", "path": "/hdr"},
                "assertions": [
                    {
                        "type": "header",
                        "name": "Content-Type",
                        "op": "eq",
                        "value": "application/json",
                    },
                    {"type": "header", "name": "X-Request-ID", "op": "contains", "value": "12345"},
                    {
                        "type": "header",
                        "name": "X-Request-ID",
                        "op": "matches",
                        "value": "^req-\\d+$",
                    },
                    {"type": "header", "name": "Content-Type", "op": "exists"},
                    {"type": "header", "name": "X-Non-Existent", "op": "not_exists"},
                ],
            }
        ],
    }
    assert executor.execute_test_case(pass_case, client).verdict == "PASS"
