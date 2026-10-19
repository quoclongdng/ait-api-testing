"""Figures 3-6 of the paper (text width about 125 mm). Reads paper/results/*.csv and writes
paper/figures/FigN_*.pdf and .png (600 dpi).

    python paper/analysis/make_figures.py
"""
import csv
import glob
import os

import matplotlib as mpl
import matplotlib.pyplot as plt
import numpy as np
import pandas as pd

HERE = os.path.dirname(os.path.abspath(__file__))
RES = os.path.join(HERE, "..", "results")
OUT = os.environ.get("FIG_OUT", os.path.join(HERE, "..", "figures"))
ST_RAW = os.environ.get("ST_RAW", os.path.join(HERE, "..", "schemathesis", "raw"))
W = 125  # mm

CFG = ["RULE", "LLM-7B", "LLM-30B", "HYBRID-7B", "HYBRID-30B", "Schemathesis"]
OKABE = ["#000000", "#E69F00", "#D55E00", "#56B4E9", "#0072B2", "#009E73"]
COLOR = dict(zip(CFG, OKABE))
MARK = dict(zip(CFG, ["s", "o", "o", "D", "D", "^"]))
BENCH = {"simple-store": "simple-store", "ecommerce-order": "ecommerce-order",
         "appointment-booking": "appointment-booking"}
CAT_LABEL = {"SCHEMA_MISMATCH": "Schema mismatch", "STATUS_CODE_WRONG": "Wrong status code",
             "VALIDATION_MISSING": "Missing validation", "BOUNDARY_ERROR": "Boundary error",
             "AUTH_ERROR": "Authorization", "SEQUENCE_ERROR": "Sequence",
             "BUSINESS_RULE_VIOLATION": "Business rule"}


def setup():
    """Journal style: 8 pt sans-serif, thin axes, no top/right spines, embedded TrueType fonts."""
    fs = 8
    mpl.rcParams.update({
        "font.family": "sans-serif",
        "font.sans-serif": ["Arial", "Helvetica", "Liberation Sans", "DejaVu Sans"],
        "font.size": fs, "axes.labelsize": fs, "axes.titlesize": fs + 1,
        "xtick.labelsize": fs - 1, "ytick.labelsize": fs - 1, "legend.fontsize": fs - 1,
        "axes.linewidth": 0.6, "lines.linewidth": 1.0, "patch.linewidth": 0.6,
        "xtick.major.width": 0.6, "ytick.major.width": 0.6, "xtick.major.size": 3, "ytick.major.size": 3,
        "axes.spines.top": False, "axes.spines.right": False, "legend.frameon": False,
        "savefig.dpi": 600, "savefig.bbox": "tight", "savefig.pad_inches": 0.02,
        "pdf.fonttype": 42, "ps.fonttype": 42, "figure.constrained_layout.use": True,
    })


def figure(width, height):
    """Figure of width x height millimetres."""
    return plt.subplots(figsize=(width / 25.4, height / 25.4))


def save(fig, name):
    os.makedirs(OUT, exist_ok=True)
    for ext in ("pdf", "png"):
        fig.savefig(os.path.join(OUT, f"{name}.{ext}"), facecolor="white")
    plt.close(fig)
    print("saved", os.path.join(OUT, name) + ".{pdf,png}")


def cfg_name(row):
    if row["strategy"] == "SCHEMATHESIS":
        return "Schemathesis"
    if row["strategy"] == "RULE":
        return "RULE"
    return f"{row['strategy']}-{row['model'].split(':')[1].upper()}"


def load():
    det = pd.read_csv(os.path.join(RES, "detections_long.csv"))
    det["cfg"] = det.apply(cfg_name, axis=1)
    det = det.drop_duplicates(["cfg", "benchmark", "bug", "repetition"])
    rep = pd.read_csv(os.path.join(RES, "rep_metrics.csv"))
    rep["cfg"] = rep.apply(cfg_name, axis=1)
    rep = rep.drop_duplicates(["cfg", "benchmark", "repetition"])
    return det, rep


def fig3_bdr(det):
    per = det.groupby(["cfg", "benchmark", "repetition"])["detected"].mean().reset_index()
    pooled = det.groupby(["cfg", "repetition"])["detected"].mean().reset_index().assign(benchmark="Pooled")
    per = pd.concat([per, pooled])
    groups = list(BENCH) + ["Pooled"]
    fig, ax = figure(W, 62)
    bw = 0.13
    for i, c in enumerate(CFG):
        for g_i, g in enumerate(groups):
            v = per[(per.cfg == c) & (per.benchmark == g)]["detected"].values * 100
            x = g_i + (i - 2.5) * bw
            ax.bar(x, v.mean(), bw * 0.92, color=COLOR[c], edgecolor="black", linewidth=0.4,
                   label=c if g_i == 0 else None)
            ax.errorbar(x, v.mean(), yerr=v.std(ddof=1), color="black", lw=0.6, capsize=1.2)
    ax.set_xticks(range(len(groups)), [g.replace("-", "-\n", 1) if g != "Pooled" else g for g in groups])
    ax.set_ylabel("Bug detection rate (%)")
    ax.set_ylim(0, 100)
    ax.legend(ncol=3, frameon=False, fontsize=6, loc="upper right")
    save(fig, "Fig3_bdr")


