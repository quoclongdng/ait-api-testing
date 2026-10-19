#!/usr/bin/env bash
# Reproduce the experiment protocol (docs/architecture/experiment-design.md §4) end to end [S12-02].
# Run through the Makefile: `make experiment` (full protocol) or `make experiment-smoke`.
#
# Steps: check Docker/disk -> check the LLM (only when LLM/HYBRID is requested) -> start the
# experiment stack (base + 32 single-bug sandboxes + experiment overrides) -> stop the worker ->
# back up a previous run's DB -> migrate:fresh --seed -> ait:benchmarks:import -> recreate the
# worker -> ait:experiments:run -> ait:experiments:wait (bounded) -> ait:experiments:export + DB dump
# into benchmark/results/<UTC timestamp>/.
#
# Settings (environment, set by the Makefile; defaults = protocol):
#   STRATEGIES=RULE,LLM,HYBRID  BENCHMARKS=all  REPETITIONS=3  MODEL=qwen2.5-coder:7b
#   TEMPERATURE=0.2  SEEDS=42,1024,2026  RESET_PER_CASE=true  OLLAMA=native|docker  OLLAMA_URL=http://127.0.0.1:11434
#   LLM_CONTEXT_TOKENS (default: context_length reported by Ollama /api/ps, else
#   LLM_CONTEXT_TOKENS=16384)  LLM_TIMEOUT=300  GENERATE_BUDGET=1320  CASES_PER_CALL=3
#   WAIT_INTERVAL=15  WAIT_TIMEOUT (default: experiments x REPETITIONS x 3600 s)
#   RESULTS_ROOT=benchmark/results  ALLOW_SMALL_CONTEXT=0 (1 = run with an LLM context < 16384)
# Time chain (S11-02, ADR-0005): LLM_TIMEOUT < GENERATE_BUDGET <= AI_SERVICE_TIMEOUT - 60
# (AI_SERVICE_TIMEOUT = GENERATE_BUDGET + 60) < RunExperimentStepJob/GenerateTestsJob 1500 s.
set -euo pipefail

# The whole script is one { ... } block: bash parses it before running anything, so editing this
# file (e.g. git checkout) during a multi-hour run cannot corrupt the running copy.
{

cd "$(dirname "$0")/../.."
ROOT=$(pwd)

STRATEGIES=${STRATEGIES:-RULE,LLM,HYBRID}
BENCHMARKS=${BENCHMARKS:-all}
REPETITIONS=${REPETITIONS:-3}
MODEL=${MODEL:-qwen2.5-coder:7b}
TEMPERATURE=${TEMPERATURE:-0.2}
SEEDS=${SEEDS:-42,1024,2026}
# ADR-0006 addendum 2026-10-12: sandbox reset before every test case of an experiment run.
RESET_PER_CASE=${RESET_PER_CASE:-true}
OLLAMA=${OLLAMA:-native}
OLLAMA_URL=${OLLAMA_URL:-http://127.0.0.1:11434}
WAIT_INTERVAL=${WAIT_INTERVAL:-15}
WAIT_TIMEOUT=${WAIT_TIMEOUT:-}
# Upper bound of one repetition (generation <= 1500 s plus the clean and single-bug runs) used for
# the default WAIT_TIMEOUT, so a dead worker can never make the script poll forever.
WAIT_SECONDS_PER_REPETITION=3600
ALLOW_SMALL_CONTEXT=${ALLOW_SMALL_CONTEXT:-0}
MIN_CONTEXT_TOKENS=16384
RESULTS_ROOT=${RESULTS_ROOT:-benchmark/results}
MIN_FREE_GB=${MIN_FREE_GB:-16}
LLM_TIMEOUT=${LLM_TIMEOUT:-300}
GENERATE_BUDGET=${GENERATE_BUDGET:-1320}
CASES_PER_CALL=${CASES_PER_CALL:-3}
# Protocol values (experiment-design §4.2 + ADR-0005); other values are flagged and recorded.
PROTOCOL_LLM_TIMEOUT=300
PROTOCOL_GENERATE_BUDGET=1320
PROTOCOL_CASES_PER_CALL=3
# Fixed by RunExperimentAction (max_cases_per_endpoint) and the compose override.
MAX_CASES_PER_ENDPOINT=10
OUTPUT_TOKENS_PER_CASE=400
# The web refuses LLM/HYBRID generation unless AI_SERVICE_TIMEOUT >= budget + 60, and
# RunExperimentStepJob::TIMEOUT is 1500 s: budget + 60 (+ the RULE seed of HYBRID) must fit in it.
AI_TIMEOUT_MARGIN=60
MAX_GENERATE_BUDGET=1380

TS=$(date -u +%Y%m%dT%H%M%SZ)
OUT="$RESULTS_ROOT/$TS"
START=$(date +%s)

say() { printf '\033[36m==> %s\033[0m\n' "$*"; }
fail() { printf '\033[31mLỖI: %s\033[0m\n' "$*" >&2; exit 1; }

case "$OLLAMA" in
  native)
    export EXPERIMENT_LLM_BASE_URL=${LLM_BASE_URL:-http://host.docker.internal:11434/v1}
    CONTEXT_DEFAULT=16384
    PROFILE=()
    ;;
  docker)
    export EXPERIMENT_LLM_BASE_URL=${LLM_BASE_URL:-http://ollama:11434/v1}
    CONTEXT_DEFAULT=16384
    PROFILE=(--profile llm)
    ;;
  *) fail "OLLAMA phải là 'native' (Ollama cài trực tiếp trên máy) hoặc 'docker' (service ollama của compose), nhận được '$OLLAMA'." ;;
esac
for value in "$LLM_TIMEOUT" "$GENERATE_BUDGET"; do
  case "$value" in '' | *[!0-9]*) fail "LLM_TIMEOUT và GENERATE_BUDGET phải là số giây nguyên dương." ;; esac
