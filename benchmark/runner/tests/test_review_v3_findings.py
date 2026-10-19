"""Regression tests verifying PR #92 Review V3 findings.

Findings covered:
1. Provenance validation: RULE/LLM/HYBRID reject mismatched sources and fail run.
2. D04 Semantic validation: Rejects unknown body field, 599 status, and missing vars.
3. Extraction failure halts execution: Missing JSONPath/header yields ERROR verdict.
4. Repetition mapping: Supports string/integer keys, validates repetitions 1..N.
5. DEBUG env isolation: Runner and benchmark adapters remain resilient when DEBUG=release.
"""

from __future__ import annotations

from typing import Any
from unittest.mock import MagicMock

import pytest
from benchmark.runner.adapter import BenchmarkAdapter
from benchmark.runner.dsl_executor import DslExecutor
from benchmark.runner.experiment_runner import ExperimentRunner
from benchmark.runner.models import ExperimentConfig, TestCaseResult
from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse
from fastapi.testclient import TestClient


# ==============================================================================
# Finding 1: Provenance Validation for RULE, LLM, HYBRID
# ==============================================================================
def test_finding_1_provenance_validation_rejects_mismatched_source() -> None:
    """Runner must conclude run as FAILED when test case source does not match strategy."""
    config = ExperimentConfig(
        name="test-prov-rule",
        benchmark="simple-store",
        strategy="RULE",
        repetitions=1,
    )
    runner = ExperimentRunner(config)

    # Suite has LLM case while strategy is RULE
    mismatched_case = {
        "id": "c-llm-1",
        "dsl_version": "1.0",
        "title": "LLM Generated Case",
        "endpoint": {"method": "GET", "path": "/products"},
        "technique": "HAPPY_PATH",
        "source": "LLM",
        "steps": [
            {
                "name": "step 1",
                "request": {"method": "GET", "path": "/products"},
                "assertions": [{"type": "status", "equals": 200}],
            }
        ],
    }

    result = runner.run(test_cases_by_repetition=[mismatched_case])
    assert result.status == "FAILED"
    err = str(result.metrics.get("error"))
    assert "Provenance mismatch" in err
    assert "Configured strategy is 'RULE'" in err
    assert "source='LLM'" in err


def test_finding_1_provenance_validation_accepts_matching_source() -> None:
    """Runner accepts test cases with matching provenance."""
    for strategy in ("RULE", "LLM", "HYBRID"):
        config = ExperimentConfig(
            name=f"test-prov-{strategy}",
            benchmark="simple-store",
            strategy=strategy,
            repetitions=1,
        )
        runner = ExperimentRunner(config)
        runner.adapter.bugs = runner.adapter.bugs[:1]

        mock_res = TestCaseResult(case_id="c-1", title="c1", technique="T", verdict="PASS")
        runner.executor.execute_test_case = MagicMock(return_value=mock_res)

        matching_case = {
            "id": "c-1",
            "dsl_version": "1.0",
            "title": f"Valid {strategy} Case",
            "endpoint": {"method": "GET", "path": "/products"},
            "technique": "HAPPY_PATH",
            "source": strategy,
            "steps": [
                {
                    "name": "step 1",
                    "request": {"method": "GET", "path": "/products"},
                    "assertions": [{"type": "status", "equals": 200}],
                }
            ],
        }

        result = runner.run(test_cases_by_repetition=[matching_case])
        assert result.status == "COMPLETED"


# ==============================================================================
# Finding 2: D04 Semantic Validation
# ==============================================================================
def test_finding_2_semantic_validation_rejects_unknown_body_field() -> None:
    """Case with undeclared body field must fail semantic validation and be filtered out."""
    config = ExperimentConfig(benchmark="simple-store", strategy="RULE", repetitions=1)
    runner = ExperimentRunner(config)

    # POST /products with undeclared 'extra_unknown_field'
    invalid_case = {
        "id": "c-unknown-body",
        "dsl_version": "1.0",
        "title": "Unknown Body Field Case",
        "endpoint": {"method": "POST", "path": "/products"},
        "technique": "BOUNDARY",
        "source": "RULE",
        "steps": [
            {
                "name": "create with unknown field",
                "request": {
                    "method": "POST",
                    "path": "/products",
                    "headers": {"Content-Type": "application/json"},
                    "body": {
                        "name": "Widget",
                        "price": 10.0,
                        "extra_unknown_field": "disallowed",
                    },
                },
                "assertions": [{"type": "status", "equals": 201}],
            }
        ],
    }

    valid_cases = runner._validate_and_dedup_cases([invalid_case])
    assert len(valid_cases) == 0


