# Reproducing the paper

Material for *Rules First, LLMs Second: Rule-Based, LLM-Based and Hybrid REST API Test Generation with Local Models and a Deterministic Oracle* (Nguyen Quoc Long, Duy Tan University).

The experiment compares RULE, LLM and HYBRID generation (two local models, `qwen2.5-coder:7b` and `qwen3-coder:30b`) and a Schemathesis baseline on the 3 benchmark APIs of this repository (32 seeded faults), with 5 repetitions per configuration.

## Content

| Path | Content |
|---|---|
| `runs/<UTC>/` | Exports of the 21 experiment runs listed in the manifests: `experiments.json` (every case, verdict and metric), `experiments.csv`, `experiment-<id>.json/csv`, `summary.csv`, `summary.md`, `environment.txt` (parameters of the run). The 6 main runs also include `db.sql.gz` (PostgreSQL dump, needed for the LLM failure taxonomy) |
| `schemathesis/raw/` | Schemathesis JSON reports, default credential: `<benchmark>/seed-<s>/{clean,BUG-xxx}.json`, `timings.csv` |
| `schemathesis/null/` | Second clean run per seed (noise of the clean baseline) |
| `schemathesis/roles/` | Ablation: Schemathesis with the token of every other role |
| `results/` | `manifest.json` (main runs), `manifest_ablation.json`, `manifest_ablation_failed.json` (excluded attempt), and every CSV reported in the paper |
| `analysis/` | Analysis scripts (Python) |
| `scripts/` | `run_experiments.sh` (main experiment), `run_ablations.sh` |
| `patches/` | Code changes of the ablations: `rule-cap20.patch`, `no-business-rules.patch` |

Every run used the platform code of this release. The `git_commit` in `environment.txt` refers to the development repository the release was prepared from; this release differs from that commit only in files that take no part in the experiments (deployment configuration, public landing pages, internal project documents and their tests).

## Re-running the analysis (minutes, no Docker needed)

Python 3.11 or later:

```bash
python -m venv .venv
source .venv/bin/activate            # Windows: .venv\Scripts\activate
pip install -r paper/requirements.txt
python paper/analysis/analyze_stats.py          # detections_long, rep_metrics, summary_bdr, tests_*.csv
python paper/analysis/fault_level.py            # fault-level BDR, bootstrap CIs, sign and Wilcoxon tests
python paper/analysis/hybrid_llm_part.py        # LLM part of HYBRID, faults per category
python paper/analysis/schemathesis_baseline.py parse
python paper/analysis/schemathesis_roles.py parse
python paper/analysis/ablation_analysis.py      # ablation_*.csv
python paper/analysis/make_figures.py           # paper/figures/Fig3-Fig6 (.pdf, .png)
```

The scripts overwrite `paper/results/*.csv`; `git diff --ignore-space-at-eol paper/results` shows any difference from the published values. On the machine of the paper (Windows) the regenerated files are byte-for-byte identical; on Linux or macOS only the line endings differ.

### LLM failure taxonomy (needs Docker)

The classification of invalid LLM cases reads the database dumps of the 6 main runs, loaded into a throwaway PostgreSQL container (bash):

```bash
docker run -d --name p07-pg -e POSTGRES_PASSWORD=p07 -e POSTGRES_USER=p07 postgres:16
python -c "import json;[print(('r_'+r['model'].split(':')[1]+'_'+r['benchmark'].replace('-','_'))+'|'+r['dir']) for r in json.load(open('paper/results/manifest.json'))['runs']]" \
  | while IFS='|' read db dir; do dir=${dir%$'\r'}; docker exec p07-pg createdb -U p07 $db; \
      gunzip -c "$dir/db.sql.gz" | grep -v -E '^\\(un)?restrict' | MSYS_NO_PATHCONV=1 docker exec -i p07-pg psql -q -U p07 -d $db; done
python paper/analysis/llm_failure_taxonomy.py   # llm_invalid_by_class.csv, llm_repair_summary.csv
docker rm -f p07-pg
```

## Re-running the experiment (hours, GPU recommended)

Measured on Windows 11 (Git Bash), Intel i7-12700K, 64 GB RAM, NVIDIA RTX 3070 8 GB, Docker Desktop 29.5, Ollama 0.30.11.

1. Start Ollama on port 11435 with a 16384-token context and pull both models:
   ```bash
   OLLAMA_HOST=127.0.0.1:11435 OLLAMA_CONTEXT_LENGTH=16384 OLLAMA_KEEP_ALIVE=30m ollama serve
   OLLAMA_HOST=127.0.0.1:11435 ollama pull qwen2.5-coder:7b
   OLLAMA_HOST=127.0.0.1:11435 ollama pull qwen3-coder:30b
   ```
   Stop any other process that keeps a model on the GPU (`ollama ps`), otherwise the generation times are distorted.
2. Quick check without an LLM (about 3 minutes; RULE must detect 8 of the 10 faults of simple-store with no false positive):
   ```bash
   STRATEGIES=RULE BENCHMARKS=simple-store REPETITIONS=3 bash docker/experiment/run-experiment.sh
   ```
3. Main experiment, RULE/LLM/HYBRID x 3 benchmarks x 5 repetitions for each model (measured: about 1.5 h for the 7B model and 4 h for the 30B model):
   ```bash
   bash paper/scripts/run_experiments.sh        # writes paper/results/manifest.new.json
   ```
4. Schemathesis baseline (`schemathesis/schemathesis:v4.28.0`, 100 examples per operation, phases examples, coverage, fuzzing and stateful, every check; measured about 68 minutes). The experiment stack must be running (`make up-experiment`):
   ```bash
   python paper/analysis/schemathesis_baseline.py run
   ```
   For the second clean run, call `st_driver.py` with `--bugs ""` and `ST_NULL` as output folder (see the docstring of `schemathesis_baseline.py`).
5. Ablations (about 6 h): RULE with 20 cases per operation, HYBRID without business rules (7B and 30B), Schemathesis with every role. Each patch is applied for its runs and reverted afterwards:
   ```bash
   bash paper/scripts/run_ablations.sh          # writes paper/results/manifest_ablation.new.json
   ```

To analyse new runs, pass the new manifest (`analyze_stats.py --manifest paper/results/manifest.new.json`) or replace `manifest.json`. Do not run Schemathesis and `run-experiment.sh` at the same time: both use the same sandboxes, and `run-experiment.sh` resets the database and restarts the stack. Do not let the machine sleep during a run.

LLM output is not bit-for-bit reproducible across hardware and Ollama versions even with a fixed seed and temperature 0.2, so new runs give close but not identical numbers; RULE and the analysis of the published runs are deterministic.

## Protocol

- Parameters: `analysis/params.py` (models, seeds `42,1024,2026,2027,2028`, temperature 0.2, Schemathesis settings, alpha = 0.05).
- Generation protocol of the platform (ADR-0005/0006): 300 s per LLM call, 1320 s budget per generation job, 3 cases per GENERATE call, at most 2 REPAIR calls per case, sandbox reset before every case.
- Detection rule (D14): a fault is detected when a case passes on the clean API and fails or errors on the variant with that fault only. For Schemathesis, a "case" is an (operation, check) pair; see the docstring of `analysis/schemathesis_baseline.py`.
- Statistics: the fault is the unit of analysis (n = 32); cluster bootstrap confidence intervals over faults (10 000 resamples), sign test and Wilcoxon signed-rank test with Holm correction; McNemar, Cochran's Q and Vargha-Delaney A12 are reported as descriptive statistics.