done
if [ "$GENERATE_BUDGET" -lt 1 ] || [ "$GENERATE_BUDGET" -gt "$MAX_GENERATE_BUDGET" ]; then
  fail "GENERATE_BUDGET phải trong khoảng 1..$MAX_GENERATE_BUDGET giây (web cần GENERATE_BUDGET + $AI_TIMEOUT_MARGIN giây, RunExperimentStepJob dừng sau 1500 giây)."
fi
if [ "$LLM_TIMEOUT" -lt 1 ] || [ "$LLM_TIMEOUT" -ge "$GENERATE_BUDGET" ]; then
  fail "LLM_TIMEOUT ($LLM_TIMEOUT) phải dương và nhỏ hơn GENERATE_BUDGET ($GENERATE_BUDGET)."
fi
case "$CASES_PER_CALL" in '' | *[!0-9]*) fail "CASES_PER_CALL phải là số nguyên 1..20." ;; esac
if [ "$CASES_PER_CALL" -lt 1 ] || [ "$CASES_PER_CALL" -gt 20 ]; then
  fail "CASES_PER_CALL phải là số nguyên 1..20."
fi
case "$RESET_PER_CASE" in
  true | false) ;;
  *) fail "RESET_PER_CASE phải là true hoặc false, nhận được '$RESET_PER_CASE'." ;;
esac
export EXPERIMENT_MODEL=$MODEL EXPERIMENT_TEMPERATURE=$TEMPERATURE EXPERIMENT_SEEDS=$SEEDS
export EXPERIMENT_RESET_PER_CASE=$RESET_PER_CASE
export EXPERIMENT_LLM_PROVIDER=openai_compatible
export EXPERIMENT_LLM_TIMEOUT_SECONDS=$LLM_TIMEOUT EXPERIMENT_GENERATE_BUDGET_SECONDS=$GENERATE_BUDGET
export EXPERIMENT_AI_SERVICE_TIMEOUT=$((GENERATE_BUDGET + AI_TIMEOUT_MARGIN))
export EXPERIMENT_LLM_CASES_PER_CALL=$CASES_PER_CALL

COMPOSE=(docker compose -f docker/docker-compose.yml -f docker/docker-compose.experiment.yml
  -f docker/docker-compose.experiment-llm.yml --env-file .env ${PROFILE[@]+"${PROFILE[@]}"})
artisan() { "${COMPOSE[@]}" exec -T web php artisan "$@"; }

NEEDS_LLM=0
case ",$(printf '%s' "$STRATEGIES" | tr '[:lower:]' '[:upper:]' | tr -d ' ')," in
  *,LLM,* | *,HYBRID,*) NEEDS_LLM=1 ;;
esac