def fig4_fpr_bdr(rep, det):
    pooled = det.groupby(["cfg", "repetition"])["detected"].mean().rename("bdr").reset_index()
    fpr = rep[rep.cfg != "Schemathesis"].groupby(["cfg", "repetition"])["false_positive_rate"].mean().rename("fpr").reset_index()
    d = pooled.merge(fpr, on=["cfg", "repetition"])
    fig, ax = figure(W * 0.62, 58)
    for c in CFG[:-1]:
        s = d[d.cfg == c]
        ax.scatter(s.fpr * 100, s.bdr * 100, s=12, marker=MARK[c], color=COLOR[c], alpha=0.45, lw=0.3,
                   edgecolor="white")
        ax.scatter(s.fpr.mean() * 100, s.bdr.mean() * 100, s=34, marker=MARK[c], facecolor="none",
                   edgecolor=COLOR[c], lw=1.2, label=c)
    ax.set_xlabel("False positive rate on clean API (%)")
    ax.set_ylabel("Bug detection rate (%)")
    ax.set_xlim(0, 45)
    ax.set_ylim(0, 70)
    ax.legend(frameon=False, fontsize=6, loc="upper right")
    save(fig, "Fig4_fpr_vs_bdr")


def schemathesis_seconds_per_seed():
    tot = {}
    for f in glob.glob(os.path.join(ST_RAW, "*", "timings.csv")):
        with open(f, encoding="utf-8") as fh:
            for r in csv.DictReader(fh):
                tot[r["seed"]] = tot.get(r["seed"], 0.0) + float(r["seconds"])
    return np.mean(list(tot.values())) if tot else np.nan


def fig5_cost(rep, det):
    pooled = det.groupby("cfg")["detected"].mean() * 100
    t = rep[rep.cfg != "Schemathesis"].groupby(["cfg", "repetition"])["generation_time_ms"].sum() / 1000
    t = t.groupby("cfg").mean()
    t["Schemathesis"] = schemathesis_seconds_per_seed()
    fig, ax = figure(W * 0.62, 58)
    for c in CFG:
        ax.scatter(max(t[c], 1), pooled[c], s=46, marker=MARK[c], color=COLOR[c], edgecolor="black", lw=0.6)
        off = {"HYBRID-7B": (-6, -12), "HYBRID-30B": (-10, 7), "LLM-7B": (4, -10)}.get(c, (4, 3))
        ax.annotate(c, (max(t[c], 1), pooled[c]), xytext=off, textcoords="offset points", fontsize=6,
                    ha="right" if c == "HYBRID-7B" else "left")
    for s in ("LLM", "HYBRID"):
        a, b = f"{s}-7B", f"{s}-30B"
        ax.annotate("", xy=(t[b], pooled[b]), xytext=(t[a], pooled[a]),
                    arrowprops=dict(arrowstyle="->", lw=0.6, color="0.4"))
    ax.set_xscale("log")
    ax.set_xlabel("Time per repetition, 3 APIs (s, log scale)")
    ax.set_ylabel("Pooled bug detection rate (%)")
    ax.set_ylim(0, 70)
    save(fig, "Fig5_cost_vs_bdr")
    return t, pooled


def fig6_heatmap(det):
    m = det.groupby(["category", "cfg"])["detected"].mean().unstack()[CFG] * 100
    n = det[det.cfg == "RULE"].groupby("category")["bug"].nunique()
    order = ["SCHEMA_MISMATCH", "STATUS_CODE_WRONG", "VALIDATION_MISSING", "BOUNDARY_ERROR",
             "AUTH_ERROR", "SEQUENCE_ERROR", "BUSINESS_RULE_VIOLATION"]
    m = m.loc[order]
    fig, ax = figure(W, 62)
    im = ax.imshow(m.values, cmap="cividis", vmin=0, vmax=100, aspect="auto")
    ax.set_xticks(range(len(CFG)), CFG, rotation=30, ha="right")
    ax.set_yticks(range(len(order)), [f"{CAT_LABEL[c]} (n={n[c]})" for c in order])
    for i in range(m.shape[0]):
        for j in range(m.shape[1]):
            v = m.values[i, j]
            ax.text(j, i, f"{v:.0f}", ha="center", va="center", fontsize=6,
                    color="white" if v < 55 else "black")
    cb = fig.colorbar(im, ax=ax, fraction=0.04, pad=0.02)
    cb.set_label("Detection rate (%)")
    ax.tick_params(length=0)
    for s in ax.spines.values():
        s.set_visible(False)
    save(fig, "Fig6_category_heatmap")


def main():
    setup()
    os.makedirs(OUT, exist_ok=True)
    det, rep = load()
    fig3_bdr(det)
    fig4_fpr_bdr(rep, det)
    t, pooled = fig5_cost(rep, det)
    fig6_heatmap(det)
    print("Time (s per repetition, 3 APIs):", t.round(1).to_dict())
    print("Pooled BDR (%):", pooled.round(1).to_dict())


if __name__ == "__main__":
    main()
