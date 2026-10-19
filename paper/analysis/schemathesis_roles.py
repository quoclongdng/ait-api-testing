"""Ablation: Schemathesis with the tokens of EVERY role.

The main run (`schemathesis_baseline.py`) uses only the default credential (alice). Here every other role of
ecommerce-order (customer_bob, admin) and appointment-booking (patient_bob, doctor, admin) is run with the same
5 seeds and configuration. Each role has its own clean baseline = union of two clean runs (as in the main run).
A fault counts as detected for seed s if the default run OR the run of any role detects it (D14 equivalent).

    python paper/analysis/schemathesis_roles.py run     # the experiment stack must be running; about 2 h
    python src/schemathesis_roles.py parse   # → results/schemathesis_roles_detections.csv
"""
import argparse
import os
import subprocess
import sys

import pandas as pd
import yaml

from params import AIT_REPO, SCHEMATHESIS_IMAGE, SEEDS, ST_CHECKS, ST_MAX_EXAMPLES, ST_PHASES
from schemathesis_baseline import HERE, NETWORK, RESULTS, units

ROOT = os.environ.get("ST_ROLES", os.path.join(HERE, "..", "schemathesis", "roles"))
BENCHES = ["ecommerce-order", "appointment-booking"]


def roles(slug):
    with open(os.path.join(AIT_REPO, "benchmark", slug, "ground-truth.yaml"), encoding="utf-8") as f:
        gt = yaml.safe_load(f)
    auth = gt["auth"]
    extra = {r: v["token"] for r, v in auth["roles"].items() if r != auth["default_role"]}
    return gt["bugs"], extra


def driver(slug, bugs, token, out):
    cmd = ["docker", "run", "--rm", "--network", NETWORK,
           "-v", f"{os.path.join(AIT_REPO, 'benchmark')}:/bench:ro", "-v", f"{out}:/out", "-v", f"{HERE}:/drv:ro",
           "--entrypoint", "python", SCHEMATHESIS_IMAGE, "/drv/st_driver.py", "--slug", slug, "--bugs", bugs,
           "--seeds", ",".join(map(str, SEEDS)), "--out", "/out", "--max-examples", str(ST_MAX_EXAMPLES),
           "--phases", ST_PHASES, "--checks", ST_CHECKS, "--token", token]
    rc = subprocess.call(cmd, env=dict(os.environ, MSYS_NO_PATHCONV="1"))
    if rc != 0:
        sys.exit(f"driver failed: {slug} ({rc})")


def run():
    for slug in BENCHES:
        bugs, extra = roles(slug)
        for role, token in extra.items():
            for sub, bug_arg in (("main", ",".join(b["code"] for b in bugs)), ("null", "")):
                out = os.path.join(ROOT, role, sub)
                os.makedirs(out, exist_ok=True)
                print(f"==> {slug} role={role} {sub}", flush=True)
                driver(slug, bug_arg, token, out)


def parse():
    base = pd.read_csv(os.path.join(RESULTS, "schemathesis_detections.csv"))
    rows = []
    for slug in BENCHES:
        bugs, extra = roles(slug)
        for r, seed in enumerate(SEEDS, start=1):
            for role in extra:
                d = os.path.join(ROOT, role, "main", slug, f"seed-{seed}")
                n = os.path.join(ROOT, role, "null", slug, f"seed-{seed}", "clean.json")
                clean, _ = units(os.path.join(d, "clean.json"))
                if os.path.exists(n):
                    clean |= units(n)[0]
                for b in bugs:
                    new = units(os.path.join(d, f"{b['code']}.json"))[0] - clean
                    own = f"{b['method'].upper()} {b['path']}"
                    rows.append(dict(benchmark=slug, repetition=r, bug=b["code"], category=b["category"], role=role,
                                     detected=int(bool(new)), detected_strict=int(any(op == own for op, _ in new))))
    roles_df = pd.DataFrame(rows)
    roles_df.to_csv(os.path.join(RESULTS, "schemathesis_roles_units.csv"), index=False)
    agg = roles_df.groupby(["benchmark", "repetition", "bug"])[["detected", "detected_strict"]].max().reset_index()
    m = base.merge(agg, on=["benchmark", "repetition", "bug"], how="left", suffixes=("_default", "_roles")).fillna(0)
    m["detected"] = m[["detected_default", "detected_roles"]].max(axis=1).astype(int)
    m["detected_strict"] = m[["detected_strict_default", "detected_strict_roles"]].max(axis=1).astype(int)
    out = m[["strategy", "benchmark", "repetition", "bug", "category", "detected", "detected_strict",
             "detected_default", "detected_roles"]]
    out.to_csv(os.path.join(RESULTS, "schemathesis_roles_detections.csv"), index=False)
    print("default:", int(m.detected_default.sum()), "/ 160 | all roles:", int(m.detected.sum()),
          "| strict, all roles:", int(m.detected_strict.sum()))
    print(m.groupby("category")[["detected_default", "detected"]].sum())


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("action", choices=["run", "parse"])
    {"run": run, "parse": parse}[ap.parse_args().action]()
