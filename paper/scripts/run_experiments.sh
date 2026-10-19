#!/usr/bin/env bash
# Main experiment of the paper: RULE / LLM / HYBRID x 3 benchmarks x 5 repetitions, for each model,
# run one after the other with docker/experiment/run-experiment.sh (protocol of ADR-0005/0006 unchanged;
# only the model, the number of repetitions and the seeds differ from the platform defaults).
# Each (model, benchmark) gives one folder benchmark/results/<UTC>/ and one row of the manifest.
#
# Requirements: Docker, and an Ollama server with a 16384-token context, e.g.
#   OLLAMA_HOST=127.0.0.1:11435 OLLAMA_CONTEXT_LENGTH=16384 ollama serve
#   ollama pull qwen2.5-coder:7b && ollama pull qwen3-coder:30b
# Run from the repository root (bash; Git Bash on Windows):
#   bash paper/scripts/run_experiments.sh
#   MODELS="qwen3-coder:30b" bash paper/scripts/run_experiments.sh
# Measured on an i7-12700K + RTX 3070 8 GB: about 4-6 h for the 7B model, 10-15 h for the 30B model.
set -uo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
MANIFEST=${MANIFEST:-$ROOT/paper/results/manifest.new.json}
LOGDIR=${LOGDIR:-$ROOT/paper/logs}
MODELS=${MODELS:-"qwen2.5-coder:7b qwen3-coder:30b"}
BENCHMARKS=${BENCHMARKS:-"simple-store ecommerce-order appointment-booking"}
REPETITIONS=${REPETITIONS:-5}
SEEDS=${SEEDS:-42,1024,2026,2027,2028}
OLLAMA_PORT=${OLLAMA_PORT:-11435}
PY=${PY:-python}

mkdir -p "$LOGDIR" "$(dirname "$MANIFEST")"
[ -f "$MANIFEST" ] || echo '{"runs": []}' > "$MANIFEST"

for model in $MODELS; do
  for bench in $BENCHMARKS; do
    tag="${model//[:\/]/_}__${bench}"
    log="$LOGDIR/llm_${tag}.log"
    if "$PY" -c "import json,sys; m=json.load(open(sys.argv[1],encoding='utf-8')); sys.exit(0 if any(r['model']==sys.argv[2] and r['benchmark']==sys.argv[3] for r in m['runs']) else 1)" "$MANIFEST" "$model" "$bench"; then
      echo "skip $model x $bench (already in the manifest)"; continue
    fi
    echo "==> $(date '+%F %T') $model x $bench (log: $log)"
    ( cd "$ROOT" && MSYS_NO_PATHCONV=1 \
        STRATEGIES=RULE,LLM,HYBRID BENCHMARKS="$bench" REPETITIONS="$REPETITIONS" SEEDS="$SEEDS" \
        MODEL="$model" TEMPERATURE=0.2 OLLAMA=native \
        OLLAMA_URL="http://127.0.0.1:$OLLAMA_PORT" LLM_BASE_URL="http://host.docker.internal:$OLLAMA_PORT/v1" \
        bash docker/experiment/run-experiment.sh ) > "$log" 2>&1
    rc=$?
    dir=$(grep -o "benchmark/results/[0-9]\{8\}T[0-9]\{6\}Z" "$log" | tail -1)
    echo "    exit code $rc, folder ${dir:-<none>}"
    if [ -n "$dir" ]; then
      "$PY" - "$MANIFEST" "$model" "$bench" "$dir" "$rc" <<'EOF'
import json, sys
path, model, bench, d, rc = sys.argv[1:]
m = json.load(open(path, encoding="utf-8"))
m["runs"].append({"model": model, "benchmark": bench, "dir": d, "exit_code": int(rc)})
json.dump(m, open(path, "w", encoding="utf-8"), indent=1, ensure_ascii=False)
EOF
    fi
  done
done
echo "==> $(date '+%F %T') done. Manifest: $MANIFEST"
