"""End-to-end integration tests for ExperimentRunner across all 3 Benchmark APIs."""

import json
from pathlib import Path

import pytest
from benchmark.runner.experiment_runner import ExperimentRunner
from benchmark.runner.models import ExperimentConfig


@pytest.mark.parametrize(
    ("slug", "expected_bugs"),
    [
        ("simple-store", 10),
        ("ecommerce-order", 12),
        ("appointment-booking", 10),
    ],
)
def test_experiment_runner_end_to_end_detects_all_bugs(
    slug: str, expected_bugs: int, tmp_path: Path
) -> None:
    """Run full experiment with canonical witness cases and verify 100% bug detection rate."""
    config = ExperimentConfig(
        name=f"test-run-{slug}",
        benchmark=slug,
        strategy="WITNESS",
        repetitions=1,
        seeds=[42],
        output_dir=str(tmp_path),
    )
    runner = ExperimentRunner(config)
    result = runner.run()

    assert result.status == "COMPLETED"
    assert len(result.repetitions_data) == 1

    rep = result.repetitions_data[0]
    assert rep.repetition == 1
    assert rep.clean_pass_count == expected_bugs
    assert rep.clean_fail_count == 0
    assert rep.clean_error_count == 0
    assert rep.bugs_total == expected_bugs
    assert rep.bugs_detected == expected_bugs

    # Every single bug must be detected
    for detection in rep.detections:
        assert detection.detected is True, f"Bug {detection.bug_code} was not detected!"
        assert len(detection.detecting_case_ids) >= 1

    # Verify metrics
    metrics = result.metrics
    assert metrics["total_bugs"] == expected_bugs
    assert metrics["unique_bugs_detected"] == expected_bugs
    assert metrics["bug_detection_rate"] == 1.0
    assert metrics["mutation_score"] == 1.0
    assert metrics["executable_rate"] == 1.0
    assert metrics["false_positive_rate"] == 0.0

    # Verify output artifact file was generated and is valid JSON
    out_files = list(tmp_path.glob("*.json"))
    assert len(out_files) == 1
    with out_files[0].open("r", encoding="utf-8") as f:
        saved_data = json.load(f)
    assert saved_data["id"] == result.id
    assert saved_data["metrics"]["bug_detection_rate"] == 1.0


def test_experiment_runner_multi_repetitions() -> None:
    """Verify multi-repetition run execution and aggregation across repetitions."""
    config = ExperimentConfig(
        name="test-multi-rep",
        benchmark="simple-store",
        strategy="WITNESS",
        repetitions=3,
        seeds=[42, 1024, 2026],
    )
    runner = ExperimentRunner(config)
    result = runner.run()

    assert len(result.repetitions_data) == 3
    assert result.repetitions_data[0].seed == 42
    assert result.repetitions_data[1].seed == 1024
    assert result.repetitions_data[2].seed == 2026

    for rep in result.repetitions_data:
        assert rep.bugs_detected == 10

    metrics = result.metrics
    assert metrics["total_bugs"] == 10
    assert metrics["unique_bugs_detected"] == 10
    assert metrics["average_bugs_detected_per_rep"] == 10.0
    assert metrics["bug_detection_rate"] == 1.0
