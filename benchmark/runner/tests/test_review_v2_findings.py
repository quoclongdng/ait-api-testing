"""Comprehensive regression tests verifying fixes for PR #92 Review V2 findings.

Covers:
- Finding 1 [P1]: DefaultStrategyGenerator rejects unlabeled strategy witness substitution.
- Finding 2 [P1]: DslExecutor quoted member JSONPath resolution and exact JSON body transport.
- Finding 3 [P1]: Mandatory schema validation, semantic spec gate, and canonical fingerprint dedup.
- Finding 4 [P1]: Case ID suffix collision prevention with existing IDs.
- Finding 5 [P2]: CLI exits non-zero on missing or invalid --suite-file.
- Finding 6 [P2]: ExperimentRunner rejects base_url when remote mode is unsupported.
- Finding 7 [P2]: Mutant exception guarantees bug cleanup and returns FAILED experiment status.
"""

from __future__ import annotations

from typing import Any
from unittest.mock import MagicMock

import pytest
from benchmark.runner.cli import main as cli_main
from benchmark.runner.dsl_executor import DslExecutor, _resolve_json_path
from benchmark.runner.experiment_runner import (
    DefaultStrategyGenerator,
    ExperimentRunner,
)
from benchmark.runner.fingerprint import DslFingerprintService
from benchmark.runner.models import ExperimentConfig, TestCaseResult
from fastapi import FastAPI, Request, Response
from fastapi.testclient import TestClient


def _make_valid_dsl(
    case_id: str,
    title: str = "Valid Case",
    method: str = "GET",
    path: str = "/products",
    status_eq: int = 200,
) -> dict[str, Any]:
    return {
        "id": case_id,
        "dsl_version": "1.0",
        "title": title,
        "endpoint": {"method": method, "path": path},
        "technique": "HAPPY_PATH",
        "source": "RULE",
        "steps": [
            {
                "name": "step 1",
                "request": {"method": method, "path": path},
                "assertions": [{"type": "status", "equals": status_eq}],
            }
        ],
    }


# ==============================================================================
# Finding 1 [P1]: DefaultStrategyGenerator Provenance
# ==============================================================================
def test_finding_1_default_generator_rejects_disguised_witness() -> None:
    """DefaultStrategyGenerator must not disguise witness cases as RULE, LLM, or HYBRID."""
    generator = DefaultStrategyGenerator()
    config = ExperimentConfig(benchmark="simple-store", strategy="RULE")

    # WITNESS mode returns witness cases
    witnesses = generator.generate("simple-store", "WITNESS", 1, 42, config)
    assert len(witnesses) == 10

    # Non-witness strategies must raise ValueError if no producer is connected
    for strat in ("RULE", "LLM", "HYBRID"):
        with pytest.raises(ValueError, match="No producer configured for strategy"):
            generator.generate("simple-store", strat, 1, 42, config)


# ==============================================================================
# Finding 2 [P1]: Quoted Literal Keys & Wire JSON Encoding
# ==============================================================================
def test_finding_2_quoted_member_key_jsonpath() -> None:
    """Selector must read literal member key 'a.b', distinct from nested body.a.b."""
    data = {"a.b": 1, "a": {"b": 2}}

    # Quoted key: $['a.b'] -> literal key "a.b"
    found, val = _resolve_json_path(data, "$['a.b']")
    assert found is True
    assert val == 1

    # Nested path: $.a.b -> body["a"]["b"]
    found_nested, val_nested = _resolve_json_path(data, "$.a.b")
    assert found_nested is True
    assert val_nested == 2


