"""Regression test suite verifying fixes for PR #92 review findings P1 and P2.

Findings covered:
- Finding 1 [P1]: Strategy generator dispatch, Fake generator injection, provenance.
- Finding 2 [P1]: DslExecutor contract compliance (operators, header, latency, types).
- Finding 3 [P1]: Canonical witness contract adherence (JSON Schema, techniques, steps).
- Finding 4 [P1]: Stable case identity preventing title collision and false negative.
- Finding 5 [P2]: Correct Executable Rate (ER) and False Positive Rate (FPR) metrics.
"""

from __future__ import annotations

import json
from pathlib import Path
from typing import Any
from unittest.mock import MagicMock

import jsonschema
from benchmark.runner.dsl_executor import DslExecutor
from benchmark.runner.experiment_runner import ExperimentRunner, StrategyGenerator
from benchmark.runner.models import (
    ExperimentConfig,
    TestCaseResult,
)
from benchmark.runner.witness_generator import (
    generate_witness_cases_for_benchmark,
    strip_dsl_metadata,
)
from fastapi import FastAPI, Response
from fastapi.testclient import TestClient


# ==============================================================================
# Finding 1 [P1]: Strategy Generator Dispatch & Fake Injection
# ==============================================================================
class FakeGenerator(StrategyGenerator):
    """Spy / Fake generator that records calls and parameters."""

    def __init__(self, cases: list[dict[str, Any]] | None = None) -> None:
        self.calls: list[dict[str, Any]] = []
        self._cases = cases or []

    def generate(
        self,
        benchmark: str,
        strategy: str,
        repetition: int,
        seed: int,
        config: ExperimentConfig,
        openapi_spec: dict[str, Any] | None = None,
    ) -> list[dict[str, Any]]:
        self.calls.append(
            {
                "benchmark": benchmark,
                "strategy": strategy,
                "repetition": repetition,
                "seed": seed,
                "model": config.model,
                "temperature": config.temperature,
            }
        )
        return self._cases


def test_finding_1_strategy_generator_dispatch() -> None:
    fake_gen = FakeGenerator()
    config = ExperimentConfig(
        name="dispatch-test",
        benchmark="simple-store",
        strategy="LLM",
        model="gpt-4o-mini",
        temperature=0.7,
        repetitions=2,
        seeds=[101, 102],
    )
    runner = ExperimentRunner(config, generator=fake_gen)
    runner.run()

    assert len(fake_gen.calls) == 2
    assert fake_gen.calls[0] == {
        "benchmark": "simple-store",
        "strategy": "LLM",
        "repetition": 1,
        "seed": 101,
        "model": "gpt-4o-mini",
        "temperature": 0.7,
    }
    assert fake_gen.calls[1] == {
        "benchmark": "simple-store",
        "strategy": "LLM",
        "repetition": 2,
        "seed": 102,
        "model": "gpt-4o-mini",
        "temperature": 0.7,
    }


# ==============================================================================
# Finding 2 [P1]: DslExecutor Contract Compliance
# ==============================================================================
def test_finding_2_numeric_and_jsonpath_operators() -> None:
    app = FastAPI()

    @app.get("/data")
    def get_data() -> dict[str, Any]:
        return {"count": 10, "ratio": 3.14, "tag": "prod", "null_field": None}

    client = TestClient(app)
    executor = DslExecutor()

    # Numeric comparison operators: eq, ne, gt, gte, lt, lte
    case = {
        "endpoint": {"method": "GET", "path": "/data"},
        "steps": [
            {
                "name": "check operators",
                "request": {"method": "GET", "path": "/data"},
                "assertions": [
                    {"type": "status", "equals": 200},
                    {"type": "json_path", "path": "$.count", "op": "eq", "value": 10},
                    {"type": "json_path", "path": "$.count", "op": "gt", "value": 5},
                    {"type": "json_path", "path": "$.count", "op": "gte", "value": 10},
                    {"type": "json_path", "path": "$.count", "op": "lt", "value": 15},
                    {"type": "json_path", "path": "$.count", "op": "lte", "value": 10},
                    {"type": "json_path", "path": "$.count", "op": "ne", "value": 99},
                ],
            }
        ],
    }
    res = executor.execute_test_case(case, client=client)
    assert res.verdict == "PASS"


