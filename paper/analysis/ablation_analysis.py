"""Ablation analysis (fault as the unit, as in fault_level.py).

Compared configurations:
  RULE (cap 10) | RULE-cap20 | HYBRID-7B/30B | HYBRID-noBR-7B/30B | Schemathesis (default) | Schemathesis (all roles)
Input: results/manifest.json (main runs), results/manifest_ablation.json, results/schemathesis_*.csv.
Output: results/ablation_bdr.csv, results/ablation_tests.csv, results/ablation_by_category.csv, results/ablation_rep_metrics.csv
"""
import json
import os

import numpy as np
import pandas as pd
from scipy import stats
from statsmodels.stats.multitest import multipletests

from params import AIT_REPO, SEED

HERE = os.path.dirname(os.path.abspath(__file__))
RES = os.path.join(HERE, "..", "results")
B = 10_000


def read_runs(manifest, label_fn):
    det, rep = [], []
    for run in json.load(open(os.path.join(RES, manifest), encoding="utf-8"))["runs"]:
        data = json.load(open(os.path.join(AIT_REPO, run["dir"], "experiments.json"), encoding="utf-8"))
        for e in data["experiments"]:
            if e["status"] != "COMPLETED":
                continue
            cfg = label_fn(run, e["strategy"])
            if cfg is None:
                continue
            for b in e["bug_detections"]:
                det.append(dict(cfg=cfg, benchmark=e["benchmark"]["slug"], repetition=b["repetition"], bug=b["bug_code"],
                                category=b["category"], detected=int(bool(b["detected"]))))
            for r in e["repetitions"]:
                m = r["metrics"]
                rep.append(dict(cfg=cfg, benchmark=e["benchmark"]["slug"], repetition=r["repetition"],
                                **{k: m.get(k) for k in ("false_positive_rate", "validity_rate", "status_code_coverage",
                                                         "generated", "valid", "bugs_detected", "generation_time_ms",
                                                         "prompt_tokens", "completion_tokens")}))
    return pd.DataFrame(det), pd.DataFrame(rep)


def main():
    rng = np.random.default_rng(SEED)
    main_det, main_rep = read_runs("manifest.json", lambda run, s: "RULE" if s == "RULE" and run["model"].endswith("7b")
                                   else (f"{s}-{run['model'].split(':')[1].upper()}" if s == "HYBRID" else None))
    abl_det, abl_rep = read_runs("manifest_ablation.json",
                                 lambda run, s: run["tag"].replace("-7b", "-7B").replace("-30b", "-30B"))
    st = pd.read_csv(os.path.join(RES, "schemathesis_detections.csv")).assign(cfg="Schemathesis")
    frames = [main_det, abl_det, st[["cfg", "benchmark", "repetition", "bug", "category", "detected"]]]
    roles_path = os.path.join(RES, "schemathesis_roles_detections.csv")
    if os.path.exists(roles_path):
        sr = pd.read_csv(roles_path).assign(cfg="Schemathesis-roles")
        frames.append(sr[["cfg", "benchmark", "repetition", "bug", "category", "detected"]])
    det = pd.concat(frames, ignore_index=True).drop_duplicates(["cfg", "benchmark", "bug", "repetition"])
    det["fault"] = det.benchmark + "/" + det.bug
    pf = det.groupby(["fault", "category", "cfg"]).detected.mean().unstack("cfg")
    cfgs = [c for c in ["RULE", "RULE-cap20", "HYBRID-7B", "HYBRID-noBR-7B", "HYBRID-noBR-7B-run1", "HYBRID-30B",
                        "HYBRID-noBR-30B", "Schemathesis", "Schemathesis-roles"] if c in pf.columns]

    def ci(x):
        m = x[rng.integers(0, len(x), size=(B, len(x)))].mean(axis=1)
        return np.percentile(m, [2.5, 97.5]) * 100

    rows = []
    for c in cfgs:
        x = pf[c].values
        lo, hi = ci(x)
        rows.append(dict(cfg=c, bdr=x.mean() * 100, ci_lo=lo, ci_hi=hi, faults_ever=int((x > 0).sum())))
    bdr = pd.DataFrame(rows)
    pairs = [("RULE-cap20", "RULE"), ("HYBRID-7B", "RULE-cap20"), ("HYBRID-30B", "RULE-cap20"),
             ("HYBRID-noBR-7B", "HYBRID-7B"), ("HYBRID-noBR-30B", "HYBRID-30B"),
             ("HYBRID-noBR-7B", "RULE"), ("HYBRID-noBR-30B", "RULE"), ("HYBRID-noBR-7B-run1", "HYBRID-7B"),
             ("HYBRID-noBR-7B", "RULE-cap20"), ("HYBRID-noBR-30B", "RULE-cap20"),
             ("Schemathesis-roles", "Schemathesis"), ("HYBRID-7B", "Schemathesis-roles"), ("HYBRID-30B", "Schemathesis-roles")]
    trows = []
    for a, b in pairs:
        if a not in pf.columns or b not in pf.columns:
            continue
        d = (pf[a] - pf[b]).values
        lo, hi = ci(d)
        pos, neg = int((d > 0).sum()), int((d < 0).sum())
        w = 1.0 if np.allclose(d, 0) else stats.wilcoxon(pf[a], pf[b], zero_method="zsplit").pvalue
        s = stats.binomtest(pos, pos + neg).pvalue if pos + neg else 1.0
        trows.append(dict(pair=f"{a} vs {b}", diff_pp=d.mean() * 100, ci_lo=lo, ci_hi=hi, better=pos, worse=neg,
                          p_sign=s, p_wilcoxon=w))
    tests = pd.DataFrame(trows)
    if len(tests):
        tests["p_holm"] = multipletests(tests.p_wilcoxon, method="holm")[1]
    bycat = (pf[cfgs] * 100).groupby(level="category").mean()
    rep = pd.concat([main_rep, abl_rep], ignore_index=True)
    rep_s = rep.groupby("cfg").agg(fpr=("false_positive_rate", "mean"), vr=("validity_rate", "mean"),
                                   scc=("status_code_coverage", "mean"), cases=("generated", "mean"),
                                   valid=("valid", "mean"), gen_s=("generation_time_ms", lambda x: x.mean() / 1000),
                                   tokens=("prompt_tokens", "mean"))
    for name, df in (("ablation_bdr", bdr), ("ablation_tests", tests), ("ablation_by_category", bycat),
                     ("ablation_rep_metrics", rep_s)):
        df.to_csv(os.path.join(RES, f"{name}.csv"))
    pd.set_option("display.width", 220)
    print(bdr.round(1).to_string(index=False)); print(tests.round(3).to_string(index=False))
    print(bycat.round(0).to_string()); print(rep_s.round(3).to_string())


if __name__ == "__main__":
    main()
