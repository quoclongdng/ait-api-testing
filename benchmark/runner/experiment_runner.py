"""Experiment Runner Core Engine (Task S9-01).

Implements the experiment execution protocol across:
{Strategy} x {Benchmark} x {Variants (Clean + Seeded Bugs)}
strictly adhering to Decision D14 and Section 4.3.1 isolation rules.
"""

from __future__ import annotations

import contextlib
import json
import math
import uuid
from datetime import UTC, datetime
from pathlib import Path
from typing import Any, Protocol

from benchmark.runner.adapter import BenchmarkAdapter
from benchmark.runner.dsl_executor import DslExecutor
from benchmark.runner.dsl_validator import DslValidator
from benchmark.runner.fingerprint import DslFingerprintService
from benchmark.runner.models import (
    BugDetectionRecord,
    ExperimentConfig,
    ExperimentResult,
    RepetitionData,
)
from benchmark.runner.witness_generator import (
    generate_witness_cases_for_benchmark,
    strip_dsl_metadata,
)


class StrategyGenerator(Protocol):
    """Protocol for generating test suites for benchmark experiment repetitions."""

    def generate(
        self,
        benchmark: str,
        strategy: str,
        repetition: int,
        seed: int,
        config: ExperimentConfig,
        openapi_spec: dict[str, Any] | None = None,
    ) -> list[dict[str, Any]]: ...


class DefaultStrategyGenerator:
    """Default generator dispatching strategy pipelines or witness control."""

    def generate(
        self,
        benchmark: str,
        strategy: str,
        repetition: int,
        seed: int,
        config: ExperimentConfig,
        openapi_spec: dict[str, Any] | None = None,
    ) -> list[dict[str, Any]]:
        # In WITNESS control mode, return ground truth canonical witnesses
        if strategy == "WITNESS" or config.mode == "witness":
            cases = generate_witness_cases_for_benchmark(benchmark)
            for c in cases:
                c.setdefault("source", "WITNESS")
            return cases

        raise ValueError(
            f"No producer configured for strategy '{strategy}' in DefaultStrategyGenerator. "
            f"To evaluate '{strategy}', provide a pre-generated suite via --suite-file "
            f"or inject a custom StrategyGenerator (e.g. FakeStrategyGenerator). "
            f"Use strategy='WITNESS' to run the ground-truth control baseline."
        )


