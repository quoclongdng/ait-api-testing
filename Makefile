COMPOSE := docker compose -f docker/docker-compose.yml --env-file .env
WEB     := $(COMPOSE) exec -T web
AI      := $(COMPOSE) exec -T ai-service
BENCH   := $(COMPOSE) exec -T benchmark
BENCH_ORDER := $(COMPOSE) exec -T benchmark-order
BENCH_BOOKING := $(COMPOSE) exec -T benchmark-booking
MODEL   ?= qwen2.5-coder:7b
# Benchmark suites CI runs on the host with uv (not in a container).
BENCH_HOST_DIRS := benchmark/runner benchmark/tools
# Containers of the 3 benchmark APIs whose pytest suites make test-bench runs.
BENCH_SERVICES := benchmark benchmark-order benchmark-booking
# Sub-targets of make test, all run even when an earlier one fails (S13-03).
TEST_SUITES := test-web test-ai test-bench test-bench-host
# make test-ai runs pytest in a throwaway ai-service container with the repo mounted read-only at
# /repo: the service itself only mounts ai-service/ and docs/, but some tests check repo-root files
# (docker/docker-compose.yml, web/.env.example...) exactly as CI does on the host (S13-03).
AI_TEST := $(COMPOSE) run --rm --no-deps -T -v "$(CURDIR):/repo:ro" -e AIT_REPO_ROOT=/repo -e AIT_REQUIRE_REPO_FILES=1 ai-service
REDOCLY := npx --yes @redocly/cli@2.57.0
COMPOSE_EXP := docker compose -f docker/docker-compose.yml -f docker/docker-compose.experiment.yml -f docker/docker-compose.experiment-llm.yml --env-file .env
# make experiment (S12-02): defaults = protocol of docs/architecture/experiment-design.md §4.2.
# LLM limits default to the S11-02 chain (ADR-0005): LLM_TIMEOUT=300 GENERATE_BUDGET=1320
# (web AI_SERVICE_TIMEOUT = budget + 60 = 1380 < 1500 s job timeout) CASES_PER_CALL=3, context 16384.
# Override on the command line, e.g. make experiment STRATEGIES=RULE,LLM BENCHMARKS=simple-store
STRATEGIES  ?= RULE,LLM,HYBRID
BENCHMARKS  ?= all
REPETITIONS ?= 3
TEMPERATURE ?= 0.2
SEEDS       ?= 42,1024,2026
# ADR-0006 addendum 2026-10-12: reset the sandbox before every test case (true = protocol)
RESET_PER_CASE ?= true
# native = Ollama installed on the host (OLLAMA_CONTEXT_LENGTH=16384); docker = compose profile llm
OLLAMA      ?= native
OLLAMA_URL  ?= http://127.0.0.1:11434
EXPERIMENT_ENV = STRATEGIES='$(STRATEGIES)' BENCHMARKS='$(BENCHMARKS)' REPETITIONS='$(REPETITIONS)' \
	MODEL='$(MODEL)' TEMPERATURE='$(TEMPERATURE)' SEEDS='$(SEEDS)' RESET_PER_CASE='$(RESET_PER_CASE)' OLLAMA='$(OLLAMA)' OLLAMA_URL='$(OLLAMA_URL)' \
	$(if $(LLM_CONTEXT_TOKENS),LLM_CONTEXT_TOKENS='$(LLM_CONTEXT_TOKENS)') $(if $(LLM_BASE_URL),LLM_BASE_URL='$(LLM_BASE_URL)') \
	$(if $(LLM_TIMEOUT),LLM_TIMEOUT='$(LLM_TIMEOUT)') $(if $(GENERATE_BUDGET),GENERATE_BUDGET='$(GENERATE_BUDGET)') \
	$(if $(CASES_PER_CALL),CASES_PER_CALL='$(CASES_PER_CALL)') $(if $(WAIT_TIMEOUT),WAIT_TIMEOUT='$(WAIT_TIMEOUT)') \
	$(if $(ALLOW_SMALL_CONTEXT),ALLOW_SMALL_CONTEXT='$(ALLOW_SMALL_CONTEXT)')

.DEFAULT_GOAL := help
.PHONY: help setup up up-llm pull-model up-experiment down-experiment experiment experiment-smoke experiment-compose down fresh seed demo demo-seed test test-web test-ai test-bench test-bench-host lint lint-web lint-ai lint-bench lint-bench-host lint-openapi analyse format secrets logs ps shell

help: ## List available targets
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'

setup: ## Create local env files from examples (never overwrites)
	@for f in .env web/.env ai-service/.env; do \
		if [ -f "$$f" ]; then echo "setup: giữ nguyên $$f (đã có)"; \
		else cp "$$f.example" "$$f" && echo "setup: đã tạo $$f từ $$f.example (see README.md)"; fi; \
	done

up: setup ## Build and start the stack, wait until healthy
	$(COMPOSE) up -d --build --wait

up-llm: setup ## Start the stack plus a local Ollama server (OLLAMA_CONTEXT_LENGTH=16384)
	$(COMPOSE) --profile llm up -d --build --wait
	@echo "Ollama context: 16384 tokens (= AI_LLM_CONTEXT_TOKENS). A host-native Ollama must be"
	@echo "started the same way: OLLAMA_CONTEXT_LENGTH=16384 ollama serve"

