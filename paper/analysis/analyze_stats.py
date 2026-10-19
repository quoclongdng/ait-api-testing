"""Aggregation and statistical tests for the RULE / LLM / HYBRID / SCHEMATHESIS experiment.

Input: results/manifest.json
    {"runs": [{"model": "qwen2.5-coder:7b", "dir": "<folder holding experiments.json>"}, ...],
     "schemathesis": "<path of schemathesis_detections.csv>"   # optional
    }
Each "dir" is a folder exported by `run-experiment.sh` (one benchmark, three strategies).

Output (results/):
    detections_long.csv    one row = (model, strategy, benchmark, repetition, bug)
    rep_metrics.csv        one row = (model, strategy, benchmark, repetition): FPR, SCC, VR, tokens, time
    summary_bdr.csv        BDR per configuration (mean ± SD over repetitions) and pooled
    tests_detection.csv    Cochran's Q + pairwise exact McNemar (Holm)
    tests_metrics.csv      pairwise Wilcoxon (Holm) + Vargha-Delaney A12 for FPR, SCC, VR
    tests_models.csv       comparison of the two models within a strategy

Run: python paper/analysis/analyze_stats.py   (or --manifest <file>)
"""
import argparse
import itertools
import json
import os

import numpy as np
import pandas as pd
from scipy import stats
from statsmodels.stats.contingency_tables import cochrans_q, mcnemar
from statsmodels.stats.multitest import multipletests

from params import ALPHA, AIT_REPO, MANIFEST

HERE = os.path.dirname(os.path.abspath(__file__))
RESULTS = os.path.join(HERE, "..", "results")
REP_METRICS = ["false_positive_rate", "status_code_coverage", "validity_rate", "bug_detection_rate",
               "generation_time_ms", "prompt_tokens", "completion_tokens", "generated", "valid",
               "endpoint_coverage", "executable_rate", "duplicate", "bugs_detected"]


_BASE = [AIT_REPO]


def _resolve(path):
    """Absolute path, or a path relative to the repository root."""
    if os.path.isabs(path):
        return path
    for base in _BASE:
        cand = os.path.join(base, path)
        if os.path.exists(cand):
            return cand
    return os.path.join(AIT_REPO, path)


def load(manifest):
    det, rep = [], []
    for run in manifest["runs"]:
        with open(os.path.join(_resolve(run["dir"]), "experiments.json"), encoding="utf-8") as f:
            data = json.load(f)
        for e in data["experiments"]:
            if e["status"] != "COMPLETED":
                print(f"  skipping experiment {e['id']} ({e['strategy']}) with status {e['status']}")
                continue
            # RULE uses no LLM: keep the model of the run to check that RULE is identical across the two runs
            base = dict(model=run["model"], strategy=e["strategy"], benchmark=e["benchmark"]["slug"])
            for b in e["bug_detections"]:
                det.append(dict(base, repetition=b["repetition"], bug=b["bug_code"],
                                category=b["category"], detected=int(bool(b["detected"]))))
            for r in e["repetitions"]:
                m = r["metrics"]
                rep.append(dict(base, repetition=r["repetition"], seed=r.get("seed"),
                                **{k: m.get(k) for k in REP_METRICS}))
    det, rep = pd.DataFrame(det), pd.DataFrame(rep)
    st = manifest.get("schemathesis")
    if st:
        s = pd.read_csv(_resolve(st))
        # Schemathesis uses no LLM: duplicate it for each model so that it is compared within the same block
        for model in det["model"].unique():
            det = pd.concat([det, s.assign(model=model, strategy="SCHEMATHESIS")[det.columns]], ignore_index=True)
        rm = os.path.join(os.path.dirname(_resolve(st)), "schemathesis_rep_metrics.csv")
        if os.path.exists(rm):
            r = pd.read_csv(rm)
            for model in rep["model"].unique():
                rep = pd.concat([rep, r.assign(model=model, strategy="SCHEMATHESIS")], ignore_index=True)
    return det, rep


def a12(x, y):
    """Vargha-Delaney A12: probability that a value of x is larger than a value of y (0.5 = no difference)."""
    x, y = np.asarray(x, float), np.asarray(y, float)
    gt = (x[:, None] > y[None, :]).sum()
    eq = (x[:, None] == y[None, :]).sum()
    return (gt + 0.5 * eq) / (len(x) * len(y))


def a12_label(a):
    d = abs(a - 0.5)
    return "negligible" if d < 0.06 else "small" if d < 0.14 else "medium" if d < 0.21 else "large"


def summary_bdr(det):
    per_rep = (det.groupby(["model", "strategy", "benchmark", "repetition"])["detected"]
               .mean().reset_index())
    by_cfg = (per_rep.groupby(["model", "strategy", "benchmark"])["detected"]
              .agg(bdr_mean="mean", bdr_sd="std", n_reps="count").reset_index())
    pooled = (det.groupby(["model", "strategy"])["detected"]
              .agg(detected="sum", total="count").reset_index())
    pooled["benchmark"] = "ALL"
    pooled["bdr_mean"] = pooled["detected"] / pooled["total"]
    return pd.concat([by_cfg, pooled], ignore_index=True)