class ExperimentRunner:
    """Executes the quantitative benchmark evaluation matrix applying Decision D14."""

    def __init__(
        self,
        config: ExperimentConfig,
        generator: StrategyGenerator | None = None,
        executor: DslExecutor | None = None,
    ) -> None:
        if config.base_url is not None:
            msg = (
                f"Remote execution via base_url ('{config.base_url}') is not supported. "
                "In-process single-fault bug toggle isolation is required."
            )
            raise ValueError(msg)

        if config.oracle != "builtin":
            msg = (
                f"Unsupported oracle '{config.oracle}'. Only 'builtin' is supported "
                "prior to S4-02 common PHP AssertionEngine integration."
            )
            raise ValueError(msg)

        self.config = config
        self.adapter = BenchmarkAdapter(config.benchmark)
        self.executor = executor or DslExecutor(openapi_spec=self.adapter.openapi_spec)
        self.generator = generator or DefaultStrategyGenerator()
        self.fingerprint_service = DslFingerprintService()
        self._dsl_validator = DslValidator()

    def _validate_provenance(self, cases: list[dict[str, Any]]) -> None:
        """Enforce strict provenance validation for RULE, LLM, and HYBRID strategies.

        If a test case does not match the configured strategy provenance,
        raise ValueError to conclude run as FAILED without silently altering or filtering source.
        """
        expected_strategy = self.config.strategy
        if expected_strategy not in ("RULE", "LLM", "HYBRID"):
            return

        for case in cases:
            if not isinstance(case, dict):
                continue
            src = case.get("source")
            cid = case.get("id") or case.get("case_id") or case.get("title") or "unknown"
            if src != expected_strategy:
                raise ValueError(
                    f"Provenance mismatch: Configured strategy is '{expected_strategy}', "
                    f"but test case '{cid}' has source='{src}'. "
                    "Mismatched provenance is not allowed."
                )

    def _validate_and_dedup_cases(
        self,
        raw_cases: list[dict[str, Any]],
    ) -> list[dict[str, Any]]:
        """Validate Test DSL against JSON Schema, OpenAPI semantics, and deduplicate cases."""
        valid_cases: list[dict[str, Any]] = []
        seen_fingerprints: set[str] = set()

        if not raw_cases:
            return []

        for case in raw_cases:
            if not isinstance(case, dict):
                continue

            clean_dsl = strip_dsl_metadata(case)

            # 1. Full Schema & OpenAPI Semantic Validation (Decision D04)
            val_res = self._dsl_validator.validate(clean_dsl, spec=self.adapter.openapi_spec)
            if not val_res.is_valid:
                continue

            # 2. Canonical Fingerprint Deduplication (RFC 8785)
            with contextlib.suppress(Exception):
                fp = self.fingerprint_service.calculate(clean_dsl)
                if fp in seen_fingerprints:
                    continue
                seen_fingerprints.add(fp)

            valid_cases.append(case)

        return valid_cases

    def run(
        self,
        test_cases_by_repetition: dict[int, list[dict[str, Any]]]
        | list[dict[str, Any]]
        | None = None,
    ) -> ExperimentResult:
        """Run the full multi-repetition experiment across clean and all bug variants."""
        experiment_id = uuid.uuid4().hex[:12]
        started_at = datetime.now(UTC).isoformat()
        repetitions_data: list[RepetitionData] = []
        all_bug_detections: list[BugDetectionRecord] = []

        total_reps = self.config.repetitions
        seeds = list(self.config.seeds)
        while len(seeds) < total_reps:
            seeds.append(42 + len(seeds) * 1000)

        try:
            # Pre-validate suite mapping if a dictionary is provided
            if isinstance(test_cases_by_repetition, dict):
                for r in range(1, total_reps + 1):
                    cases_r = test_cases_by_repetition.get(r)
                    if cases_r is None:
                        cases_r = test_cases_by_repetition.get(str(r))
                    if cases_r is None:
                        raise ValueError(
                            f"Suite mapping is missing repetition {r} (required: 1..{total_reps})"
                        )
                    if not isinstance(cases_r, list):
                        raise ValueError(
                            f"Suite mapping for repetition {r} must be a list of cases, "
                            f"got {type(cases_r).__name__}"
                        )

            for rep_idx in range(1, total_reps + 1):
                seed = seeds[rep_idx - 1]

                # 1. Obtain test cases for this repetition and validate provenance
                raw_cases = self._resolve_cases_for_repetition(
                    test_cases_by_repetition, rep_idx, seed
                )
                self._validate_provenance(raw_cases)
                cases = self._validate_and_dedup_cases(raw_cases)

                # Assign stable and strictly unique case identities to prevent title collision
                case_entries: list[tuple[str, dict[str, Any]]] = []
                seen_cids: set[str] = set()
                for idx, c in enumerate(cases):
                    raw_id = c.get("id") or c.get("case_id")
                    if raw_id:
                        base_cid = str(raw_id)
                        cid = base_cid
                        suffix_num = 2
                        while cid in seen_cids:
                            cid = f"{base_cid}_{suffix_num}"
                            suffix_num += 1
                    else:
                        title_slug = str(c.get("title", "case")).strip().replace(" ", "_")[:30]
                        base_cid = f"case_{idx}_{title_slug}"
                        cid = base_cid
                        suffix_num = 2
                        while cid in seen_cids:
                            cid = f"{base_cid}_{suffix_num}"
                            suffix_num += 1
                    seen_cids.add(cid)
                    case_entries.append((cid, c))

                # 2. Baseline Clean Run (Section 4.3.1 Step 2)
                self.adapter.set_clean_state()
                clean_verdicts: dict[str, str] = {}
                for cid, case in case_entries:
                    res = self.executor.execute_test_case(case, self.adapter.client)
                    clean_verdicts[cid] = res.verdict

                pass_clean = {cid for cid, v in clean_verdicts.items() if v == "PASS"}
                clean_pass_count = len(pass_clean)
                clean_fail_count = sum(1 for v in clean_verdicts.values() if v == "FAIL")
                clean_error_count = sum(1 for v in clean_verdicts.values() if v == "ERROR")

                # 3. Bug Mutation Evaluation (Section 4.3.1 Step 3 & Decision D14)
                rep_detections: list[BugDetectionRecord] = []
                for bug in self.adapter.bugs:
                    # Enforce Single-Fault Isolation with guaranteed cleanup
                    with self.adapter.isolated_bug(bug.code):
                        bug_verdicts: dict[str, str] = {}
                        for cid, case in case_entries:
                            res = self.executor.execute_test_case(case, self.adapter.client)
                            bug_verdicts[cid] = res.verdict

                        # Decision D14: Bug detected iff >=1 test PASS on clean & FAIL/ERROR on bug
                        # Iterate in suite order (not set order) for reproducible raw output.
                        detecting_case_ids = [
                            cid
                            for cid, _ in case_entries
                            if cid in pass_clean and bug_verdicts.get(cid) in ("FAIL", "ERROR")
                        ]
                        detected = len(detecting_case_ids) > 0

                        rec = BugDetectionRecord(
                            bug_code=bug.code,
                            category=bug.category,
                            detected=detected,
                            detecting_case_ids=detecting_case_ids,
                            clean_verdicts=clean_verdicts,
                            bug_verdicts=bug_verdicts,
                        )
                        rep_detections.append(rec)
                        all_bug_detections.append(rec)

                detected_in_rep = sum(1 for d in rep_detections if d.detected)
                rep_data = RepetitionData(
                    repetition=rep_idx,
                    seed=seed,
                    total_cases=len(cases),
                    clean_pass_count=clean_pass_count,
                    clean_fail_count=clean_fail_count,
                    clean_error_count=clean_error_count,
                    bugs_total=len(self.adapter.bugs),
                    bugs_detected=detected_in_rep,
                    detections=rep_detections,
                )
                repetitions_data.append(rep_data)

            # 4. Aggregated Metrics and Summary Calculation
            metrics = self._calculate_metrics(repetitions_data)
            bug_summary = self._summarize_bug_detections(all_bug_detections)
            finished_at = datetime.now(UTC).isoformat()

            result = ExperimentResult(
                id=experiment_id,
                name=self.config.name,
                benchmark=self.config.benchmark,
                strategy=self.config.strategy,
                config=self.config.model_dump(),
                status="COMPLETED",
                started_at=started_at,
                finished_at=finished_at,
                repetitions_data=repetitions_data,
                metrics=metrics,
                bug_detections_summary=bug_summary,
            )

            if self.config.output_dir:
                self._save_result(result, Path(self.config.output_dir))

            return result

        except Exception as exc:
            self.adapter.set_clean_state()
            finished_at = datetime.now(UTC).isoformat()
            result = ExperimentResult(
                id=experiment_id,
                name=self.config.name,
                benchmark=self.config.benchmark,
                strategy=self.config.strategy,
                config=self.config.model_dump(),
                status="FAILED",
                started_at=started_at,
                finished_at=finished_at,
                repetitions_data=repetitions_data,
                metrics={"error": str(exc)},
                bug_detections_summary=[],
            )
            if self.config.output_dir:
                self._save_result(result, Path(self.config.output_dir))
            return result
        finally:
            self.adapter.set_clean_state()

    def _resolve_cases_for_repetition(
        self,
        test_cases_by_repetition: dict[int | str, list[dict[str, Any]]]
        | list[dict[str, Any]]
        | None,
        repetition: int,
        seed: int,
    ) -> list[dict[str, Any]]:
        """Resolve test suite for the repetition while strictly retaining provenance."""
        if isinstance(test_cases_by_repetition, dict):
            if repetition in test_cases_by_repetition:
                cases = test_cases_by_repetition[repetition]
            elif str(repetition) in test_cases_by_repetition:
                cases = test_cases_by_repetition[str(repetition)]
            else:
                raise ValueError(f"Suite mapping is missing cases for repetition {repetition}")
            if not isinstance(cases, list):
                raise ValueError(
                    f"Cases for repetition {repetition} must be a list, got {type(cases).__name__}"
                )
            return cases

        if isinstance(test_cases_by_repetition, list):
            return test_cases_by_repetition

        # Generate through configured strategy generator pipeline
        return self.generator.generate(
            benchmark=self.config.benchmark,
            strategy=self.config.strategy,
            repetition=repetition,
            seed=seed,
            config=self.config,
            openapi_spec=self.adapter.openapi_spec,
        )

    @staticmethod
    def _ratio(numerator: int, denominator: int) -> float:
        """Same rule as web ExperimentMetricsCalculator::ratio (0.0 on empty denominator)."""
        if denominator <= 0:
            return 0.0
        return round(max(0, numerator) / denominator, 4)

    @staticmethod
    def _mean(values: list[float]) -> float:
        return sum(values) / len(values) if values else 0.0

    @classmethod
    def _sample_stddev(cls, values: list[float]) -> float:
        """Sample standard deviation (n - 1), 0.0 with fewer than two values."""
        n = len(values)
        if n < 2:
            return 0.0
        mean = cls._mean(values)
        return float(math.sqrt(sum((v - mean) ** 2 for v in values) / (n - 1)))

    def _calculate_metrics(self, reps: list[RepetitionData]) -> dict[str, Any]:
        """Compute metrics of docs/DESIGN.md §11 per repetition, then mean and sample stddev.

        The headline rates follow docs/architecture/experiment-design.md §5 and the web
        ExperimentMetricsCalculator: every rate is computed for one repetition, then the mean
        over the N repetitions is reported with its sample standard deviation. The union over
        repetitions ("detected in at least one repetition") is reported separately as
        ``ever_detected_rate`` and must not be used as the Bug Detection Rate.
        """
        total_bugs = len(self.adapter.bugs)
        if total_bugs == 0 or not reps:
            return {}

        detected_by_bug: dict[str, bool] = {b.code: False for b in self.adapter.bugs}
        bug_category = {b.code: b.category for b in self.adapter.bugs}
        category_totals: dict[str, int] = {}
        for bug in self.adapter.bugs:
            category_totals[bug.category] = category_totals.get(bug.category, 0) + 1

        per_rep: dict[str, list[float]] = {
            "bug_detection_rate": [],
            "mutation_score": [],
            "executable_rate": [],
            "false_positive_rate": [],
        }
        category_per_rep: dict[str, list[float]] = {cat: [] for cat in category_totals}

        for r in reps:
            rep_total_bugs = r.bugs_total if r.bugs_total > 0 else total_bugs
            executed = r.clean_pass_count + r.clean_fail_count
            per_rep["bug_detection_rate"].append(self._ratio(r.bugs_detected, rep_total_bugs))
            # Each seeded bug is one mutant variant: killed mutants = detected bugs (D14).
            per_rep["mutation_score"].append(self._ratio(r.bugs_detected, rep_total_bugs))
            per_rep["executable_rate"].append(self._ratio(executed, r.total_cases))
            per_rep["false_positive_rate"].append(self._ratio(r.clean_fail_count, executed))

            rep_detected_by_category: dict[str, int] = {cat: 0 for cat in category_totals}
            for d in r.detections:
                if d.detected:
                    detected_by_bug[d.bug_code] = True
                    cat = bug_category.get(d.bug_code, d.category)
                    if cat in rep_detected_by_category:
                        rep_detected_by_category[cat] += 1
            for cat, tot in category_totals.items():
                category_per_rep[cat].append(self._ratio(rep_detected_by_category[cat], tot))

        unique_detected = sum(1 for v in detected_by_bug.values() if v)
        avg_detected = self._mean([float(r.bugs_detected) for r in reps])

        metrics: dict[str, Any] = {"total_bugs": total_bugs}
        stddev: dict[str, float] = {}
        for key, values in per_rep.items():
            metrics[key] = round(self._mean(values), 4)
            stddev[key] = round(self._sample_stddev(values), 4)

        metrics.update(
            {
                "average_bugs_detected_per_rep": round(avg_detected, 2),
                "unique_bugs_detected": unique_detected,
                "ever_detected_rate": self._ratio(unique_detected, total_bugs),
                "category_detection_rates": {
                    cat: round(self._mean(values), 4) for cat, values in category_per_rep.items()
                },
                "stddev": stddev,
                "repetitions": [
                    {key: values[i] for key, values in per_rep.items()} for i in range(len(reps))
                ],
            }
        )
        return metrics

    def _summarize_bug_detections(
        self, detections: list[BugDetectionRecord]
    ) -> list[dict[str, Any]]:
        """Summarize bug detection outcomes for database persistence."""
        grouped: dict[str, dict[str, Any]] = {}
        for d in detections:
            if d.bug_code not in grouped:
                grouped[d.bug_code] = {
                    "bug_code": d.bug_code,
                    "category": d.category,
                    "ever_detected": False,
                    "times_detected": 0,
                    "detecting_test_case_ids": set(),
                }
            if d.detected:
                grouped[d.bug_code]["ever_detected"] = True
                grouped[d.bug_code]["times_detected"] += 1
                grouped[d.bug_code]["detecting_test_case_ids"].update(d.detecting_case_ids)

        return [
            {
                "bug_code": v["bug_code"],
                "category": v["category"],
                "detected": v["ever_detected"],
                "times_detected": v["times_detected"],
                "detecting_test_case_ids": sorted(list(v["detecting_test_case_ids"])),
            }
            for v in grouped.values()
        ]

    def _save_result(self, result: ExperimentResult, output_dir: Path) -> None:
        output_dir.mkdir(parents=True, exist_ok=True)
        filename = f"{result.name}_{result.benchmark}_{result.strategy}_{result.id}.json"
        filepath = output_dir / filename
        with filepath.open("w", encoding="utf-8") as f:
            json.dump(result.model_dump(), f, indent=2, ensure_ascii=False)
