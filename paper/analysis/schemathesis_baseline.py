"""Schemathesis baseline on the 32 seeded faults, with a detection rule equivalent to D14.

A "test" unit = (operation, check type). For each seed:
  - run Schemathesis on the clean API and on every single-fault variant (same seed, sandbox reset before each run);
  - a unit that FAILs on the clean API is a false positive (excluded from detection, as in D14);
  - fault B is detected when at least one unit PASSes on the clean API and FAILs on variant B.
Clean baseline = UNION of the FAIL units of two clean runs (RAW and NULL): appointment-booking depends on the
wall clock, so two clean runs a few hours apart differ (clean-vs-clean control run, 2026-10-15).
Two levels: `detected` (D14 equivalent, lenient towards the baseline) and `detected_strict` (the detecting
unit is on the operation of the fault in the ground truth).
Schemathesis does not reset the sandbox between its cases (unlike the reset_per_case protocol of
RULE/LLM/HYBRID); see Threats to validity in the paper.

Requires the experiment stack to be running (`make up-experiment`), Docker network `ait_default`.

    python paper/analysis/schemathesis_baseline.py run      # run Schemathesis (about 3-5 h, resumable)
    python paper/analysis/schemathesis_baseline.py parse    # JSON reports -> results/schemathesis_*.csv
"""
import argparse
import csv
import glob
import json
import os
import subprocess
import sys

import yaml

from params import (AIT_REPO, BENCHMARKS, SCHEMATHESIS_IMAGE, SEEDS, ST_CHECKS, ST_MAX_EXAMPLES,
                    ST_PHASES)

HERE = os.path.dirname(os.path.abspath(__file__))
RESULTS = os.path.join(HERE, "..", "results")
RAW = os.environ.get("ST_RAW", os.path.join(HERE, "..", "schemathesis", "raw"))
# Second clean run (same seeds) to measure noise: `st_driver.py --bugs ""` with --out set to this folder
NULL = os.environ.get("ST_NULL", os.path.join(HERE, "..", "schemathesis", "null"))
NETWORK = os.environ.get("AIT_NETWORK", "ait_default")


def ground_truth(slug):
    with open(os.path.join(AIT_REPO, "benchmark", slug, "ground-truth.yaml"), encoding="utf-8") as f:
        gt = yaml.safe_load(f)
    token = ""
    auth = gt.get("auth")
    if auth:  # the same default credential the executor uses for every strategy (ADR-0006)
        token = auth["roles"][auth["default_role"]]["token"]
    return gt["bugs"], token


def run():
    os.makedirs(RAW, exist_ok=True)
    for slug in BENCHMARKS:
        bugs, token = ground_truth(slug)
        cmd = ["docker", "run", "--rm", "--network", NETWORK,
               "-v", f"{os.path.join(AIT_REPO, 'benchmark')}:/bench:ro",
               "-v", f"{RAW}:/out", "-v", f"{HERE}:/drv:ro",
               "--entrypoint", "python", SCHEMATHESIS_IMAGE, "/drv/st_driver.py",
               "--slug", slug, "--bugs", ",".join(b["code"] for b in bugs),
               "--seeds", ",".join(map(str, SEEDS)), "--out", "/out",
               "--max-examples", str(ST_MAX_EXAMPLES), "--phases", ST_PHASES, "--checks", ST_CHECKS]
        if token:
            cmd += ["--token", token]
        print("==>", slug, flush=True)
        env = dict(os.environ, MSYS_NO_PATHCONV="1")
        rc = subprocess.call(cmd, env=env)
        if rc != 0:
            sys.exit(f"Schemathesis driver failed on {slug} (exit code {rc})")


def units(report_path):
    with open(report_path, encoding="utf-8") as f:
        rep = json.load(f)
    out = set()
    for fail in rep.get("failures", []):
        for op in fail.get("operations", []):
            out.add((op, fail["type"]))
    return out, rep


def parse():
    det_rows, rep_rows, unit_rows = [], [], []
    for slug in BENCHMARKS:
        bugs, _ = ground_truth(slug)
        for r, seed in enumerate(SEEDS, start=1):
            d = os.path.join(RAW, slug, f"seed-{seed}")
            clean_path = os.path.join(d, "clean.json")
            if not os.path.exists(clean_path):
                print(f"  missing {clean_path}")
                continue
            clean_fail, clean_rep = units(clean_path)
            null_path = os.path.join(NULL, slug, f"seed-{seed}", "clean.json")
            null_extra = set()
            if os.path.exists(null_path):
                null_fail, _ = units(null_path)
                null_extra = null_fail - clean_fail
                clean_fail = clean_fail | null_fail
            detected_n = 0
            for b in bugs:
                p = os.path.join(d, f"{b['code']}.json")
                if not os.path.exists(p):
                    print(f"  missing {p}")
                    continue
                bug_fail, _ = units(p)
                new = sorted(bug_fail - clean_fail)
                det = int(bool(new))
                own_op = f"{b['method'].upper()} {b['path']}"
                det_strict = int(any(op == own_op for op, _ in new))
                detected_n += det
                det_rows.append(dict(strategy="SCHEMATHESIS", benchmark=slug, repetition=r, bug=b["code"],
                                     category=b["category"], detected=det, detected_strict=det_strict))
                for op, typ in new:
                    unit_rows.append(dict(benchmark=slug, repetition=r, seed=seed, bug=b["code"],
                                          operation=op, check=typ, on_bug_operation=int(op == own_op)))
            tc = clean_rep.get("test_cases", {})
            rep_rows.append(dict(benchmark=slug, repetition=r, seed=seed,
                                 bug_detection_rate=detected_n / len(bugs),
                                 clean_failing_units=len(clean_fail),
                                 clean_null_extra_units=len(null_extra),
                                 generated=tc.get("generated"),
                                 clean_running_time_s=clean_rep.get("running_time"),
                                 operations_tested=clean_rep.get("operations", {}).get("tested")))
    os.makedirs(RESULTS, exist_ok=True)
    for name, rows in (("schemathesis_detections", det_rows), ("schemathesis_rep_metrics", rep_rows),
                       ("schemathesis_detecting_units", unit_rows)):
        path = os.path.join(RESULTS, f"{name}.csv")
        with open(path, "w", newline="", encoding="utf-8") as f:
            if rows:
                w = csv.DictWriter(f, fieldnames=list(rows[0]))
                w.writeheader()
                w.writerows(rows)
        print(f"{path}: {len(rows)} rows")
    if det_rows:
        tot = sum(r["detected"] for r in det_rows)
        st = sum(r["detected_strict"] for r in det_rows)
        print(f"Schemathesis detected {tot}/{len(det_rows)} (fault x repetition); strict: {st}/{len(det_rows)}")
    timings = glob.glob(os.path.join(RAW, "*", "timings.csv"))
    print("timings:", timings)


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("action", choices=["run", "parse"])
    {"run": run, "parse": parse}[ap.parse_args().action]()