def test_finding_2_semantic_validation_rejects_undeclared_status_599() -> None:
    """Case asserting undeclared status 599 must fail semantic validation and be filtered out."""
    config = ExperimentConfig(benchmark="simple-store", strategy="RULE", repetitions=1)
    runner = ExperimentRunner(config)

    invalid_case = {
        "id": "c-status-599",
        "dsl_version": "1.0",
        "title": "Undeclared Status 599 Case",
        "endpoint": {"method": "GET", "path": "/products"},
        "technique": "HAPPY_PATH",
        "source": "RULE",
        "steps": [
            {
                "name": "get products expecting 599",
                "request": {"method": "GET", "path": "/products"},
                "assertions": [{"type": "status", "equals": 599}],
            }
        ],
    }

    valid_cases = runner._validate_and_dedup_cases([invalid_case])
    assert len(valid_cases) == 0


def test_finding_2_semantic_validation_rejects_unextracted_variable() -> None:
    """Case referencing unextracted {{missing_var}} must fail semantic validation."""
    config = ExperimentConfig(benchmark="simple-store", strategy="RULE", repetitions=1)
    runner = ExperimentRunner(config)

    invalid_case = {
        "id": "c-unextracted-var",
        "dsl_version": "1.0",
        "title": "Unextracted Variable Case",
        "endpoint": {"method": "GET", "path": "/products/{id}"},
        "technique": "HAPPY_PATH",
        "source": "RULE",
        "steps": [
            {
                "name": "get product with missing variable",
                "request": {
                    "method": "GET",
                    "path": "/products/{id}",
                    "path_params": {"id": "{{missing_var}}"},
                },
                "assertions": [{"type": "status", "equals": 200}],
            }
        ],
    }

    valid_cases = runner._validate_and_dedup_cases([invalid_case])
    assert len(valid_cases) == 0


# ==============================================================================
# Finding 3: Extraction Failure Halts Execution with ERROR Verdict
# ==============================================================================
def test_finding_3_extraction_missing_jsonpath_halts_case_with_error() -> None:
    """Missing JSONPath triggers extraction error, halts execution, and reports ERROR verdict."""
    app = FastAPI()
    step2_called = False

    @app.post("/step1")
    async def step1():
        return {"data": "ok"}

    @app.post("/step2")
    async def step2():
        nonlocal step2_called
        step2_called = True
        return {"status": "ok"}

    client = TestClient(app)
    executor = DslExecutor()

    case = {
        "id": "test-jsonpath-missing",
        "title": "Missing JSONPath Extraction",
        "technique": "SEQUENCE",
        "steps": [
            {
                "name": "step 1",
                "request": {"method": "POST", "path": "/step1"},
                "extract": [{"var": "my_id", "from": "body", "path": "$.missing_token"}],
                "assertions": [{"type": "status", "equals": 200}],
            },
            {
                "name": "step 2",
                "request": {"method": "POST", "path": "/step2"},
                "assertions": [{"type": "status", "equals": 200}],
            },
        ],
    }

    result = executor.execute_test_case(case, client)
    assert result.verdict == "ERROR"
    assert "Extraction error: JSONPath '$.missing_token' not found" in str(result.error)
    assert len(result.steps) == 1
    assert step2_called is False


def test_finding_3_extraction_missing_header_halts_case_with_error() -> None:
    """Missing Header triggers extraction error, halts execution, and reports ERROR verdict."""
    app = FastAPI()
    step2_called = False

    @app.post("/step1")
    async def step1():
        return JSONResponse(status_code=200, content={"status": "ok"})

    @app.post("/step2")
    async def step2():
        nonlocal step2_called
        step2_called = True
        return {"status": "ok"}

    client = TestClient(app)
    executor = DslExecutor()

    case = {
        "id": "test-header-missing",
        "title": "Missing Header Extraction",
        "technique": "SEQUENCE",
        "steps": [
            {
                "name": "step 1",
                "request": {"method": "POST", "path": "/step1"},
                "extract": [{"var": "auth_hdr", "from": "header", "path": "x-nonexistent-token"}],
                "assertions": [{"type": "status", "equals": 200}],
            },
            {
                "name": "step 2",
                "request": {"method": "POST", "path": "/step2"},
                "assertions": [{"type": "status", "equals": 200}],
            },
        ],
    }

    result = executor.execute_test_case(case, client)
    assert result.verdict == "ERROR"
    assert "Extraction error: Header 'x-nonexistent-token' not found" in str(result.error)
    assert len(result.steps) == 1
    assert step2_called is False