def test_finding_2_wire_json_body_encoding() -> None:
    """Request body must transmit exact JSON bytes on wire for primitives, null, and objects."""
    received_bodies: list[bytes] = []

    app = FastAPI()

    @app.api_route("/echo", methods=["GET", "POST", "PUT"])
    async def echo_endpoint(request: Request) -> Response:
        body_bytes = await request.body()
        received_bodies.append(body_bytes)
        return Response(content=b"ok", status_code=200)

    client = TestClient(app)
    executor = DslExecutor()

    test_cases_and_expected = [
        ({"body": None}, b"null"),
        ({"body": False}, b"false"),
        ({"body": True}, b"true"),
        ({"body": "hello"}, b'"hello"'),
        ({"body": 0}, b"0"),
        ({"body": []}, b"[]"),
        ({"body": {}}, b"{}"),
        ({}, b""),  # absent body
    ]

    for req_dict, expected_bytes in test_cases_and_expected:
        received_bodies.clear()
        step = {
            "name": "step echo",
            "request": {"method": "POST", "path": "/echo", **req_dict},
            "assertions": [{"type": "status", "equals": 200}],
        }
        case = {
            "dsl_version": "1.0",
            "title": "Echo test",
            "endpoint": {"method": "POST", "path": "/echo"},
            "technique": "HAPPY_PATH",
            "source": "RULE",
            "steps": [step],
        }
        res = executor.execute_test_case(case, client)
        assert res.verdict == "PASS"
        assert len(received_bodies) == 1
        assert received_bodies[0] == expected_bytes


# ==============================================================================
# Finding 3 [P1]: Schema Validation, Semantic Spec Gate, and Fingerprint Dedup
# ==============================================================================
def test_finding_3_mandatory_schema_validation_rejects_invalid_cases() -> None:
    """Cases missing dsl_version, assertions, or with unknown version must be rejected."""
    config = ExperimentConfig(benchmark="simple-store", strategy="WITNESS", repetitions=1)
    runner = ExperimentRunner(config)

    invalid_cases = [
        # Missing dsl_version
        {
            "id": "c-1",
            "title": "Missing version",
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
        },
        # Version 2.0
        {
            "id": "c-2",
            "dsl_version": "2.0",
            "title": "Unknown version",
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
        },
        # Missing assertions
        {
            "id": "c-3",
            "dsl_version": "1.0",
            "title": "Missing assertions",
            "endpoint": {"method": "GET", "path": "/products"},
            "technique": "HAPPY_PATH",
            "source": "RULE",
            "steps": [{"name": "s1", "request": {"method": "GET", "path": "/products"}}],
        },
    ]

    filtered = runner._validate_and_dedup_cases(invalid_cases)
    assert len(filtered) == 0


def test_finding_3_semantic_spec_gate_rejects_unknown_endpoints() -> None:
    """Cases targeting endpoints not declared in OpenAPI spec must be rejected."""
    config = ExperimentConfig(benchmark="simple-store", strategy="WITNESS", repetitions=1)
    runner = ExperimentRunner(config)

    # /not-in-spec is valid according to JSON Schema, but absent from simple-store OpenAPI
    case = _make_valid_dsl("c-not-in-spec", title="Unknown Endpoint", path="/not-in-spec")

    filtered = runner._validate_and_dedup_cases([case])
    assert len(filtered) == 0


def test_finding_3_canonical_fingerprint_dedup_ignores_title() -> None:
    """Cases with identical requests and assertions but different titles must be deduplicated."""
    config = ExperimentConfig(benchmark="simple-store", strategy="WITNESS", repetitions=1)
    runner = ExperimentRunner(config)

    case1 = _make_valid_dsl("c-1", title="Title One", path="/products")
    case2 = _make_valid_dsl("c-2", title="Title Two", path="/products")

    # DslFingerprintService produces identical fingerprint
    fp_service = DslFingerprintService()
    assert fp_service.calculate(case1) == fp_service.calculate(case2)

    filtered = runner._validate_and_dedup_cases([case1, case2])
    assert len(filtered) == 1
    assert filtered[0]["id"] == "c-1"