say "Thực nghiệm: chiến lược=$STRATEGIES benchmark=$BENCHMARKS lần lặp=$REPETITIONS seeds=$SEEDS model=$MODEL temperature=$TEMPERATURE"
if [ "$LLM_TIMEOUT" != "$PROTOCOL_LLM_TIMEOUT" ] || [ "$GENERATE_BUDGET" != "$PROTOCOL_GENERATE_BUDGET" ] \
  || [ "$CASES_PER_CALL" != "$PROTOCOL_CASES_PER_CALL" ]; then
  printf '\033[33mLưu ý: LLM_TIMEOUT=%s, GENERATE_BUDGET=%s, CASES_PER_CALL=%s khác giao thức (%s giây mỗi lời gọi LLM, ngân sách %s giây, %s case mỗi lời gọi; ADR-0005); giá trị được ghi vào environment.txt.\033[0m\n' \
    "$LLM_TIMEOUT" "$GENERATE_BUDGET" "$CASES_PER_CALL" "$PROTOCOL_LLM_TIMEOUT" "$PROTOCOL_GENERATE_BUDGET" "$PROTOCOL_CASES_PER_CALL"
fi

# --- 1. Docker and disk -----------------------------------------------------------------------
docker info >/dev/null 2>&1 || fail "Không kết nối được Docker. Hãy cài và khởi động Docker Desktop (hoặc Docker Engine + compose v2) rồi chạy lại."
free_kb=$(df -Pk "$ROOT" | awk 'NR==2 {print $4}')
if [ "${free_kb:-0}" -lt $((MIN_FREE_GB * 1024 * 1024)) ]; then
  printf '\033[33mCẢNH BÁO: chỉ còn %s GB trống (khuyến nghị ≥ %s GB cho image Docker, model và kết quả).\033[0m\n' \
    "$((free_kb / 1024 / 1024))" "$MIN_FREE_GB"
fi

# --- 2. LLM (only for LLM / HYBRID) --------------------------------------------------------------
ollama_help() {
  cat >&2 <<EOF

Chiến lược LLM/HYBRID cần một Ollama phục vụ model '$MODEL'. Chọn một trong hai cách:

  (a) Ollama cài trực tiếp trên máy (mặc định, nhanh hơn nhờ GPU/Metal):
        OLLAMA_CONTEXT_LENGTH=16384 ollama serve
        ollama pull $MODEL
      rồi chạy lại: make experiment
      (Kiểm tra: curl $OLLAMA_URL/api/tags phải liệt kê '$MODEL'.)
      Linux: container gọi Ollama qua host.docker.internal = cổng bridge Docker (thường 172.17.0.1).
      Chỉ mở Ollama trên địa chỉ đó (Ollama không có xác thực, không dùng 0.0.0.0):
        OLLAMA_HOST=172.17.0.1:11434 OLLAMA_CONTEXT_LENGTH=16384 ollama serve
        OLLAMA_HOST=172.17.0.1:11434 ollama pull $MODEL
        make experiment OLLAMA_URL=http://172.17.0.1:11434

  (b) Ollama trong Docker (profile llm, chạy CPU, chậm hơn nhiều):
        make experiment OLLAMA=docker

  Chỉ chạy RULE (không cần LLM): make experiment STRATEGIES=RULE
EOF
  exit 1
}