def test_finding_3_extraction_present_null_succeeds_and_continues() -> None:
    """Present null value in JSON is successfully extracted as None and case proceeds to step 2."""
    app = FastAPI()
    step2_received_var = None

    @app.post("/step1")
    async def step1():
        return {"optional_flag": None}

    @app.post("/step2")
    async def step2(req: Request):
        nonlocal step2_received_var
        body = await req.json()
        step2_received_var = body.get("flag")
        return {"status": "success"}

    client = TestClient(app)
    executor = DslExecutor()

    case = {
        "id": "test-present-null",
        "title": "Present Null Extraction",
        "technique": "SEQUENCE",
        "steps": [
            {
                "name": "step 1",
                "request": {"method": "POST", "path": "/step1"},
                "extract": [{"var": "null_var", "from": "body", "path": "$.optional_flag"}],
                "assertions": [{"type": "status", "equals": 200}],
            },
            {
                "name": "step 2",
                "request": {
                    "method": "POST",
                    "path": "/step2",
                    "body": {"flag": "{{null_var}}"},
                },
                "assertions": [{"type": "status", "equals": 200}],
            },
        ],
    }

    result = executor.execute_test_case(case, client)
    assert result.verdict == "PASS"
    assert len(result.steps) == 2


# ==============================================================================
# Finding 4: Suite Mapping by Repetition
# ==============================================================================
def test_finding_4_suite_mapping_supports_string_and_int_keys() -> None:
    """Mapping with string keys (from JSON file) or integer keys is correctly resolved."""
    config = ExperimentConfig(benchmark="simple-store", strategy="RULE", repetitions=2)
    runner = ExperimentRunner(config)
    runner.adapter.bugs = runner.adapter.bugs[:1]

    mock_res = TestCaseResult(case_id="c-1", title="c1", technique="T", verdict="PASS")
    runner.executor.execute_test_case = MagicMock(return_value=mock_res)

    case_rep1 = {
        "id": "c-1",
        "dsl_version": "1.0",
        "title": "Rep 1 Case",
        "endpoint": {"method": "GET", "path": "/products"},
        "technique": "HAPPY_PATH",
        "source": "RULE",
        "steps": [
            {
                "name": "s1",
                "request": {"method": "GET", "path": "/products"},
                "assertions": [{"type": "status", "equals": 200}],
            }
        ],
    }
    case_rep2 = {
        "id": "c-2",
        "dsl_version": "1.0",
        "title": "Rep 2 Case",
        "endpoint": {"method": "GET", "path": "/categories"},
        "technique": "HAPPY_PATH",
        "source": "RULE",
        "steps": [
            {
                "name": "s2",
                "request": {"method": "GET", "path": "/categories"},
                "assertions": [{"type": "status", "equals": 200}],
            }
        ],
    }

    # String keys (JSON object deserialization format)
    string_key_mapping = {"1": [case_rep1], "2": [case_rep2]}
    res_str = runner.run(test_cases_by_repetition=string_key_mapping)
    assert res_str.status == "COMPLETED"
    assert len(res_str.repetitions_data) == 2

    # Integer keys
    int_key_mapping = {1: [case_rep1], 2: [case_rep2]}
    res_int = runner.run(test_cases_by_repetition=int_key_mapping)
    assert res_int.status == "COMPLETED"
    assert len(res_int.repetitions_data) == 2


def test_finding_4_suite_mapping_missing_repetition_fails() -> None:
    """When suite mapping lacks a required repetition, run concludes as FAILED."""
    config = ExperimentConfig(benchmark="simple-store", strategy="RULE", repetitions=3)
    runner = ExperimentRunner(config)

    incomplete_mapping = {"1": [], "2": []}  # Missing rep 3
    result = runner.run(test_cases_by_repetition=incomplete_mapping)
    assert result.status == "FAILED"
    assert "missing repetition 3" in str(result.metrics.get("error"))


def test_finding_4_suite_mapping_non_list_value_fails() -> None:
    """When suite mapping entry is not a list of test cases, run concludes as FAILED."""
    config = ExperimentConfig(benchmark="simple-store", strategy="RULE", repetitions=1)
    runner = ExperimentRunner(config)

    invalid_mapping = {"1": "not-a-list"}
    result = runner.run(test_cases_by_repetition=invalid_mapping)
    assert result.status == "FAILED"
    assert "must be a list of cases" in str(result.metrics.get("error"))


