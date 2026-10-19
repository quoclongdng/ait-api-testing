# AI Testing Platform (AIT)

AIT generates, executes and evaluates test cases for REST APIs from an OpenAPI 3.x specification. It offers three generation strategies:

- **RULE**: deterministic rules derived from the specification (happy path, boundary values, equivalence classes, invalid input, missing required fields, type mismatch, plus authorization cases);
- **LLM**: a local large language model (served by Ollama through an OpenAI-compatible API) writes test cases in a JSON test DSL, which are validated against the specification and repaired at most twice;
- **HYBRID**: RULE cases plus LLM cases that target sequences, business rules and authorization.

Every case is executed against the API under test and judged by a deterministic assertion engine. Three benchmark APIs with 32 seeded faults (one sandbox per fault) are included for quantitative evaluation.

This repository accompanies the paper *Rules First, LLMs Second: Rule-Based, LLM-Based and Hybrid REST API Test Generation with Local Models and a Deterministic Oracle* (Nguyen Quoc Long, Duy Tan University; manuscript in preparation). The data, analysis scripts and instructions to reproduce the paper are in [`paper/`](paper/README.md).

## Repository layout

| Path | Content |
|---|---|
| `web/` | Laravel 12 application: projects, OpenAPI specs, business rules, RULE generator, DSL validator, test executor, assertion engine, experiments, REST API `/api/v1` |
| `ai-service/` | FastAPI service that calls the LLM (GENERATE, REPAIR) and returns DSL test cases |
| `benchmark/` | Benchmark APIs `simple-store`, `ecommerce-order`, `appointment-booking` (FastAPI) with `openapi.yaml` and `ground-truth.yaml` (seeded faults, business rules, auth fixture); `runner/` and `tools/` |
| `docker/` | Docker Compose stacks: development, experiment (32 single-fault sandboxes), LLM overrides; `experiment/run-experiment.sh` |
| `docs/` | `DESIGN.md` (requirements, decisions, configuration), `contracts/` (OpenAPI and DSL JSON Schema, read at runtime), `adr/`, `architecture/experiment-design.md` |
| `paper/` | Runs, Schemathesis reports, analysis scripts, patches and results of the paper |

The user interface and some documents (`docs/DESIGN.md`, ADRs) are in Vietnamese; code, comments and the paper material are in English.

## Requirements

- Docker (Docker Desktop or Docker Engine with Compose v2), GNU Make, bash, `curl`
- For LLM and HYBRID: [Ollama](https://ollama.com) with a context of at least 16384 tokens
- Optional, to run tools outside Docker: [uv](https://docs.astral.sh/uv/), Node.js 22 (OpenAPI lint)

## Quick start

```bash
make up      # create .env files from the examples, build and start the stack
make seed    # create the ADMIN account admin@ait.local
make test    # PHPUnit + pytest
make lint    # Pint, Larastan, Ruff, mypy, Redocly
```

There is no self-registration. Set `ADMIN_DEFAULT_PASSWORD` in `web/.env` before `make seed`; if it is empty, the seeder generates a random password and prints it once. The ADMIN then creates TESTER accounts.

| Service | URL |
|---|---|
| Web | http://localhost:8000 |
| Health | http://localhost:8000/api/v1/health |
| ai-service | http://127.0.0.1:8001/health (loopback only) |
| PostgreSQL | 127.0.0.1:5433 (user `ait`, loopback only) |
| Benchmarks | http://localhost:8002 (simple-store), :8003 (ecommerce-order), :8004 (appointment-booking) |

When calling `docker compose` directly, always pass `-f docker/docker-compose.yml --env-file .env`.

### Local LLM

Start Ollama on the host with a 16384-token context and pull a model:

```bash
OLLAMA_CONTEXT_LENGTH=16384 ollama serve
ollama pull qwen2.5-coder:7b
```

Then set `AI_LLM_PROVIDER=openai_compatible` and `AI_LLM_BASE_URL=http://host.docker.internal:11434/v1` in `ai-service/.env` and run `make up` again. Without a GPU, `make up-llm` and `make pull-model` run Ollama inside Docker (slower). The default Ollama context (2k-4k tokens) truncates the system prompt, so every call fails.

On Linux, containers reach the host through the Docker bridge address (usually `172.17.0.1`). Ollama has no authentication: bind it to that address only, not to every interface.

## Running an experiment

```bash
make experiment-smoke   # quick check without an LLM: RULE x simple-store x 3 repetitions
make experiment         # RULE, LLM, HYBRID x 3 benchmarks x 3 repetitions (qwen2.5-coder:7b)
make down-experiment
```

`make experiment` resets the database of the local stack (`migrate:fresh`), imports the benchmarks, runs every strategy on the clean API and on each single-fault sandbox, and exports the results to `benchmark/results/<UTC timestamp>/` (`experiments.json`, CSV files, `summary.md`, `environment.txt`, database dump). Parameters can be overridden, e.g. `make experiment STRATEGIES=RULE,LLM BENCHMARKS=simple-store REPETITIONS=5 MODEL=qwen3-coder:30b`; see the variables at the top of the `Makefile` and the header of `docker/experiment/run-experiment.sh`.

A fault is detected by a test suite when at least one test case passes on the clean API and fails (or errors) on the variant with that fault only. The sandbox is reset before every test case.

## License

MIT, see [LICENSE](LICENSE). Third-party dependencies keep their own licenses.

## Citation

See [CITATION.cff](CITATION.cff). Until the paper is published, please cite this repository.