if [ "$NEEDS_LLM" = 1 ]; then
  if [ "$OLLAMA" = docker ]; then
    say "Khởi động Ollama trong Docker và tải model $MODEL (lần đầu mất vài phút)"
    "${COMPOSE[@]}" up -d ollama
    for _ in $(seq 1 60); do
      curl -fsS --max-time 3 "$OLLAMA_URL/api/tags" >/dev/null 2>&1 && break
      sleep 2
    done
    "${COMPOSE[@]}" exec -T ollama ollama pull "$MODEL"
  fi
  say "Kiểm tra Ollama tại $OLLAMA_URL"
  tags=$(curl -fsS --max-time 5 "$OLLAMA_URL/api/tags" 2>/dev/null) || {
    printf '\033[31mLỖI: Không kết nối được Ollama tại %s.\033[0m\n' "$OLLAMA_URL" >&2
    ollama_help
  }
  if ! printf '%s' "$tags" | grep -q "\"name\":\"$MODEL\""; then
    printf '\033[31mLỖI: Ollama tại %s chưa có model %s.\033[0m\n' "$OLLAMA_URL" "$MODEL" >&2
    ollama_help
  fi
  # Use the context the server really runs the model with (Ollama reports it in /api/ps once the
  # model is loaded): AI_LLM_CONTEXT_TOKENS above it lets Ollama silently cut the prompt head.
  curl -fsS --max-time 120 "$OLLAMA_URL/api/generate" -d "{\"model\":\"$MODEL\",\"keep_alive\":\"30m\"}" >/dev/null 2>&1 || true
  ps_json=$(curl -fsS --max-time 5 "$OLLAMA_URL/api/ps" 2>/dev/null || true)
  model_json=${ps_json#*\"name\":\""$MODEL"\"}
  detected=""
  if [ "$model_json" != "$ps_json" ]; then
    model_json=${model_json%%\"name\":*}
    detected=$(printf '%s' "$model_json" | grep -o '"context_length":[0-9]*' | head -1 | cut -d: -f2 || true)
  fi
  if [ -z "${LLM_CONTEXT_TOKENS:-}" ] && [ -n "$detected" ]; then
    LLM_CONTEXT_TOKENS=$detected
    say "Ollama chạy $MODEL với context $detected token"
  elif [ -n "${LLM_CONTEXT_TOKENS:-}" ] && [ -n "$detected" ] && [ "$LLM_CONTEXT_TOKENS" != "$detected" ]; then
    printf '\033[33mCẢNH BÁO: LLM_CONTEXT_TOKENS=%s nhưng Ollama đang chạy %s với context %s.\033[0m\n' \
      "$LLM_CONTEXT_TOKENS" "$MODEL" "$detected"
  fi
else
  say "Chỉ có RULE: bỏ qua kiểm tra Ollama"
fi
export EXPERIMENT_LLM_CONTEXT_TOKENS=${LLM_CONTEXT_TOKENS:-$CONTEXT_DEFAULT}
case "$EXPERIMENT_LLM_CONTEXT_TOKENS" in
  '' | *[!0-9]*) fail "LLM_CONTEXT_TOKENS phải là số token nguyên dương, nhận được '$EXPERIMENT_LLM_CONTEXT_TOKENS'." ;;
esac
# A context below 16384 makes ai-service refuse most per-endpoint prompts (422 before any call):
# every LLM/HYBRID experiment would end FAILED after hours of RULE runs, so stop here (ADR-0005).
if [ "$NEEDS_LLM" = 1 ] && [ "$EXPERIMENT_LLM_CONTEXT_TOKENS" -lt "$MIN_CONTEXT_TOKENS" ]; then
  if [ "$ALLOW_SMALL_CONTEXT" = 1 ]; then
    printf '\033[33mCẢNH BÁO: context %s token < %s (ADR-0005), vẫn chạy vì ALLOW_SMALL_CONTEXT=1: prompt dài sẽ bị từ chối.\033[0m\n' \
      "$EXPERIMENT_LLM_CONTEXT_TOKENS" "$MIN_CONTEXT_TOKENS"
  else
    fail "Ollama chạy $MODEL với context $EXPERIMENT_LLM_CONTEXT_TOKENS token < $MIN_CONTEXT_TOKENS (ADR-0005): ai-service sẽ từ chối prompt và mọi thực nghiệm LLM/HYBRID FAILED. Khởi động lại Ollama bằng 'OLLAMA_CONTEXT_LENGTH=16384 ollama serve' (hoặc make experiment OLLAMA=docker), hoặc đặt ALLOW_SMALL_CONTEXT=1 nếu chấp nhận."
  fi
fi

# --- 3. Stack ------------------------------------------------------------------------------------
say "Khởi động stack thực nghiệm (web, worker, ai-service, postgres, 3 benchmark sạch + 32 sandbox một bug)"
"${COMPOSE[@]}" up -d --build --wait

if [ "$NEEDS_LLM" = 1 ]; then
  say "Kiểm tra ai-service gọi được $EXPERIMENT_LLM_BASE_URL từ trong Docker"
  # shellcheck disable=SC2016
  "${COMPOSE[@]}" exec -T ai-service python -c '
import os, sys, urllib.request
url = os.environ["AI_LLM_BASE_URL"].rstrip("/") + "/models"
try:
    urllib.request.urlopen(url, timeout=10).read()
except Exception as exc:
    sys.exit(f"{url}: {exc}")
' || fail "ai-service không gọi được $EXPERIMENT_LLM_BASE_URL. Với Ollama native trên Linux hãy mở Ollama trên cổng bridge Docker (không dùng 0.0.0.0 vì Ollama không có xác thực): 'OLLAMA_HOST=172.17.0.1:11434 OLLAMA_CONTEXT_LENGTH=16384 ollama serve' rồi chạy lại với OLLAMA_URL=http://172.17.0.1:11434."
fi

# --- 4. Database, benchmarks, experiments --------------------------------------------------------
mkdir -p "$OUT"
LOG="$OUT/run.log"
# psql/pg_dump run inside the postgres container with its own POSTGRES_USER / POSTGRES_DB.
# shellcheck disable=SC2016
db_dump() { "${COMPOSE[@]}" exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --no-owner' | gzip >"$1"; }

# The worker is a long-lived daemon: stop it before migrate:fresh so neither stale PHP code nor a
# step of an interrupted previous run (whose experiment ids get reused after the reset) can write
# into the new database. It is recreated (fresh code) once the database is ready.
say "Dừng worker trước khi làm mới cơ sở dữ liệu"
"${COMPOSE[@]}" stop worker
STACK_STARTED=1
on_interrupt() {
  trap - INT TERM
  printf '\n\033[31mĐã ngắt: dừng worker, các thực nghiệm đang chạy không tiếp tục.\033[0m\n' >&2
  if [ "${STACK_STARTED:-0}" = 1 ]; then
    "${COMPOSE[@]}" stop worker >&2 || true
  fi
  printf 'Dữ liệu còn trong DB của stack; lần make experiment sau sẽ sao lưu nó trước khi migrate:fresh. Dừng stack: make down-experiment\n' >&2
  exit 130
}
trap on_interrupt INT TERM

# migrate:fresh wipes llm_call_logs and test_cases (raw output, D09): back up a previous run first.
previous=$("${COMPOSE[@]}" exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc "SELECT count(*) FROM experiments"' 2>/dev/null | tr -d '[:space:]' || true)
case "$previous" in
  '' | 0 | *[!0-9]*) ;;
  *)
    say "DB hiện có $previous thực nghiệm: sao lưu vào $OUT/previous-db.sql.gz trước khi xóa"
    db_dump "$OUT/previous-db.sql.gz"
    ;;
esac

say "Làm mới cơ sở dữ liệu (migrate:fresh --seed): dữ liệu cũ của stack bị xóa"
artisan migrate:fresh --seed --force

say "Nạp benchmark và seeded bug từ ground-truth.yaml"
artisan ait:benchmarks:import --allow-targets

say "Khởi động lại worker với mã hiện tại"
"${COMPOSE[@]}" up -d --no-deps --force-recreate worker

say "Tạo và đưa thực nghiệm vào hàng đợi"
run_args=(ait:experiments:run "--strategies=$STRATEGIES" "--benchmarks=$BENCHMARKS" "--repetitions=$REPETITIONS"
  "--model=$MODEL" "--temperature=$TEMPERATURE" "--seeds=$SEEDS" --name="make experiment $TS")
artisan "${run_args[@]}" | tee "$LOG"
IDS=$(grep -o 'EXPERIMENT_IDS=[0-9,]*' "$LOG" | tail -1 | cut -d= -f2)
[ -n "$IDS" ] || fail "Không đọc được EXPERIMENT_IDS từ ait:experiments:run (xem $LOG)."
if [ -z "$WAIT_TIMEOUT" ]; then
  n_experiments=$(printf '%s' "$IDS" | tr ',' '\n' | grep -c .)
  WAIT_TIMEOUT=$((n_experiments * REPETITIONS * WAIT_SECONDS_PER_REPETITION))
fi

say "Chờ worker chạy xong các thực nghiệm $IDS (kiểm tra mỗi ${WAIT_INTERVAL}s, tối đa ${WAIT_TIMEOUT}s; theo dõi chi tiết ở màn hình M15 http://localhost:${WEB_PORT:-8000})"
set +e
artisan ait:experiments:wait "$IDS" "--interval=$WAIT_INTERVAL" "--timeout=$WAIT_TIMEOUT" | tee -a "$LOG"
WAIT_RC=${PIPESTATUS[0]}
set -e

# --- 5. Export -----------------------------------------------------------------------------------
say "Xuất kết quả vào $OUT"
EXPORT_TMP="/tmp/ait-experiment-$TS"
artisan ait:experiments:export "$IDS" --dir="$EXPORT_TMP" | tee -a "$LOG"
"${COMPOSE[@]}" cp "web:$EXPORT_TMP/." "$OUT/"
"${COMPOSE[@]}" exec -T web rm -rf "$EXPORT_TMP"
# Raw output (S11-02, D09): llm_call_logs, test_cases (DSL, validation errors), runs and results stay
# only in the DB, which the next make experiment resets; keep a dump with the results.
say "Sao lưu cơ sở dữ liệu (llm_call_logs, test_cases, kết quả chạy) vào $OUT/db.sql.gz"
db_dump "$OUT/db.sql.gz"

END=$(date +%s)
{
  echo "timestamp_utc=$TS"
  echo "git_commit=$(git rev-parse HEAD 2>/dev/null || echo unknown)"
  # Only tracked changes make the checkout differ from git_commit; untracked files (e.g. editor folders,
  # local scratch) are counted separately.
  echo "git_dirty=$([ -n "$(git status --porcelain --untracked-files=no 2>/dev/null)" ] && echo yes || echo no)"
  echo "git_untracked_files=$(git status --porcelain --untracked-files=normal 2>/dev/null | grep -c '^??' || true)"
  echo "strategies=$STRATEGIES"
  echo "benchmarks=$BENCHMARKS"
  echo "repetitions=$REPETITIONS"
  echo "seeds=$SEEDS"
  echo "reset_per_case=$RESET_PER_CASE"
  echo "model=$MODEL"
  echo "temperature=$TEMPERATURE"
  echo "llm_base_url=$EXPERIMENT_LLM_BASE_URL"
  echo "llm_context_tokens=$EXPERIMENT_LLM_CONTEXT_TOKENS"
  echo "llm_timeout_seconds=$LLM_TIMEOUT"
  echo "generate_budget_seconds=$GENERATE_BUDGET"
  echo "ai_service_timeout_seconds=$EXPERIMENT_AI_SERVICE_TIMEOUT"
  echo "max_repair_attempts=2"
  echo "max_cases_per_endpoint=$MAX_CASES_PER_ENDPOINT"
  echo "llm_cases_per_call=$CASES_PER_CALL"
  # Per-strategy case limits per endpoint (ADR-0005): RULE keeps <= max_cases_per_endpoint; LLM asks
  # min(max_cases_per_endpoint, cases_per_call) per GENERATE call and keeps <= max_cases_per_endpoint;
  # HYBRID = RULE seed + LLM additions.
  llm_cap=$((CASES_PER_CALL < MAX_CASES_PER_ENDPOINT ? CASES_PER_CALL : MAX_CASES_PER_ENDPOINT))
  echo "cases_cap_rule_per_endpoint=$MAX_CASES_PER_ENDPOINT"
  echo "cases_requested_llm_per_endpoint=$llm_cap"
  echo "cases_cap_llm_per_endpoint=$MAX_CASES_PER_ENDPOINT"
  echo "cases_cap_hybrid_per_endpoint=rule:$MAX_CASES_PER_ENDPOINT+llm:$MAX_CASES_PER_ENDPOINT (llm requested $llm_cap)"
  echo "llm_output_tokens_per_case=$OUTPUT_TOKENS_PER_CASE"
  gen_tokens=$((llm_cap * OUTPUT_TOKENS_PER_CASE + 256))
  half_ctx=$((EXPERIMENT_LLM_CONTEXT_TOKENS / 2))
  echo "generate_max_tokens=$((gen_tokens < half_ctx ? gen_tokens : half_ctx))"
  rep_tokens=$((2 * OUTPUT_TOKENS_PER_CASE + 256))
  echo "repair_max_tokens=$((rep_tokens < half_ctx ? rep_tokens : half_ctx))"
  echo "ollama_mode=$OLLAMA"
  if [ "$NEEDS_LLM" = 1 ]; then
    version=$(curl -fsS --max-time 5 "$OLLAMA_URL/api/version" 2>/dev/null | grep -o '"version":"[^"]*"' | cut -d'"' -f4 || true)
    echo "ollama_version=${version:-unknown}"
  fi
  echo "experiment_ids=$IDS"
  echo "wait_timeout_seconds=$WAIT_TIMEOUT"
  echo "allow_small_context=$ALLOW_SMALL_CONTEXT"
  echo "wait_exit_code=$WAIT_RC"
  echo "duration_seconds=$((END - START))"
  echo "host=$(uname -sm)"
} >"$OUT/environment.txt"

echo
cat "$OUT/summary.md"
echo
say "Xong sau $(((END - START) / 60)) phút $(((END - START) % 60)) giây. Kết quả: $OUT"
say "Dừng stack khi không cần nữa: make down-experiment"
if [ "$WAIT_RC" -ne 0 ]; then
  fail "Có thực nghiệm FAILED hoặc chưa xong (xem $LOG và summary.md); kết quả đã xuất vẫn nằm ở $OUT."
fi
exit 0
}
