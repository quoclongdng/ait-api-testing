"""Command Line Interface for Benchmark Experiment Runner (Task S9-01).

Usage:
    python -m benchmark.runner --benchmark simple-store --strategy WITNESS --repetitions 3
    python -m benchmark.runner --benchmark simple-store --strategy RULE --suite-file suite.json
    python -m benchmark.runner --all-benchmarks --strategy WITNESS
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path
from typing import Any

from benchmark.runner.experiment_runner import ExperimentRunner
from benchmark.runner.models import ExperimentConfig


def main() -> int:
    parser = argparse.ArgumentParser(
        description="AIT Benchmark Experiment Runner (docs/DESIGN.md §11, Decision D14)"
    )
    parser.add_argument(
        "--benchmark",
        choices=["simple-store", "ecommerce-order", "appointment-booking"],
        default="simple-store",
        help="Target benchmark API",
    )
    parser.add_argument(
        "--all-benchmarks",
        action="store_true",
        help="Run experiments across all 3 benchmark APIs sequentially",
    )
    parser.add_argument(
        "--strategy",
        choices=["RULE", "LLM", "HYBRID", "WITNESS"],
        default="WITNESS",
        help="Test generation strategy (RULE, LLM, HYBRID, or WITNESS control baseline)",
    )
    parser.add_argument(
        "--model",
        default="qwen2.5-coder:7b",
        help="LLM model name",
    )
    parser.add_argument(
        "--repetitions",
        type=int,
        default=3,
        help="Number of independent repetitions (default: 3)",
    )
    parser.add_argument(
        "--output-dir",
        default="benchmark/results/raw",
        help="Directory to save raw JSON result artifacts",
    )
    parser.add_argument(
        "--suite-file",
        default=None,
        help="Path to an existing test suite JSON file to evaluate (retains provenance)",
    )
    parser.add_argument(
        "--oracle",
        choices=["builtin"],
        default="builtin",
        help="Assertion oracle engine to evaluate verdicts ('builtin' in-process executor)",
    )
    parser.add_argument(
        "--name",
        default="exp-run",
        help="Experiment name tag",
    )

    args = parser.parse_args()

    repo_root = Path(__file__).resolve().parents[2]
    benchmarks = (
        ["simple-store", "ecommerce-order", "appointment-booking"]
        if args.all_benchmarks
        else [args.benchmark]
    )

    out_dir = Path(args.output_dir)
    if not out_dir.is_absolute():
        out_dir = repo_root / out_dir
    out_dir.mkdir(parents=True, exist_ok=True)

    loaded_suite: list[dict[str, Any]] | None = None
    if args.suite_file:
        suite_path = Path(args.suite_file)
        if not suite_path.is_absolute():
            candidates = [Path.cwd() / args.suite_file, repo_root / args.suite_file]
            found = False
            for cand in candidates:
                if cand.is_file():
                    suite_path = cand
                    found = True
                    break
            if not found:
                print(f"Error: Suite file '{args.suite_file}' does not exist.", file=sys.stderr)
                return 1
        elif not suite_path.is_file():
            print(f"Error: Suite file '{args.suite_file}' does not exist.", file=sys.stderr)
            return 1

        try:
            loaded_suite = json.loads(suite_path.read_text(encoding="utf-8"))
        except Exception as exc:
            print(f"Error: Failed to parse suite file '{args.suite_file}': {exc}", file=sys.stderr)
            return 1

        if not isinstance(loaded_suite, (list, dict)):
            print(
                f"Error: Suite file '{args.suite_file}' must contain a JSON array or mapping.",
                file=sys.stderr,
            )
            return 1

    if args.strategy in ("RULE", "LLM", "HYBRID") and loaded_suite is None:
        print(
            f"Error: Strategy '{args.strategy}' requires a suite file (--suite-file). "
            "Use --strategy WITNESS to evaluate the ground-truth witness baseline.",
            file=sys.stderr,
        )
        return 1

    print("=" * 60)
    print("AIT Benchmark Experiment Runner")
    print("Strategy:    " + str(args.strategy))
    print(f"Repetitions: {args.repetitions}")
    print(f"Oracle:      {args.oracle}")
    print(f"Output Dir:  {out_dir}")
    if loaded_suite is not None:
        count = (
            len(loaded_suite)
            if isinstance(loaded_suite, list)
            else sum(len(v) for v in loaded_suite.values())
        )
        print(f"Suite Source: {args.suite_file} ({count} cases)")
    print("=" * 60)

    for bench in benchmarks:
        print(f"\n[+] Running experiment on benchmark: {bench}...")
        config = ExperimentConfig(
            name=f"{args.name}-{bench}",
            benchmark=bench,
            strategy=args.strategy,
            model=args.model,
            repetitions=args.repetitions,
            output_dir=str(out_dir),
            oracle=args.oracle,
        )
        runner = ExperimentRunner(config)
        result = runner.run(test_cases_by_repetition=loaded_suite)

        if result.status == "FAILED":
            print(f"  [FAILED] Run failed: {result.metrics.get('error')}", file=sys.stderr)
            return 1

        metrics = result.metrics
        print(f"  [OK] Finished: {result.name} (ID: {result.id})")
        print(f"    Total Seeded Bugs:      {metrics.get('total_bugs')}")
        print(f"    Bugs Detected (mean):   {metrics.get('average_bugs_detected_per_rep')}")
        stddev = metrics.get("stddev", {})
        for label, key in (
            ("Bug Detection Rate", "bug_detection_rate"),
            ("Mutation Score", "mutation_score"),
            ("Executable Rate", "executable_rate"),
            ("False Positive Rate", "false_positive_rate"),
        ):
            mean_pct = metrics.get(key, 0.0) * 100
            std_pct = stddev.get(key, 0.0) * 100
            print(f"    {label + ':':<24}{mean_pct:.1f}% ± {std_pct:.1f}% (mean ± stddev)")
        print(
            f"    Ever Detected (union):  {metrics.get('unique_bugs_detected')} "
            f"({metrics.get('ever_detected_rate', 0.0) * 100:.1f}%, not BDR)"
        )

    print("\n[OK] All experiment runs completed successfully.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