def test_finding_2_present_null_vs_missing() -> None:
    app = FastAPI()

    @app.get("/null-check")
    def get_null() -> dict[str, Any]:
        return {"present_null": None}

    client = TestClient(app)
    executor = DslExecutor()

    # $.present_null exists should PASS; $.missing exists should FAIL
    case = {
        "endpoint": {"method": "GET", "path": "/null-check"},
        "steps": [
            {
                "name": "check present null",
                "request": {"method": "GET", "path": "/null-check"},
                "assertions": [
                    {"type": "json_path", "path": "$.present_null", "op": "exists"},
                    {"type": "json_path", "path": "$.missing", "op": "not_exists"},
                ],
            }
        ],
    }
    res = executor.execute_test_case(case, client=client)
    assert res.verdict == "PASS"


def test_finding_2_type_strictness_boolean_not_integer() -> None:
    app = FastAPI()

    @app.get("/type-check")
    def get_types() -> dict[str, Any]:
        return {"flag": True}

    client = TestClient(app)
    executor = DslExecutor()

    # $.flag is boolean True, asserting integer must FAIL
    case_fail = {
        "endpoint": {"method": "GET", "path": "/type-check"},
        "steps": [
            {
                "name": "check flag not int",
                "request": {"method": "GET", "path": "/type-check"},
                "assertions": [
                    {"type": "json_path", "path": "$.flag", "op": "type", "value": "integer"},
                ],
            }
        ],
    }
    res = executor.execute_test_case(case_fail, client=client)
    assert res.verdict == "FAIL"

    case_pass = {
        "endpoint": {"method": "GET", "path": "/type-check"},
        "steps": [
            {
                "name": "check flag is bool",
                "request": {"method": "GET", "path": "/type-check"},
                "assertions": [
                    {"type": "json_path", "path": "$.flag", "op": "type", "value": "boolean"},
                ],
            }
        ],
    }
    res2 = executor.execute_test_case(case_pass, client=client)
    assert res2.verdict == "PASS"


def test_finding_2_header_name_and_latency_lte_ms() -> None:
    app = FastAPI()

    @app.get("/header-check")
    def get_header() -> Response:
        return Response(content="ok", media_type="text/plain", headers={"X-Ait-Version": "1.0.0"})

    client = TestClient(app)
    executor = DslExecutor()

    case = {
        "endpoint": {"method": "GET", "path": "/header-check"},
        "steps": [
            {
                "name": "check header and latency",
                "request": {"method": "GET", "path": "/header-check"},
                "assertions": [
                    {"type": "header", "name": "x-ait-version", "op": "eq", "value": "1.0.0"},
                    {"type": "header", "name": "x-ait-version", "op": "exists"},
                    {"type": "header", "name": "x-missing-hdr", "op": "not_exists"},
                    {"type": "response_time", "lte_ms": 5000},
                ],
            }
        ],
    }
    res = executor.execute_test_case(case, client=client)
    assert res.verdict == "PASS"


# ==============================================================================
# Finding 3 [P1]: Witness Contract Validation
# ==============================================================================
def test_finding_3_all_32_witnesses_conform_to_dsl_schema() -> None:
    schema_path = (
        Path(__file__).resolve().parents[3] / "docs" / "contracts" / "test-dsl.schema.json"
    )
    schema = json.loads(schema_path.read_text(encoding="utf-8"))
    validator = jsonschema.Draft202012Validator(schema)

    all_witnesses: list[dict[str, Any]] = []
    for bench in ["simple-store", "ecommerce-order", "appointment-booking"]:
        all_witnesses.extend(generate_witness_cases_for_benchmark(bench))

    assert len(all_witnesses) == 32
    for w in all_witnesses:
        clean = strip_dsl_metadata(w)
        errors = list(validator.iter_errors(clean))
        assert errors == [], f"Witness {w.get('id')} failed schema: {errors}"
        # Ensure steps <= 20
        assert len(w["steps"]) <= 20
        # Ensure technique in schema enum
        assert w["technique"] in [
            "HAPPY_PATH",
            "BOUNDARY",
            "EQUIVALENCE",
            "INVALID_INPUT",
            "MISSING_REQUIRED",
            "TYPE_MISMATCH",
            "AUTH",
            "BUSINESS_RULE",
            "SEQUENCE",
        ]


