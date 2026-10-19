#!/usr/bin/env bash
# Ablations of the paper, run one after the other on the same Docker stack:
#   1) RULE with a cap of 20 cases per operation   - paper/patches/rule-cap20.patch        (no LLM)
#   2) HYBRID without business rules, 7B and 30B  - paper/patches/no-business-rules.patch (Ollama on $OLLAMA_PORT)
#   3) Schemathesis with the tokens of every role  - unpatched code (uses the sandboxes only)
# Each patch is applied to the working tree for its runs and reverted afterwards, so start from a clean
# checkout (git status shows no change to web/). Results: benchmark/results/<UTC>/, listed in the manifest.
#   bash paper/scripts/run_ablations.sh      (from the repository root)
set -uo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
MANIFEST=${MANIFEST:-$ROOT/paper/results/manifest_ablation.new.json}
LOGDIR=${LOGDIR:-$ROOT/paper/logs}
OLLAMA_PORT=${OLLAMA_PORT:-11435}
SEEDS=42,1024,2026,2027,2028
PY=${PY:-python}
mkdir -p "$LOGDIR"
[ -f "$MANIFEST" ] || echo '{"runs": []}' > "$MANIFEST"

if [ -n "$(git -C "$ROOT" status --porcelain -- web)" ]; then
  echo "web/ has local changes; commit or stash them first" >&2; exit 1
fi

run_one() {  # tag patch strategies model bench
  local tag=$1 patch=$2 strategies=$3 model=$4 bench=$5
  if "$PY" -c "import json,sys; m=json.load(open(sys.argv[1],encoding='utf-8')); sys.exit(0 if any(r['tag']==sys.argv[2] and r['benchmark']==sys.argv[3] for r in m['runs']) else 1)" "$MANIFEST" "$tag" "$bench"; then
    echo "skip $tag x $bench (already in the manifest)"; return
  fi
  git -C "$ROOT" apply "paper/patches/$patch" || { echo "cannot apply $patch"; exit 1; }
  local log="$LOGDIR/abl_${tag}__${bench}.log"
  echo "==> $(date '+%F %T') $tag x $bench"
  ( cd "$ROOT" && MSYS_NO_PATHCONV=1 STRATEGIES="$strategies" BENCHMARKS="$bench" REPETITIONS=5 SEEDS="$SEEDS" \
      MODEL="$model" TEMPERATURE=0.2 OLLAMA=native \
      OLLAMA_URL="http://127.0.0.1:$OLLAMA_PORT" LLM_BASE_URL="http://host.docker.internal:$OLLAMA_PORT/v1" \
      bash docker/experiment/run-experiment.sh ) > "$log" 2>&1
  local rc=$? dir
  git -C "$ROOT" apply -R "paper/patches/$patch"
  dir=$(grep -o "benchmark/results/[0-9]\{8\}T[0-9]\{6\}Z" "$log" | tail -1)
  echo "    exit code $rc, ${dir:-<no folder>}"
  [ -n "$dir" ] && "$PY" - "$MANIFEST" "$tag" "$bench" "$model" "$dir" "$rc" <<'EOF'
import json, sys
p, tag, bench, model, d, rc = sys.argv[1:]
m = json.load(open(p, encoding="utf-8"))
m["runs"].append({"tag": tag, "benchmark": bench, "model": model, "dir": d, "exit_code": int(rc)})
json.dump(m, open(p, "w", encoding="utf-8"), indent=1, ensure_ascii=False)
EOF
}

for b in simple-store ecommerce-order appointment-booking; do
  run_one RULE-cap20 rule-cap20.patch RULE qwen2.5-coder:7b "$b"
done
for model in qwen2.5-coder:7b qwen3-coder:30b; do
  for b in simple-store ecommerce-order appointment-booking; do
    run_one "HYBRID-noBR-${model##*:}" no-business-rules.patch HYBRID "$model" "$b"
  done
done
echo "==> $(date '+%F %T') Schemathesis, every role"
( cd "$ROOT" && "$PY" paper/analysis/schemathesis_roles.py run ) > "$LOGDIR/abl_schemathesis_roles.log" 2>&1
echo "    exit code $?"
echo "==> $(date '+%F %T') done"
