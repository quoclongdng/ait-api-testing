"""Specific unit tests verifying Decision D14 adherence in Experiment Runner.

Source of truth: docs/DESIGN.md D14:
'Một bug được tính là phát hiện khi có >=1 test PASS trên biến thể sạch
và FAIL/ERROR trên biến thể có bug. Test FAIL trên biến thể sạch bị tính là false positive.'
"""

from typing import Any
from unittest.mock import MagicMock

from benchmark.runner.experiment_runner import ExperimentRunner
from benchmark.runner.models import ExperimentConfig, TestCaseResult


def _make_valid_dsl(case_id: str, title: str = "test", path: str = "/products") -> dict[str, Any]:
    return {
        "id": case_id,
        "dsl_version": "1.0",
        "title": title,
        "endpoint": {"method": "GET", "path": path},
        "technique": "HAPPY_PATH",
        "source": "RULE",
        "steps": [
            {
                "name": "step 1",
                "request": {"method": "GET", "path": path},
                "assertions": [{"type": "status", "equals": 200}],
            }
        ],
    }


def _make_mock_runner() -> ExperimentRunner:
    config = ExperimentConfig(
        name="d14-verification",
        benchmark="simple-store",
        strategy="WITNESS",
        repetitions=1,
        seeds=[42],
    )
    runner = ExperimentRunner(config)
    # Stub adapter bugs to 1 single bug for clear isolation
    runner.adapter.bugs = runner.adapter.bugs[:1]
    return runner


def test_d14_case_pass_on_clean_and_fail_on_bug_is_detected() -> None:
    """PASS on clean + FAIL on bug => bug IS detected."""
    runner = _make_mock_runner()
    target_bug = runner.adapter.bugs[0].code

    clean_res = TestCaseResult(case_id="tc-1", title="test", technique="T", verdict="PASS")
    bug_res = TestCaseResult(case_id="tc-1", title="test", technique="T", verdict="FAIL")

    runner.executor.execute_test_case = MagicMock(side_effect=[clean_res, bug_res])

    result = runner.run(test_cases_by_repetition=[_make_valid_dsl("tc-1", "test")])
    detection = result.repetitions_data[0].detections[0]

    assert detection.bug_code == target_bug
    assert detection.detected is True
    assert detection.detecting_case_ids == ["tc-1"]


def test_d14_case_pass_on_clean_and_error_on_bug_is_detected() -> None:
    """PASS on clean + ERROR on bug => bug IS detected."""
    runner = _make_mock_runner()
    target_bug = runner.adapter.bugs[0].code

    clean_res = TestCaseResult(case_id="tc-1", title="test", technique="T", verdict="PASS")
    bug_res = TestCaseResult(case_id="tc-1", title="test", technique="T", verdict="ERROR")

    runner.executor.execute_test_case = MagicMock(side_effect=[clean_res, bug_res])

    result = runner.run(test_cases_by_repetition=[_make_valid_dsl("tc-1", "test")])
    detection = result.repetitions_data[0].detections[0]

    assert detection.bug_code == target_bug
    assert detection.detected is True
    assert detection.detecting_case_ids == ["tc-1"]


def test_d14_case_pass_on_clean_and_pass_on_bug_is_not_detected() -> None:
    """PASS on clean + PASS on bug => bug is NOT detected."""
    runner = _make_mock_runner()
    target_bug = runner.adapter.bugs[0].code

    clean_res = TestCaseResult(case_id="tc-1", title="test", technique="T", verdict="PASS")
    bug_res = TestCaseResult(case_id="tc-1", title="test", technique="T", verdict="PASS")

    runner.executor.execute_test_case = MagicMock(side_effect=[clean_res, bug_res])

    result = runner.run(test_cases_by_repetition=[_make_valid_dsl("tc-1", "test")])
    detection = result.repetitions_data[0].detections[0]

    assert detection.bug_code == target_bug
    assert detection.detected is False
    assert detection.detecting_case_ids == []


def test_d14_case_fail_on_clean_is_false_positive_and_not_counted_as_detection() -> None:
    """FAIL on clean + FAIL on bug => FALSE POSITIVE; MUST NOT count as bug detection."""
    runner = _make_mock_runner()
    target_bug = runner.adapter.bugs[0].code

    clean_res = TestCaseResult(case_id="tc-1", title="test", technique="T", verdict="FAIL")
    bug_res = TestCaseResult(case_id="tc-1", title="test", technique="T", verdict="FAIL")

    runner.executor.execute_test_case = MagicMock(side_effect=[clean_res, bug_res])

    result = runner.run(test_cases_by_repetition=[_make_valid_dsl("tc-1", "test")])
    detection = result.repetitions_data[0].detections[0]

    assert detection.bug_code == target_bug
    assert detection.detected is False
    assert detection.detecting_case_ids == []
    # False positive rate is 1.0 because 1 out of 1 test failed on clean
    assert result.repetitions_data[0].clean_fail_count == 1
    assert result.metrics["false_positive_rate"] == 1.0


def test_d14_multi_cases_mixed_resolution() -> None:
    """tc-1 passes clean and fails on bug (detected); tc-2 fails on both (not detected)."""
    runner = _make_mock_runner()

    # Clean run: tc-1 passes, tc-2 fails
    clean_tc1 = TestCaseResult(case_id="tc-1", title="test 1", technique="T", verdict="PASS")
    clean_tc2 = TestCaseResult(case_id="tc-2", title="test 2", technique="T", verdict="FAIL")

    # Bug run: tc-1 fails, tc-2 fails
    bug_tc1 = TestCaseResult(case_id="tc-1", title="test 1", technique="T", verdict="FAIL")
    bug_tc2 = TestCaseResult(case_id="tc-2", title="test 2", technique="T", verdict="FAIL")

    runner.executor.execute_test_case = MagicMock(
        side_effect=[clean_tc1, clean_tc2, bug_tc1, bug_tc2]
    )

    result = runner.run(
        test_cases_by_repetition=[
            _make_valid_dsl("tc-1", "test 1", "/products"),
            _make_valid_dsl("tc-2", "test 2", "/categories"),
        ]
    )
    detection = result.repetitions_data[0].detections[0]

    assert detection.detected is True
    # ONLY tc-1 is listed in detecting_case_ids, tc-2 was false positive
    assert detection.detecting_case_ids == ["tc-1"]


def test_d14_detecting_case_ids_follow_suite_order() -> None:
    """Detecting case ids are reported in suite order for reproducible raw output."""
    runner = _make_mock_runner()
    ids = [f"tc-{n}" for n in (5, 1, 9, 3)]
    clean = [TestCaseResult(case_id=i, title=i, technique="T", verdict="PASS") for i in ids]
    bug = [TestCaseResult(case_id=i, title=i, technique="T", verdict="FAIL") for i in ids]
    runner.executor.execute_test_case = MagicMock(side_effect=clean + bug)

    paths = ["/products", "/categories", "/products/1", "/categories/1"]
    suite = [_make_valid_dsl(i, i, p) for i, p in zip(ids, paths, strict=True)]
    result = runner.run(test_cases_by_repetition=suite)

    assert result.repetitions_data[0].total_cases == 4
    assert result.repetitions_data[0].detections[0].detecting_case_ids == ids