def tests_detection(det):
    """Block = (benchmark, bug, repetition); strategies are compared within a block."""
    rows = []
    for model, g in det.groupby("model"):
        wide = g.pivot_table(index=["benchmark", "bug", "repetition"], columns="strategy",
                             values="detected", aggfunc="first").dropna()
        strategies = list(wide.columns)
        if len(strategies) >= 3:
            q = cochrans_q(wide.values.astype(int))
            rows.append(dict(model=model, test="Cochran Q", pair="all: " + ",".join(strategies),
                             n_blocks=len(wide), statistic=q.statistic, p=q.pvalue))
        pair_rows = []
        for a, b in itertools.combinations(strategies, 2):
            x, y = wide[a].astype(int), wide[b].astype(int)
            tab = [[int(((x == 1) & (y == 1)).sum()), int(((x == 1) & (y == 0)).sum())],
                   [int(((x == 0) & (y == 1)).sum()), int(((x == 0) & (y == 0)).sum())]]
            r = mcnemar(tab, exact=True)
            pair_rows.append(dict(model=model, test="McNemar exact", pair=f"{a} vs {b}", n_blocks=len(wide),
                                  only_first=tab[0][1], only_second=tab[1][0], statistic=r.statistic,
                                  p=r.pvalue, bdr_first=x.mean(), bdr_second=y.mean()))
        if pair_rows:
            adj = multipletests([r["p"] for r in pair_rows], method="holm")[1]
            for r, p in zip(pair_rows, adj):
                r["p_holm"] = p
                r["significant"] = p < ALPHA
        rows += pair_rows
    return pd.DataFrame(rows)


def tests_metrics(rep, metrics=("false_positive_rate", "status_code_coverage", "validity_rate")):
    """Block = (benchmark, repetition); pairwise Wilcoxon + A12."""
    rows = []
    for model, g in rep.groupby("model"):
        for metric in metrics:
            wide = g.pivot_table(index=["benchmark", "repetition"], columns="strategy",
                                 values=metric, aggfunc="first")
            # SCHEMATHESIS has no FPR/SCC/VR in the same unit -> empty columns, dropped before blocking
            wide = wide.dropna(axis=1, how="all").dropna()
            block = []
            for a, b in itertools.combinations(wide.columns, 2):
                x, y = wide[a].astype(float), wide[b].astype(float)
                if np.allclose(x, y):
                    p, w = 1.0, np.nan
                else:
                    res = stats.wilcoxon(x, y, zero_method="zsplit")
                    p, w = res.pvalue, res.statistic
                aa = a12(x, y)
                block.append(dict(model=model, metric=metric, pair=f"{a} vs {b}", n=len(wide),
                                  mean_first=x.mean(), mean_second=y.mean(), W=w, p=p,
                                  A12=aa, effect=a12_label(aa)))
            if block:
                adj = multipletests([r["p"] for r in block], method="holm")[1]
                for r, p in zip(block, adj):
                    r["p_holm"] = p
                    r["significant"] = p < ALPHA
            rows += block
    return pd.DataFrame(rows)


def tests_models(det, rep):
    models = sorted(det["model"].unique())
    if len(models) < 2:
        return pd.DataFrame()
    m1, m2 = models[:2]
    rows = []
    for strategy in ("LLM", "HYBRID", "RULE"):
        d = det[det.strategy == strategy].pivot_table(index=["benchmark", "bug", "repetition"], columns="model",
                                                      values="detected", aggfunc="first").dropna()
        if {m1, m2} <= set(d.columns):
            x, y = d[m1].astype(int), d[m2].astype(int)
            tab = [[int(((x == 1) & (y == 1)).sum()), int(((x == 1) & (y == 0)).sum())],
                   [int(((x == 0) & (y == 1)).sum()), int(((x == 0) & (y == 0)).sum())]]
            r = mcnemar(tab, exact=True)
            rows.append(dict(strategy=strategy, metric="detected (McNemar exact)", first=m1, second=m2,
                             n=len(d), mean_first=x.mean(), mean_second=y.mean(), p=r.pvalue, A12=np.nan))
        for metric in ("false_positive_rate", "status_code_coverage", "validity_rate", "generation_time_ms"):
            w = rep[rep.strategy == strategy].pivot_table(index=["benchmark", "repetition"], columns="model",
                                                          values=metric, aggfunc="first").dropna()
            if {m1, m2} <= set(w.columns):
                x, y = w[m1].astype(float), w[m2].astype(float)
                p = 1.0 if np.allclose(x, y) else stats.wilcoxon(x, y, zero_method="zsplit").pvalue
                rows.append(dict(strategy=strategy, metric=f"{metric} (Wilcoxon)", first=m1, second=m2,
                                 n=len(w), mean_first=x.mean(), mean_second=y.mean(), p=p, A12=a12(x, y)))
    out = pd.DataFrame(rows)
    if len(out):
        out["p_holm"] = multipletests(out["p"], method="holm")[1]
        out["significant"] = out["p_holm"] < ALPHA
    return out


def main(manifest_path=MANIFEST, out_dir=RESULTS):
    with open(manifest_path, encoding="utf-8") as f:
        manifest = json.load(f)
    _BASE.insert(0, os.path.dirname(os.path.abspath(manifest_path)))
    os.makedirs(out_dir, exist_ok=True)
    det, rep = load(manifest)
    det.to_csv(os.path.join(out_dir, "detections_long.csv"), index=False)
    rep.to_csv(os.path.join(out_dir, "rep_metrics.csv"), index=False)
    outputs = {
        "summary_bdr": summary_bdr(det),
        "tests_detection": tests_detection(det),
        "tests_metrics": tests_metrics(rep),
        "tests_models": tests_models(det, rep),
    }
    for name, df in outputs.items():
        df.to_csv(os.path.join(out_dir, f"{name}.csv"), index=False)
    pd.set_option("display.width", 200, "display.max_columns", 20)
    print(outputs["summary_bdr"][outputs["summary_bdr"].benchmark == "ALL"].to_string(index=False))
    print(outputs["tests_detection"].round(4).to_string(index=False))
    return outputs


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--manifest", default=MANIFEST)
    ap.add_argument("--out", default=RESULTS)
    a = ap.parse_args()
    main(a.manifest, a.out)
