"""S10-01 audit regressions: ground-truth oracles follow the shared Test DSL contract.

`matches` values are bare patterns (docs/contracts/test-dsl.schema.json): no PHP-style
delimiters in ground truth, and the runner must not strip a leading/trailing `/`, `~` or `#`.
"""

from typing import Any

import pytest
from benchmark.runner.adapter import BenchmarkAdapter
from benchmark.runner.dsl_executor import DslExecutor
from benchmark.runner.dsl_validator import DslValidator
from fastapi import FastAPI
from fastapi.testclient import TestClient


def _case_with(assertion: dict[str, Any], method: str, path: str) -> dict[str, Any]:
    return {
        "dsl_version": "1.0",
        "title": "oracle",
        "endpoint": {"method": method, "path": path},
        "technique": "HAPPY_PATH",
        "source": "MANUAL",
        "steps": [
            {
                "name": "oracle",
                "request": {"method": method, "path": path},
                "assertions": [assertion],
            }
        ],
    }


@pytest.mark.parametrize("slug", BenchmarkAdapter.VALID_BENCHMARKS)
def test_every_ground_truth_oracle_is_a_dsl_assertion_with_bare_patterns(slug: str) -> None:
    adapter = BenchmarkAdapter(slug)
    validator = DslValidator()

    for bug in adapter.bugs:
        oracle = bug.oracle_assertion
        result = validator.validate(_case_with(oracle, bug.method, bug.path))
        assert result.schema_errors == [], (bug.code, result.schema_errors)
        if oracle.get("op") == "matches":
            pattern = str(oracle.get("value"))
            delimited = len(pattern) >= 2 and pattern[0] in "~/#" and pattern[-1] == pattern[0]
            assert not delimited, f"{bug.code}: PHP-style delimiter in {pattern!r}"


def test_bug_029_oracle_passes_on_clean_and_fails_on_bug() -> None:
    adapter = BenchmarkAdapter("appointment-booking")
    oracle = adapter.bugs_by_code["BUG-029"].oracle_assertion
    case = _case_with(oracle, "GET", "/doctors/101/slots")
    case["steps"][0]["request"]["query"] = {"date": "2026-10-16"}
    executor = DslExecutor()

    adapter.set_clean_state()
    assert executor.execute_test_case(case, adapter.client).verdict == "PASS"
    with adapter.isolated_bug("BUG-029"):
        assert executor.execute_test_case(case, adapter.client).verdict == "FAIL"


def test_matches_does_not_strip_slashes_from_a_bare_pattern() -> None:
    app = FastAPI()

    @app.get("/item")
    def item() -> dict[str, str]:
        return {"href": "orders"}

    client = TestClient(app)
    case = {
        "title": "bare pattern with slashes",
        "steps": [
            {
                "name": "s",
                "request": {"method": "GET", "path": "/item"},
                "assertions": [
                    {"type": "json_path", "path": "$.href", "op": "matches", "value": "/orders/"}
                ],
            }
        ],
    }
    # "/orders/" is a bare pattern requiring literal slashes; "orders" must not match it.
    assert DslExecutor().execute_test_case(case, client).verdict == "FAIL"