pull-model: ## Pull the Ollama model (MODEL=qwen2.5-coder:7b) after make up-llm
	$(COMPOSE) --profile llm exec ollama ollama pull $(MODEL)
	@echo "Set AI_LLM_PROVIDER=openai_compatible in ai-service/.env; Ollama needs OLLAMA_CONTEXT_LENGTH>=16384"

up-experiment: setup ## Start the experiment stack: 32 single-bug sandboxes + real LLM/drivers override (needs Ollama)
	$(COMPOSE_EXP) up -d --build --wait

experiment: setup ## Reproduce the full experiment (RULE/LLM/HYBRID x 3 benchmarks x 3 reps) into benchmark/results/<UTC>/
	$(EXPERIMENT_ENV) bash docker/experiment/run-experiment.sh

experiment-smoke: setup ## Fast check of make experiment: RULE only, simple-store, 3 repetitions (no LLM)
	$(MAKE) experiment STRATEGIES=RULE BENCHMARKS=simple-store REPETITIONS=3

down-experiment: ## Stop the stack including the single-bug sandboxes
	$(COMPOSE_EXP) --profile llm down

experiment-compose: ## Regenerate docker/docker-compose.experiment.yml from ground-truth.yaml
	cd benchmark/tools && uv run --with pyyaml --no-project python gen_variant_compose.py

down: ## Stop the stack
	$(COMPOSE) --profile llm down

fresh: ## Drop all tables, re-run migrations and seeders
	$(WEB) php artisan migrate:fresh --seed --force

seed: ## Run database seeders
	$(WEB) php artisan db:seed --force

demo: ## ERASES the DB (make fresh) then seeds the demo data (S14-01); keep experiment data with make demo-seed
	$(MAKE) fresh
	$(MAKE) demo-seed

demo-seed: ## Seed the demo TESTER/project, RULE suite + recorded official LLM job, their runs; erases nothing (idempotent)
	$(WEB) php artisan db:seed --class=DemoSeeder --force

test: ## Run every test suite (TEST_SUITES); keeps going after a failure, lists failed suites
	@failed=""; for suite in $(TEST_SUITES); do \
		echo "==> make $$suite"; \
		$(MAKE) --no-print-directory $$suite || failed="$$failed $$suite"; \
	done; \
	if [ -n "$$failed" ]; then echo "make test: FAILED:$$failed"; exit 1; fi; \
	echo "make test: all suites passed ($(TEST_SUITES))"

test-web: ## PHPUnit in the web container
	$(WEB) php artisan test

test-ai: ## pytest of ai-service in a throwaway container with the repo mounted read-only (/repo)
	$(AI_TEST) pytest

test-bench: ## pytest of the 3 benchmark APIs in their containers (runs all, then fails if one failed)
	@failed=""; for svc in $(BENCH_SERVICES); do \
		echo "==> pytest in $$svc"; \
		$(COMPOSE) exec -T $$svc pytest || failed="$$failed $$svc"; \
	done; \
	if [ -n "$$failed" ]; then echo "test-bench: FAILED:$$failed"; exit 1; fi

test-bench-host: ## pytest for benchmark runner and tools on the host (uv), as CI does
	@failed=""; for dir in $(BENCH_HOST_DIRS); do \
		echo "==> pytest in $$dir"; \
		(cd $$dir && uv venv -q --allow-existing && uv pip install -q -r requirements.txt && uv run pytest) || failed="$$failed $$dir"; \
	done; \
	if [ -n "$$failed" ]; then echo "test-bench-host: FAILED:$$failed"; exit 1; fi

lint: lint-web lint-ai lint-bench lint-bench-host lint-openapi ## Lint + static analysis (same checks as CI)

lint-web:
	$(WEB) ./vendor/bin/pint --test
	$(WEB) ./vendor/bin/phpstan analyse --memory-limit=1G --no-progress

lint-ai:
	$(AI) ruff check .
	$(AI) ruff format --check .
	$(AI) mypy app tests

lint-bench:
	$(BENCH) ruff check .
	$(BENCH) ruff format --check .
	$(BENCH_ORDER) ruff check .
	$(BENCH_ORDER) ruff format --check .
	$(BENCH_BOOKING) ruff check .
	$(BENCH_BOOKING) ruff format --check .

lint-bench-host: ## Ruff for benchmark runner and tools on the host (uv), as CI does
	@set -e; for dir in $(BENCH_HOST_DIRS); do (cd $$dir && uv venv -q --allow-existing && uv pip install -q -r requirements.txt && uv run ruff check . && uv run ruff format --check .); done

lint-openapi: ## Lint the web, ai-service and benchmark OpenAPI contracts
	$(REDOCLY) lint docs/contracts/web-api.openapi.yaml docs/contracts/ai-service.openapi.yaml benchmark/simple-store/openapi.yaml benchmark/ecommerce-order/openapi.yaml benchmark/appointment-booking/openapi.yaml

analyse: ## Static analysis only
	$(WEB) ./vendor/bin/phpstan analyse --memory-limit=1G --no-progress
	$(AI) mypy app tests

format: ## Auto-fix code style
	$(WEB) ./vendor/bin/pint
	$(AI) ruff format .
	$(AI) ruff check --fix .

secrets: ## Scan git history for secrets (gitleaks)
	docker run --rm -v "$(CURDIR):/repo" zricethezav/gitleaks:v8.21.2 git /repo --redact -v

logs: ## Follow logs
	$(COMPOSE) logs -f --tail=100

ps: ## Show service status
	$(COMPOSE) ps

shell: ## Shell into the web container
	$(COMPOSE) exec web sh
