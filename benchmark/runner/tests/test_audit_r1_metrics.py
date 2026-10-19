"""S10-01 audit regressions: runner metrics must use the per-repetition definition.

BDR, Mutation Score, Executable Rate and FPR are computed for each repetition and reported as
mean and sample standard deviation (docs/architecture/experiment-design.md §5), exactly like the
web ExperimentMetricsCalculator. The union over repetitions is only ``ever_detected_rate``.
"""

import json
from pathlib import Path
from typing import Any

from benchmark.runner.experiment_runner import ExperimentRunner
from benchmark.runner.models import ExperimentConfig, RepetitionData

FIXTURE = Path(__file__).parent / "fixtures" / "metrics_crosscheck.json"


def _runner() -> ExperimentRunner:
    config = ExperimentConfig(
        name="metrics-audit",
        benchmark="simple-store",
        strategy="LLM",
        repetitions=3,
        seeds=[42, 1024, 2026],
    )
    return ExperimentRunner(config)


def _load() -> dict[str, Any]:
    with FIXTURE.open(encoding="utf-8") as f:
        data: dict[str, Any] = json.load(f)
    return data


def test_bug_detected_in_one_of_three_repetitions_is_not_counted_fully() -> None:
    data = _load()
    reps = [RepetitionData.model_validate(r) for r in data["repetitions_data"]]

    metrics = _runner()._calculate_metrics(reps)

    # Detected only in repetition 1 of 3 → mean BDR = (0.1 + 0 + 0) / 3, not the union 0.1.
    assert metrics["bug_detection_rate"] == 0.0333
    assert metrics["mutation_score"] == 0.0333
    assert metrics["ever_detected_rate"] == 0.1
    assert metrics["unique_bugs_detected"] == 1
    assert metrics["category_detection_rates"]["VALIDATION_MISSING"] == round(
        (round(1 / 3, 4) + 0 + 0) / 3, 4
    )


def test_runner_metrics_match_web_shared_crosscheck_fixture() -> None:
    """Same raw file is asserted in web SummarizeRunnerResultActionTest."""
    data = _load()
    reps = [RepetitionData.model_validate(r) for r in data["repetitions_data"]]

    metrics = _runner()._calculate_metrics(reps)

    for key, value in data["expected"]["metrics"].items():
        assert metrics[key] == value, key
    for key, value in data["expected"]["stddev"].items():
        assert metrics["stddev"][key] == value, key
    # Pooled values would have been ER = 21/25 = 0.84 and FPR = 4/21 = 0.1905.
    assert metrics["executable_rate"] != 0.84
    assert metrics["false_positive_rate"] != 0.1905
