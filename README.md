# AIT – AI Testing Platform

AIT takes an OpenAPI 3.x spec and generates test cases for the API, runs them and tells you which ones pass. Test cases can come from three generators:

- RULE: fixed rules over the spec (happy path, boundary values, equivalence classes, invalid input, missing required fields, wrong types) plus auth cases for secured operations.
- LLM: a local model served by Ollama writes the cases. Each case is checked against the spec and sent back for repair at most twice if it is invalid.
- HYBRID: the RULE cases, plus LLM cases for multi-step flows, business rules and roles.

The model never decides whether a test passed. Verdicts come from a plain assertion engine.

The repo also has three small benchmark APIs with 32 seeded bugs (one Docker container per bug), which is what we used to compare the generators in our paper:

> Nguyen Quoc Long. *Rules First, LLMs Second: Rule-Based, LLM-Based and Hybrid REST API Test Generation with Local Models and a Deterministic Oracle.* Manuscript in preparation.

Everything needed to check or rerun the paper's numbers is in [`paper/`](paper/README.md).

This is a release repository. The code was moved here from my internal development repository when the paper was prepared, so the history here starts with the release and does not show how the platform was developed.

## What's where

```
web/          Laravel 12 app: specs, business rules, RULE generator, validator, executor, experiments, /api/v1
ai-service/   FastAPI service that talks to the LLM (GENERATE and REPAIR)
benchmark/    simple-store, ecommerce-order, appointment-booking (FastAPI) + ground truth of the seeded bugs
docker/       compose files for dev and for experiments, experiment/run-experiment.sh
docs/         DESIGN.md, ADRs, contracts/ (OpenAPI + DSL JSON Schema, read at runtime)
paper/        runs, Schemathesis reports, analysis scripts and results of the paper
```

The UI and some docs (DESIGN.md, the ADRs) are in Vietnamese. Code and comments are in English.

## Running it

You need Docker with Compose v2, GNU Make, bash and curl. For LLM and HYBRID you also need [Ollama](https://ollama.com).

```bash
make up      # creates the .env files from the examples, builds and starts everything
make seed    # creates the admin account admin@ait.local
make test
make lint
```

There's no sign-up page. Put a password in `ADMIN_DEFAULT_PASSWORD` (web/.env) before `make seed`, or leave it empty and the seeder prints a random one once. The admin creates the tester accounts.

Web runs on http://localhost:8000, the benchmarks on ports 8002, 8003 and 8004. Postgres (5433) and ai-service (8001) only listen on 127.0.0.1.

If you call `docker compose` yourself, add `-f docker/docker-compose.yml --env-file .env`, otherwise it won't find the env file.

### Using a local model

```bash
OLLAMA_CONTEXT_LENGTH=16384 ollama serve
ollama pull qwen2.5-coder:7b
```

Then set `AI_LLM_PROVIDER=openai_compatible` and `AI_LLM_BASE_URL=http://host.docker.internal:11434/v1` in `ai-service/.env` and run `make up` again. The context length matters: with Ollama's default (2–4k tokens) the system prompt gets cut off and every call fails. No GPU? `make up-llm` and `make pull-model` run Ollama in Docker instead, just slower.

On Linux the containers reach the host through the Docker bridge (usually 172.17.0.1). Ollama has no auth, so bind it to that address and not to 0.0.0.0.

### Running an experiment

```bash
make experiment-smoke   # RULE only, simple-store, 3 repetitions; no model needed, takes a couple of minutes
make experiment         # RULE, LLM and HYBRID on all 3 benchmarks, 3 repetitions
make down-experiment
```

Careful: `make experiment` wipes the local database (`migrate:fresh`) before it starts. Results end up in `benchmark/results/<UTC timestamp>/`. You can override the defaults, e.g.

```bash
make experiment STRATEGIES=RULE,LLM BENCHMARKS=simple-store REPETITIONS=5 MODEL=qwen3-coder:30b
```

The other variables are listed at the top of the Makefile and in `docker/experiment/run-experiment.sh`.

A bug counts as found when some test passes on the clean API and fails on the copy that has only that bug turned on. The sandbox is reset before every test case.

## License

MIT, see [LICENSE](LICENSE). If you use the code or the data, [CITATION.cff](CITATION.cff) has the details.

## Contact

Nguyen Quoc Long, School of Data & Artificial Intelligence (SDA), Duy Tan University, Da Nang, Vietnam.
nguyenquoclong1@dtu.edu.vn (or quoclongdng@gmail.com)
