"""Tests for Experiment Runner data models and configuration."""

from benchmark.runner.models import (
    BugDefinition,
    BugDetectionRecord,
    ExperimentConfig,
    ExperimentResult,
    RepetitionData,
    StepResult,
    TestCaseResult,
)


def test_experiment_config_validation() -> None:
    config = ExperimentConfig(
        name="test-run",
        benchmark="simple-store",
        strategy="RULE",
        repetitions=3,
        seeds=[42, 1024, 2026],
    )
    assert config.benchmark == "simple-store"
    assert config.strategy == "RULE"
    assert config.repetitions == 3
    assert len(config.seeds) == 3


def test_bug_definition_model() -> None:
    bug = BugDefinition(
        code="BUG-001",
        method="POST",
        path="/products",
        category="VALIDATION_MISSING",
        toggle_env="BUG_001",
        description="Missing required name",
        oracle_assertion={"type": "status", "equals": 422},
    )
    assert bug.code == "BUG-001"
    assert bug.category == "VALIDATION_MISSING"
    assert bug.oracle_assertion["equals"] == 422


def test_test_case_result_data() -> None:
    step = StepResult(
        name="create product",
        method="POST",
        path="/products",
        status_code=422,
        passed=True,
    )
    case_res = TestCaseResult(
        case_id="tc-1",
        title="Check validation",
        technique="VALIDATION_MISSING",
        verdict="PASS",
        steps=[step],
    )
    assert case_res.verdict == "PASS"
    assert len(case_res.steps) == 1
    assert case_res.steps[0].status_code == 422


def test_experiment_result_serialization() -> None:
    rep = RepetitionData(
        repetition=1,
        seed=42,
        total_cases=10,
        clean_pass_count=10,
        clean_fail_count=0,
        clean_error_count=0,
        bugs_total=10,
        bugs_detected=10,
        detections=[
            BugDetectionRecord(
                bug_code="BUG-001",
                category="VALIDATION_MISSING",
                detected=True,
                detecting_case_ids=["tc-1"],
            )
        ],
    )
    res = ExperimentResult(
        id="exp-123",
        name="run-simple",
        benchmark="simple-store",
        strategy="RULE",
        config={"benchmark": "simple-store", "strategy": "RULE"},
        repetitions_data=[rep],
        metrics={"bug_detection_rate": 1.0, "mutation_score": 1.0},
    )
    dumped = res.model_dump()
    assert dumped["id"] == "exp-123"
    assert dumped["repetitions_data"][0]["bugs_detected"] == 10
    assert dumped["metrics"]["bug_detection_rate"] == 1.0
