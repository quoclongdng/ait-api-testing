"""Isolate the LLM part of HYBRID.

RULE is deterministic: the RULE cases inside HYBRID equal the RULE run of the same benchmark (same cases, same clean verdicts).
Hence, per block (benchmark, repetition):
    valid_LLM  = valid_HYBRID - valid_RULE
    FP_LLM     = FPR_HYBRID * valid_HYBRID - FPR_RULE * valid_RULE
    FPR_LLM    = FP_LLM / valid_LLM
    tok_LLM    = (prompt + completion tokens of HYBRID) / valid_LLM
Output: results/hybrid_llm_part.csv and the faults per category x benchmark (results/faults_by_category.csv).
Assumption: every VALID case is executed (executable rate = 100% in every configuration).
"""
import os

import pandas as pd
import yaml

from params import AIT_REPO, BENCHMARKS

HERE = os.path.dirname(os.path.abspath(__file__))
RES = os.path.join(HERE, "..", "results")


def main():
    r = pd.read_csv(os.path.join(RES, "rep_metrics.csv"))
    r = r[r.strategy != "SCHEMATHESIS"].drop_duplicates(["model", "strategy", "benchmark", "repetition"])
    rule = r[r.strategy == "RULE"].set_index(["model", "benchmark", "repetition"])
    rows = []
    for _, h in r[r.strategy == "HYBRID"].iterrows():
        k = (h.model, h.benchmark, h.repetition)
        ru = rule.loc[k]
        v_llm = h.valid - ru.valid
        fp_llm = h.false_positive_rate * h.valid - ru.false_positive_rate * ru.valid
        rows.append(dict(model=h.model, benchmark=h.benchmark, repetition=h.repetition, valid_llm=v_llm,
                         fp_llm=fp_llm, fpr_llm=fp_llm / v_llm if v_llm else float("nan"),
                         tokens=h.prompt_tokens + h.completion_tokens,
                         tok_per_llm_valid=(h.prompt_tokens + h.completion_tokens) / v_llm if v_llm else float("nan")))
    d = pd.DataFrame(rows)
    d.to_csv(os.path.join(RES, "hybrid_llm_part.csv"), index=False)
    pooled = d.groupby("model").agg(valid_llm=("valid_llm", "sum"), fp_llm=("fp_llm", "sum"), tokens=("tokens", "sum"))
    pooled["fpr_llm_pooled"] = pooled.fp_llm / pooled.valid_llm
    pooled["tok_per_llm_valid"] = pooled.tokens / pooled.valid_llm
    print(pooled.round(3).to_string())
    print(d.groupby("model")[["fpr_llm", "tok_per_llm_valid"]].agg(["mean", "std"]).round(3).to_string())

    rows = []
    for slug in BENCHMARKS:
        with open(os.path.join(AIT_REPO, "benchmark", slug, "ground-truth.yaml"), encoding="utf-8") as f:
            for b in yaml.safe_load(f)["bugs"]:
                rows.append(dict(benchmark=slug, category=b["category"]))
    cat = pd.crosstab(pd.DataFrame(rows).category, pd.DataFrame(rows).benchmark)[BENCHMARKS]
    cat.to_csv(os.path.join(RES, "faults_by_category.csv"))
    print(cat.to_string())


if __name__ == "__main__":
    main()
