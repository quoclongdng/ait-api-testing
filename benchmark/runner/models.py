"""Data models and schemas for the Benchmark Experiment Runner (Task S9-01).

Implements the Experiment matrix configuration, run results, bug detections,
and evaluation metrics adhering to docs/DESIGN.md §11, §12, and Decision D14.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import UTC, datetime
from typing import Any, Literal

from pydantic import BaseModel, ConfigDict
from pydantic import Field as PydanticField

StrategyType = Literal["RULE", "LLM", "HYBRID", "WITNESS"]
VerdictType = Literal["PASS", "FAIL", "ERROR"]
OracleType = Literal["builtin"]


class ExperimentConfig(BaseModel):
    """Configuration for an experiment execution run."""

    model_config = ConfigDict(extra="forbid")

    name: str = PydanticField(default="experiment-run", description="Experiment run name")
    benchmark: str = PydanticField(
        ..., description="Benchmark slug: simple-store, ecommerce-order, appointment-booking"
    )
    strategy: StrategyType = PydanticField(
        default="RULE", description="Generation strategy: RULE, LLM, HYBRID, or WITNESS"
    )
    mode: Literal["experiment", "witness"] = PydanticField(
        default="experiment", description="Execution mode: experiment or witness control"
    )
    model: str = PydanticField(default="qwen2.5-coder:7b", description="LLM model identifier")
    repetitions: int = PydanticField(
        default=3, ge=1, le=10, description="Number of independent repetitions"
    )
    seeds: list[int] = PydanticField(
        default_factory=lambda: [42, 1024, 2026], description="Random seeds for each repetition"
    )
    temperature: float = PydanticField(
        default=0.2, ge=0.0, le=2.0, description="Sampling temperature for LLM"
    )
    base_url: str | None = PydanticField(
        default=None, description="Optional remote base URL; None runs in-process TestClient"
    )
    output_dir: str | None = PydanticField(
        default=None, description="Directory to save raw result JSON files"
    )
    oracle: OracleType = PydanticField(
        default="builtin",
        description="Assertion oracle engine: 'builtin' (in-process DslExecutor)",
    )


class BugDefinition(BaseModel):
    """Definition of a seeded bug loaded from ground-truth.yaml."""

    model_config = ConfigDict(extra="ignore")

    code: str
    method: str
    path: str
    category: str
    toggle_env: str
    description: str
    expected_clean_behavior: str | None = None
    actual_bug_behavior: str | None = None
    oracle_assertion: dict[str, Any] = PydanticField(default_factory=dict)


@dataclass
class StepResult:
    """Execution result of a single DSL step."""

    name: str
    method: str
    path: str
    status_code: int | None = None
    passed: bool = True
    error: str | None = None
    assertions_count: int = 0
    passed_assertions: int = 0
    failed_assertions: list[dict[str, Any]] = field(default_factory=list)
    latency_ms: int = 0


@dataclass
class TestCaseResult:
    """Execution result of an entire test case."""

    __test__ = False

    case_id: str
    title: str
    technique: str
    verdict: VerdictType
    steps: list[StepResult] = field(default_factory=list)
    error: str | None = None
    total_latency_ms: int = 0


class BugDetectionRecord(BaseModel):
    """Bug detection record per repetition conforming to Decision D14."""

    model_config = ConfigDict(extra="forbid")

    bug_code: str
    category: str
    detected: bool
    detecting_case_ids: list[str] = PydanticField(default_factory=list)
    clean_verdicts: dict[str, str] = PydanticField(default_factory=dict)
    bug_verdicts: dict[str, str] = PydanticField(default_factory=dict)


class RepetitionData(BaseModel):
    """Result data for a single repetition r."""

    model_config = ConfigDict(extra="forbid")

    repetition: int
    seed: int
    total_cases: int
    clean_pass_count: int
    clean_fail_count: int
    clean_error_count: int
    bugs_total: int
    bugs_detected: int
    detections: list[BugDetectionRecord] = PydanticField(default_factory=list)


class ExperimentResult(BaseModel):
    """Comprehensive outcome of the experiment runner."""

    model_config = ConfigDict(extra="forbid")

    id: str
    name: str
    benchmark: str
    strategy: StrategyType
    config: dict[str, Any]
    status: Literal["COMPLETED", "FAILED"] = "COMPLETED"
    started_at: str = PydanticField(default_factory=lambda: datetime.now(UTC).isoformat())
    finished_at: str | None = None
    repetitions_data: list[RepetitionData] = PydanticField(default_factory=list)
    metrics: dict[str, Any] = PydanticField(default_factory=dict)
    bug_detections_summary: list[dict[str, Any]] = PydanticField(default_factory=list)