# ==============================================================================
# Finding 4 [P1]: Suffix Collision Prevention
# ==============================================================================
def test_finding_4_id_suffix_collision_prevention() -> None:
    """IDs ['dup', 'dup_2', 'dup'] must be uniquely assigned as ['dup', 'dup_2', 'dup_3']."""
    config = ExperimentConfig(benchmark="simple-store", strategy="WITNESS", repetitions=1)
    runner = ExperimentRunner(config)
    runner.adapter.bugs = runner.adapter.bugs[:1]

    # Create 3 cases targeting different valid endpoints so dedup keeps all 3
    c1 = _make_valid_dsl("dup", title="Case 1", path="/products")
    c2 = _make_valid_dsl("dup_2", title="Case 2", path="/categories")
    c3 = _make_valid_dsl("dup", title="Case 3", path="/health")

    # Case 2 detects bug (PASS clean, FAIL mutant)
    # Case 1 & 3 pass both
    c1_res = TestCaseResult(case_id="dup", title="Case 1", technique="T", verdict="PASS")
    c2_clean = TestCaseResult(case_id="dup_2", title="Case 2", technique="T", verdict="PASS")
    c3_clean = TestCaseResult(case_id="dup_3", title="Case 3", technique="T", verdict="PASS")

    c1_mutant = TestCaseResult(case_id="dup", title="Case 1", technique="T", verdict="PASS")
    c2_mutant = TestCaseResult(case_id="dup_2", title="Case 2", technique="T", verdict="FAIL")
    c3_mutant = TestCaseResult(case_id="dup_3", title="Case 3", technique="T", verdict="PASS")

    runner.executor.execute_test_case = MagicMock(
        side_effect=[c1_res, c2_clean, c3_clean, c1_mutant, c2_mutant, c3_mutant]
    )

    result = runner.run(test_cases_by_repetition=[c1, c2, c3])
    rep = result.repetitions_data[0]

    assert rep.total_cases == 3
    assert rep.clean_pass_count == 3
    # Bug detection for Case 2 must NOT be overwritten by Case 3!
    assert rep.bugs_detected == 1
    detection = rep.detections[0]
    assert detection.detected is True
    assert "dup_2" in detection.detecting_case_ids


# ==============================================================================
# Finding 5 [P2]: CLI Exits on Non-Existent Suite File
# ==============================================================================
def test_finding_5_cli_exits_on_missing_suite_file(monkeypatch: pytest.MonkeyPatch) -> None:
    """CLI must fail cleanly when --suite-file points to a non-existent file."""
    monkeypatch.setattr(
        "sys.argv",
        ["runner", "--benchmark", "simple-store", "--suite-file", "does-not-exist.json"],
    )
    exit_code = cli_main()
    assert exit_code == 1


# ==============================================================================
# Finding 6 [P2]: ExperimentConfig Rejects base_url in Unsupported Remote Mode
# ==============================================================================
def test_finding_6_runner_rejects_base_url() -> None:
    """ExperimentRunner must reject base_url configuration in local single-fault mode."""
    config = ExperimentConfig(
        benchmark="simple-store",
        base_url="http://review-target.invalid:8000",
    )
    with pytest.raises(ValueError, match=r"Remote execution via base_url .* is not supported"):
        ExperimentRunner(config)


# ==============================================================================
# Finding 7 [P2]: Mutant Exception Cleanup & FAILED Result
# ==============================================================================
def test_finding_7_mutant_exception_guarantees_cleanup() -> None:
    """When an exception occurs during mutant execution, clean state is restored and run FAILS."""
    config = ExperimentConfig(benchmark="simple-store", strategy="WITNESS", repetitions=1)
    runner = ExperimentRunner(config)
    runner.adapter.bugs = runner.adapter.bugs[:1]
    bug = runner.adapter.bugs[0]

    case = _make_valid_dsl("c-1", title="Case 1", path="/products")

    # Clean passes, but mutant raises RuntimeError
    clean_res = TestCaseResult(case_id="c-1", title="Case 1", technique="T", verdict="PASS")

    def mock_exec(*args: Any, **kwargs: Any) -> TestCaseResult:
        if runner.adapter.settings.bug_001:
            raise RuntimeError("Mutant execution fatal error")
        return clean_res

    runner.executor.execute_test_case = MagicMock(side_effect=mock_exec)

    result = runner.run(test_cases_by_repetition=[case])

    assert result.status == "FAILED"
    assert "Mutant execution fatal error" in str(result.metrics.get("error"))

    # Crucial: bug toggle MUST NOT be left enabled!
    toggle_attr = bug.toggle_env.lower()
    assert getattr(runner.adapter.settings, toggle_attr) is False