# ==============================================================================
# Finding 4 [P1]: Title Collision Resilience
# ==============================================================================
def test_finding_4_cases_with_same_title_do_not_overwrite() -> None:
    """Two different cases with identical title must not overwrite verdicts."""
    config = ExperimentConfig(
        name="collision-test",
        benchmark="simple-store",
        strategy="RULE",
        repetitions=1,
        seeds=[42],
    )
    runner = ExperimentRunner(config)
    runner.adapter.bugs = runner.adapter.bugs[:1]
    target_bug = runner.adapter.bugs[0].code

    # Case A: Passes clean, fails mutant (triggers bug detection)
    # Case B: Passes clean, passes mutant
    # Both have the same title "Shared title"
    clean_a = TestCaseResult(case_id="case-1", title="Shared title", technique="T", verdict="PASS")
    bug_a = TestCaseResult(case_id="case-1", title="Shared title", technique="T", verdict="FAIL")

    clean_b = TestCaseResult(case_id="case-2", title="Shared title", technique="T", verdict="PASS")
    bug_b = TestCaseResult(case_id="case-2", title="Shared title", technique="T", verdict="PASS")

    runner.executor.execute_test_case = MagicMock(side_effect=[clean_a, clean_b, bug_a, bug_b])

    result = runner.run(
        test_cases_by_repetition=[
            {
                "id": "case-1",
                "dsl_version": "1.0",
                "title": "Shared title",
                "endpoint": {"method": "POST", "path": "/products"},
                "technique": "HAPPY_PATH",
                "source": "RULE",
                "steps": [
                    {
                        "name": "step 1",
                        "request": {"method": "POST", "path": "/products"},
                        "assertions": [{"type": "status", "equals": 201}],
                    }
                ],
            },
            {
                "id": "case-2",
                "dsl_version": "1.0",
                "title": "Shared title",
                "endpoint": {"method": "GET", "path": "/products"},
                "technique": "HAPPY_PATH",
                "source": "RULE",
                "steps": [
                    {
                        "name": "step 2",
                        "request": {"method": "GET", "path": "/products"},
                        "assertions": [{"type": "status", "equals": 200}],
                    }
                ],
            },
        ]
    )

    rep = result.repetitions_data[0]
    assert rep.total_cases == 2
    assert rep.clean_pass_count == 2
    assert rep.bugs_detected == 1

    detection = rep.detections[0]
    assert detection.bug_code == target_bug
    assert detection.detected is True
    assert "case-1" in detection.detecting_case_ids


# ==============================================================================
# Finding 5 [P2]: ER & FPR Metrics Formulas
# ==============================================================================
def test_finding_5_executable_rate_and_false_positive_rate() -> None:
    """ER = (PASS + FAIL on clean) / N_valid.

    FPR = FAIL / (PASS + FAIL on clean), excluding ERROR from FPR denominator.
    """
    config = ExperimentConfig(
        name="metrics-test",
        benchmark="simple-store",
        strategy="RULE",
        repetitions=1,
        seeds=[42],
    )
    runner = ExperimentRunner(config)
    runner.adapter.bugs = runner.adapter.bugs[:1]

    # Clean runs: 1 PASS, 1 FAIL, 1 ERROR -> Total executed = 1 + 1 = 2 out of 3 valid cases
    # ER = 2 / 3 = 0.6667
    # FPR = 1 FAIL / 2 executed = 0.5 (ERROR excluded from denominator)
    c1 = TestCaseResult(case_id="c-1", title="c1", technique="T", verdict="PASS")
    c2 = TestCaseResult(case_id="c-2", title="c2", technique="T", verdict="FAIL")
    c3 = TestCaseResult(case_id="c-3", title="c3", technique="T", verdict="ERROR")

    b1 = TestCaseResult(case_id="c-1", title="c1", technique="T", verdict="PASS")
    b2 = TestCaseResult(case_id="c-2", title="c2", technique="T", verdict="FAIL")
    b3 = TestCaseResult(case_id="c-3", title="c3", technique="T", verdict="ERROR")

    runner.executor.execute_test_case = MagicMock(side_effect=[c1, c2, c3, b1, b2, b3])

    result = runner.run(
        test_cases_by_repetition=[
            {
                "id": "c-1",
                "dsl_version": "1.0",
                "title": "case 1",
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
            {
                "id": "c-2",
                "dsl_version": "1.0",
                "title": "case 2",
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
            },
            {
                "id": "c-3",
                "dsl_version": "1.0",
                "title": "case 3",
                "endpoint": {"method": "GET", "path": "/products/1"},
                "technique": "HAPPY_PATH",
                "source": "RULE",
                "steps": [
                    {
                        "name": "s3",
                        "request": {"method": "GET", "path": "/products/1"},
                        "assertions": [{"type": "status", "equals": 200}],
                    }
                ],
            },
        ]
    )

    rep = result.repetitions_data[0]
    assert rep.total_cases == 3
    assert rep.clean_pass_count == 1
    assert rep.clean_fail_count == 1
    assert rep.clean_error_count == 1

    metrics = result.metrics
    assert metrics["executable_rate"] == 0.6667
    assert metrics["false_positive_rate"] == 0.5
