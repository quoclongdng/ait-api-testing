"""Analysis with the FAULT as the unit (n = 32), which avoids treating repetitions as independent.

For each configuration and fault: p = share of repetitions that detect the fault (0..1, 5 repetitions).
- Pairwise comparison: Wilcoxon signed-rank on p per fault (zero_method="zsplit") + sign test on sign(p_a - p_b).
- 95% confidence intervals: cluster bootstrap over faults (B = 10 000, seed 2026) for pooled BDR and differences.
- Union control: RULE ∪ LLM (a fault counts as detected in repetition r if RULE or LLM detects it).
- Sensitivity analysis: without the AUTH_ERROR and BUSINESS_RULE_VIOLATION faults (business rules and roles are read by LLM/HYBRID only).
Output: results/fault_level_bdr.csv, results/fault_level_tests.csv.
"""
import itertools
import os

import numpy as np
import pandas as pd
from scipy import stats

from params import SEED

HERE = os.path.dirname(os.path.abspath(__file__))
RES = os.path.join(HERE, "..", "results")
B = 10_000


def cfg(row):
    if row.strategy == "SCHEMATHESIS":
        return "Schemathesis"
    if row.strategy == "RULE":
        return "RULE"
    return f"{row.strategy}-{row.model.split(':')[1].upper()}"


def load():
    d = pd.read_csv(os.path.join(RES, "detections_long.csv"))
    d["cfg"] = d.apply(cfg, axis=1)
    d = d.drop_duplicates(["cfg", "benchmark", "bug", "repetition"])
    d["fault"] = d.benchmark + "/" + d.bug
    wide = d.pivot_table(index=["fault", "category", "repetition"], columns="cfg", values="detected").reset_index()
    for m in ("7B", "30B"):
        wide[f"RULE∪LLM-{m}"] = np.maximum(wide["RULE"], wide[f"LLM-{m}"])
    return wide


def per_fault(wide, cols):
    return wide.groupby(["fault", "category"])[cols].mean()


def boot_ci(x, rng):
    idx = rng.integers(0, len(x), size=(B, len(x)))
    m = x[idx].mean(axis=1)
    return np.percentile(m, [2.5, 97.5])


def main():
    rng = np.random.default_rng(SEED)
    wide = load()
    cols = ["RULE", "LLM-7B", "LLM-30B", "HYBRID-7B", "HYBRID-30B", "Schemathesis", "RULE∪LLM-7B", "RULE∪LLM-30B"]
    rows, trows = [], []
    for subset, mask in (("all 32 faults", None),
                         ("without AUTH and BUSINESS_RULE", lambda f: ~f.index.get_level_values("category").isin(
                             ["AUTH_ERROR", "BUSINESS_RULE_VIOLATION"]))):
        pf = per_fault(wide, cols)
        if mask is not None:
            pf = pf[mask(pf)]
        for c in cols:
            x = pf[c].values
            lo, hi = boot_ci(x, rng)
            rows.append(dict(subset=subset, cfg=c, n_faults=len(x), bdr=x.mean() * 100, ci_lo=lo * 100, ci_hi=hi * 100,
                             faults_ever=int((x > 0).sum()), faults_majority=int((x >= 0.6).sum())))
        pairs = [("HYBRID-7B", "RULE"), ("HYBRID-30B", "RULE"), ("HYBRID-7B", "Schemathesis"),
                 ("HYBRID-30B", "Schemathesis"), ("RULE", "Schemathesis"), ("HYBRID-7B", "RULE∪LLM-7B"),
                 ("HYBRID-30B", "RULE∪LLM-30B"), ("LLM-30B", "LLM-7B"), ("HYBRID-30B", "HYBRID-7B")]
        block = []
        for a, b in pairs:
            diff = pf[a].values - pf[b].values
            lo, hi = boot_ci(diff, rng)
            pos, neg = int((diff > 0).sum()), int((diff < 0).sum())
            w = 1.0 if np.allclose(diff, 0) else stats.wilcoxon(pf[a].values, pf[b].values, zero_method="zsplit").pvalue
            s = stats.binomtest(pos, pos + neg, 0.5).pvalue if pos + neg else 1.0
            block.append(dict(subset=subset, pair=f"{a} vs {b}", n_faults=len(diff), diff_pp=diff.mean() * 100,
                              ci_lo=lo * 100, ci_hi=hi * 100, faults_better=pos, faults_worse=neg,
                              p_wilcoxon=w, p_sign=s))
        from statsmodels.stats.multitest import multipletests
        adj = multipletests([r["p_wilcoxon"] for r in block], method="holm")[1]
        for r, p in zip(block, adj):
            r["p_wilcoxon_holm"] = p
        trows += block
    out1, out2 = pd.DataFrame(rows), pd.DataFrame(trows)
    out1.to_csv(os.path.join(RES, "fault_level_bdr.csv"), index=False)
    out2.to_csv(os.path.join(RES, "fault_level_tests.csv"), index=False)
    pd.set_option("display.width", 220)
    print(out1.round(1).to_string(index=False))
    print(out2.round(3).to_string(index=False))


if __name__ == "__main__":
    main()