# ==============================================================================
# Finding 5: DEBUG Environment Variable Isolation
# ==============================================================================
def test_finding_5_debug_env_release_does_not_crash_runner(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """Setting DEBUG=release must not crash benchmark adapter or ExperimentRunner startup."""
    monkeypatch.setenv("DEBUG", "release")

    for benchmark_slug in ("simple-store", "ecommerce-order", "appointment-booking"):
        adapter = BenchmarkAdapter(benchmark_slug)
        assert adapter.app is not None
        assert adapter.settings is not None

    config = ExperimentConfig(benchmark="simple-store", strategy="WITNESS", repetitions=1)
    runner = ExperimentRunner(config)
    assert runner.adapter.app is not None


# ==============================================================================
# Finding 6: Escaped Quoted-Key JSONPath and Selector Syntax Validation
# ==============================================================================
def test_finding_6_escaped_quoted_members_positive_and_not_exists() -> None:
    """Escaped JSONPath keys resolve to literal members and prevent false PASS on not_exists."""
    executor = DslExecutor()

    # Case 1: unicode escape $["a\u002eb"] matches key "a.b"
    client_unicode = MagicMock()
    client_unicode.request.return_value = MagicMock(
        status_code=200,
        headers={"content-type": "application/json"},
        json=lambda: {"a.b": 1},
    )
    dsl_unicode_eq = {
        "id": "tc-unicode-eq",
        "title": "Unicode positive",
        "technique": "HAPPY_PATH",
        "steps": [
            {
                "name": "check",
                "request": {"method": "GET", "path": "/test"},
                "assertions": [
                    {"type": "json_path", "path": '$["a\\u002eb"]', "op": "eq", "value": 1}
                ],
            }
        ],
    }
    res_eq = executor.execute_test_case(dsl_unicode_eq, client_unicode)
    assert res_eq.verdict == "PASS"

    # Case 2: same selector with not_exists must FAIL because "a.b" exists
    dsl_unicode_not_exists = {
        "id": "tc-unicode-ne",
        "title": "Unicode not exists",
        "technique": "HAPPY_PATH",
        "steps": [
            {
                "name": "check",
                "request": {"method": "GET", "path": "/test"},
                "assertions": [{"type": "json_path", "path": '$["a\\u002eb"]', "op": "not_exists"}],
            }
        ],
    }
    res_ne = executor.execute_test_case(dsl_unicode_not_exists, client_unicode)
    assert res_ne.verdict == "FAIL"

    # Case 3: single quote escape $['a\'b'] matches key "a'b"
    client_quote = MagicMock()
    client_quote.request.return_value = MagicMock(
        status_code=200,
        headers={"content-type": "application/json"},
        json=lambda: {"a'b": 1},
    )
    dsl_quote = {
        "id": "tc-quote-eq",
        "title": "Quote positive",
        "technique": "HAPPY_PATH",
        "steps": [
            {
                "name": "check",
                "request": {"method": "GET", "path": "/test"},
                "assertions": [{"type": "json_path", "path": "$['a\\'b']", "op": "eq", "value": 1}],
            }
        ],
    }
    res_q = executor.execute_test_case(dsl_quote, client_quote)
    assert res_q.verdict == "PASS"


def test_finding_6_invalid_jsonpath_syntax_rejected_without_false_pass() -> None:
    """Invalid JSONPath selectors fail explicitly rather than falsely passing not_exists."""
    executor = DslExecutor()
    client = MagicMock()
    client.request.return_value = MagicMock(
        status_code=200,
        headers={"content-type": "application/json"},
        json=lambda: {"a": 1},
    )

    dsl_invalid = {
        "id": "tc-invalid-path",
        "title": "Invalid selector not_exists",
        "technique": "HAPPY_PATH",
        "steps": [
            {
                "name": "check",
                "request": {"method": "GET", "path": "/test"},
                "assertions": [
                    {"type": "json_path", "path": '$["a\\invalid"]', "op": "not_exists"}
                ],
            }
        ],
    }
    res = executor.execute_test_case(dsl_invalid, client)
    assert res.verdict == "FAIL"
    assert "invalid or outside supported syntax" in (res.error or "")


# ==============================================================================
# Finding 7: Case-Insensitive Content-Type Preservation on Wire
# ==============================================================================
@pytest.mark.parametrize(
    "header_key",
    ["Content-Type", "content-type", "CONTENT-TYPE", "cOnTeNt-TyPe"],
)
@pytest.mark.parametrize("has_body", [False, True], ids=["no_body", "with_body"])
def test_finding_7_content_type_preserved_case_insensitively(
    header_key: str, has_body: bool
) -> None:
    """Custom content-type must not be duplicated by default header regardless of casing."""
    app = FastAPI()

    @app.post("/echo")
    async def echo(request: Request) -> JSONResponse:
        return JSONResponse({"media": request.headers.getlist("content-type")})

    client = TestClient(app)
    executor = DslExecutor()

    custom_type = "application/vnd.review+json"
    body_val = {"name": "Test"} if has_body else None
    dsl = {
        "id": f"tc-ct-{header_key}-{has_body}",
        "title": "Content-Type casing test",
        "technique": "HAPPY_PATH",
        "endpoint": {"method": "POST", "path": "/echo"},
        "steps": [
            {
                "name": "post_echo",
                "request": {
                    "method": "POST",
                    "path": "/echo",
                    "headers": {header_key: custom_type},
                    "body": body_val,
                },
                "assertions": [
                    {
                        "type": "json_path",
                        "path": "$.media",
                        "op": "eq",
                        "value": [custom_type],
                    }
                ],
            }
        ],
    }
    result = executor.execute_test_case(dsl, client)
    assert result.verdict == "PASS", result.error


# ==============================================================================
# Finding 8: OpenAPI Literal Path Dot Regex Escaping in Semantic Gate
# ==============================================================================
def test_finding_8_openapi_literal_path_dot_escaping() -> None:
    """Literal dot in OpenAPI paths must not act as a regex wildcard matching other characters."""
    import copy

    spec = {
        "openapi": "3.1.0",
        "info": {"title": "Literal dot fixture", "version": "1.0"},
        "paths": {
            "/v1.0/items/{id}": {
                "get": {
                    "parameters": [
                        {
                            "name": "id",
                            "in": "path",
                            "required": True,
                            "schema": {"type": "integer"},
                        }
                    ],
                    "responses": {"200": {"description": "OK"}, "404": {"description": "NF"}},
                }
            }
        },
    }

    app = FastAPI()

    @app.get("/v1.0/items/{id}")
    async def get_item(id: int) -> dict[str, int]:
        return {"id": id}

    runner = ExperimentRunner(
        ExperimentConfig(benchmark="simple-store", strategy="RULE", repetitions=1)
    )
    runner.adapter.openapi_spec = copy.deepcopy(spec)
    runner.adapter._client = TestClient(app)
    runner.adapter.bugs = []

    # Valid template path
    valid_dsl = {
        "dsl_version": "1.0",
        "title": "Template valid",
        "endpoint": {"method": "GET", "path": "/v1.0/items/{id}"},
        "technique": "HAPPY_PATH",
        "source": "RULE",
        "steps": [
            {
                "name": "read",
                "request": {
                    "method": "GET",
                    "path": "/v1.0/items/{id}",
                    "path_params": {"id": 1},
                },
                "assertions": [{"type": "status", "equals": 200}],
            }
        ],
    }
    res_valid = runner.run([valid_dsl])
    assert res_valid.status == "COMPLETED"
    assert res_valid.repetitions_data[0].total_cases == 1

    # Invalid route /v1X0/items/1 must be rejected by semantic gate, not matched via unescaped dot
    invalid_dsl = {
        "dsl_version": "1.0",
        "title": "Dot wildcard mismatch",
        "endpoint": {"method": "GET", "path": "/v1X0/items/1"},
        "technique": "HAPPY_PATH",
        "source": "RULE",
        "steps": [
            {
                "name": "read",
                "request": {"method": "GET", "path": "/v1X0/items/1"},
                "assertions": [{"type": "status", "equals": 404}],
            }
        ],
    }
    res_invalid = runner.run([invalid_dsl])
    assert res_invalid.status == "COMPLETED"
    assert res_invalid.repetitions_data[0].total_cases == 0


# ==============================================================================
# Finding 9: Custom Oracle / Executor Injection per D03
# ==============================================================================
def test_finding_9_oracle_injection_in_experiment_runner() -> None:
    """ExperimentRunner accepts custom executor/oracle implementing the execution contract."""
    mock_executor = MagicMock()
    mock_executor.execute_test_case.return_value = TestCaseResult(
        case_id="tc-injected",
        title="Injected oracle case",
        technique="HAPPY_PATH",
        verdict="PASS",
    )

    config = ExperimentConfig(benchmark="simple-store", strategy="RULE", repetitions=1)
    runner = ExperimentRunner(config, executor=mock_executor)
    assert runner.executor is mock_executor


def test_finding_9_oracle_enum_and_validation() -> None:
    """ExperimentConfig restricts oracle to Literal['builtin'] and rejects invalid values."""
    from pydantic import ValidationError

    # When oracle is an arbitrary invalid string, Pydantic rejects with ValidationError
    with pytest.raises(ValidationError):
        ExperimentConfig(
            benchmark="simple-store",
            strategy="RULE",
            repetitions=1,
            oracle="not-an-oracle",  # type: ignore[arg-type]
        )

    # When oracle='php' is attempted prior to S4-02, Pydantic rejects with ValidationError
    with pytest.raises(ValidationError):
        ExperimentConfig(
            benchmark="simple-store",
            strategy="RULE",
            repetitions=1,
            oracle="php",  # type: ignore[arg-type]
        )

    # When oracle='builtin', resolves to DslExecutor
    config_builtin = ExperimentConfig(
        benchmark="simple-store", strategy="RULE", repetitions=1, oracle="builtin"
    )
    runner_builtin = ExperimentRunner(config_builtin)
    assert isinstance(runner_builtin.executor, DslExecutor)


# ==============================================================================
# Finding 10: Unicode Surrogate Pair JSONPath Resolution & Extraction
# ==============================================================================
@pytest.mark.parametrize(
    ("label", "path", "body", "op", "value", "expected"),
    [
        ("bmp-control", '$["\\u00e9"]', {"é": 1}, "eq", 1, "PASS"),
        ("surrogate-pair-positive", '$["\\ud83d\\ude00"]', {"😀": 1}, "eq", 1, "PASS"),
        ("surrogate-pair-single-quote", "$['\\ud83d\\ude00']", {"😀": 1}, "eq", 1, "PASS"),
        (
            "surrogate-pair-not-exists-fail",
            '$["\\ud83d\\ude00"]',
            {"😀": 1},
            "not_exists",
            None,
            "FAIL",
        ),
        (
            "surrogate-pair-not-exists-pass",
            '$["\\ud83d\\ude00"]',
            {"other": 1},
            "not_exists",
            None,
            "PASS",
        ),
        (
            "unpaired-high-surrogate",
            '$["\\ud83d"]',
            {"other": 1},
            "not_exists",
            None,
            "FAIL",
        ),
        (
            "unpaired-low-surrogate",
            '$["\\ude00"]',
            {"other": 1},
            "not_exists",
            None,
            "FAIL",
        ),
    ],
)
def test_finding_10_unicode_surrogate_pairs(
    label: str, path: str, body: dict, op: str, value: Any, expected: str
) -> None:
    """UTF-16 surrogate pairs resolve to Unicode scalar and prevent false PASS on not_exists."""
    executor = DslExecutor()
    client = MagicMock()
    client.request.return_value = MagicMock(
        status_code=200,
        headers={"content-type": "application/json"},
        json=lambda: body,
    )
    assertion = {"type": "json_path", "path": path, "op": op}
    if op != "not_exists":
        assertion["value"] = value

    dsl = {
        "id": f"tc-surrogate-{label}",
        "title": f"Surrogate pair {label}",
        "technique": "HAPPY_PATH",
        "steps": [
            {
                "name": "check",
                "request": {"method": "GET", "path": "/test"},
                "assertions": [assertion],
            }
        ],
    }
    result = executor.execute_test_case(dsl, client)
    assert result.verdict == expected, (label, result.error)


def test_finding_10_surrogate_pair_extraction() -> None:
    """Extractions resolve Unicode surrogate pair keys into variables correctly."""
    executor = DslExecutor()
    client = MagicMock()
    client.request.return_value = MagicMock(
        status_code=200,
        headers={"content-type": "application/json"},
        json=lambda: {"😀": 42},
    )

    dsl = {
        "id": "tc-extract-surrogate",
        "title": "Extract surrogate pair",
        "technique": "HAPPY_PATH",
        "steps": [
            {
                "name": "step_extract",
                "request": {"method": "GET", "path": "/test"},
                "extract": [
                    {"var": "extracted_val", "from": "body", "path": '$["\\ud83d\\ude00"]'}
                ],
                "assertions": [
                    {
                        "type": "json_path",
                        "path": '$["\\ud83d\\ude00"]',
                        "op": "eq",
                        "value": 42,
                    }
                ],
            }
        ],
    }
    result = executor.execute_test_case(dsl, client)
    assert result.verdict == "PASS"
