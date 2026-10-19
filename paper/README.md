# Paper data and scripts

This folder backs up the numbers in *Rules First, LLMs Second: Rule-Based, LLM-Based and Hybrid REST API Test Generation with Local Models and a Deterministic Oracle* (Nguyen Quoc Long, Duy Tan University).

Short version of the setup: RULE, LLM and HYBRID with two local models (`qwen2.5-coder:7b`, `qwen3-coder:30b`), plus Schemathesis as a baseline, on the 3 benchmark APIs of this repo (32 seeded bugs), 5 repetitions each.

```
runs/<UTC>/      exports of the 21 runs in the manifests (experiments.json has every case and verdict);
                 the 6 main runs also have db.sql.gz, needed for the LLM failure table
schemathesis/    raw/ = default credential, null/ = second clean run, roles/ = one run per role
results/         manifests and every CSV the paper reports
analysis/        Python scripts that produce results/ and figures/
scripts/         run_experiments.sh, run_ablations.sh
patches/         the two code changes used in the ablations
figures/         Fig. 3–6
```

All runs used the platform code in this repo. `environment.txt` in each run has a `git_commit` that points to our internal repository; this release is that commit minus files that have nothing to do with the experiments (deployment config, a landing page, internal docs and their tests).

## Checking the numbers (a few minutes, no Docker)

Python 3.11+:

```bash
python -m venv .venv
source .venv/bin/activate        # Windows: .venv\Scripts\activate
pip install -r paper/requirements.txt

python paper/analysis/analyze_stats.py
python paper/analysis/fault_level.py
python paper/analysis/hybrid_llm_part.py
python paper/analysis/schemathesis_baseline.py parse
python paper/analysis/schemathesis_roles.py parse
python paper/analysis/ablation_analysis.py
python paper/analysis/make_figures.py
```

The scripts overwrite the CSVs in `results/`, so `git diff --ignore-space-at-eol paper/results` shows whether anything changed. On our Windows machine the output is byte-for-byte the same. On Linux and macOS only the line endings differ.

The table of invalid LLM cases needs the database dumps, loaded into a throwaway Postgres container:

```bash
docker run -d --name p07-pg -e POSTGRES_PASSWORD=p07 -e POSTGRES_USER=p07 postgres:16
python -c "import json;[print(('r_'+r['model'].split(':')[1]+'_'+r['benchmark'].replace('-','_'))+'|'+r['dir']) for r in json.load(open('paper/results/manifest.json'))['runs']]" \
  | while IFS='|' read db dir; do dir=${dir%$'\r'}; docker exec p07-pg createdb -U p07 $db; \
      gunzip -c "$dir/db.sql.gz" | grep -v -E '^\\(un)?restrict' | MSYS_NO_PATHCONV=1 docker exec -i p07-pg psql -q -U p07 -d $db; done
python paper/analysis/llm_failure_taxonomy.py
docker rm -f p07-pg
```

## Running the experiment again (hours, a GPU helps)

Our machine: Windows 11 with Git Bash, i7-12700K, 64 GB RAM, RTX 3070 8 GB, Docker Desktop 29.5, Ollama 0.30.11.

Start Ollama on port 11435 with a 16k context and pull both models:

```bash
OLLAMA_HOST=127.0.0.1:11435 OLLAMA_CONTEXT_LENGTH=16384 OLLAMA_KEEP_ALIVE=30m ollama serve
OLLAMA_HOST=127.0.0.1:11435 ollama pull qwen2.5-coder:7b
OLLAMA_HOST=127.0.0.1:11435 ollama pull qwen3-coder:30b
```

Make sure nothing else keeps a model on the GPU (`ollama ps`), or the timings will be off.

Quick check first, no model needed (about 3 minutes). RULE should find 8 of the 10 bugs in simple-store with no false positives:

```bash
STRATEGIES=RULE BENCHMARKS=simple-store REPETITIONS=3 bash docker/experiment/run-experiment.sh
```

Then:

```bash
bash paper/scripts/run_experiments.sh     # main runs: ~1.5 h with 7B, ~4 h with 30B
python paper/analysis/schemathesis_baseline.py run   # needs `make up-experiment`; ~70 min
bash paper/scripts/run_ablations.sh       # ~6 h; applies each patch and reverts it afterwards
```

New runs go into `paper/results/manifest.new.json` and `manifest_ablation.new.json`. Pass them with `--manifest` or swap them in for the old ones. For the second clean Schemathesis run, call `st_driver.py` with `--bugs ""` (see the docstring of `schemathesis_baseline.py`).

Don't run Schemathesis and `run-experiment.sh` at the same time, they share the sandboxes and the latter resets the database. Don't let the machine go to sleep mid-run either.

Expect close but not identical numbers from new LLM runs. Even with fixed seeds and temperature 0.2, output changes with hardware and Ollama version. RULE and the analysis of the published runs are deterministic.

## Settings

- `analysis/params.py`: models, seeds 42, 1024, 2026, 2027, 2028, temperature 0.2, Schemathesis settings, alpha 0.05.
- Generation (ADR-0005/0006): 300 s per LLM call, 1320 s per generation job, 3 cases per GENERATE call, up to 2 REPAIR calls per case, sandbox reset before every case.
- Schemathesis 4.28.0, 100 examples per operation, phases examples/coverage/fuzzing/stateful, all checks. A "test" for Schemathesis is an (operation, check) pair.
- Stats: one bug is one unit (n = 32). Bootstrap CIs resample bugs (10,000 times); sign test and Wilcoxon with Holm correction. McNemar, Cochran's Q and A12 are only descriptive.
