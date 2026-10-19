"""Generate docker/docker-compose.experiment.yml: one sandbox per seeded bug [S12-02].

Single-fault isolation (experiment-design.md §4.3, D14) needs one benchmark process per seeded
bug with only that bug's toggle enabled. This script reads every
``benchmark/<slug>/ground-truth.yaml`` and the clean benchmark services of
``docker/docker-compose.yml`` and writes a compose override with one service per bug.

Hostname convention (used by the web side through BENCHMARK_VARIANT_URL_TEMPLATE):

* bug variant:  ``<slug>-<code lower>`` e.g. ``simple-store-bug-001`` → ``http://{slug}-{code_lower}:8000``
* clean variant: ``<slug>`` (network alias of the clean service) → ``http://simple-store:8000``,
  which is also the ``base_url`` every ground-truth.yaml must declare (checked here).

Usage (from the repository root)::

    python benchmark/tools/gen_variant_compose.py           # (re)write the compose file
    python benchmark/tools/gen_variant_compose.py --check   # exit 1 when the file is stale
    python benchmark/tools/gen_variant_compose.py --print-allowlist
"""

from __future__ import annotations

import argparse
import re
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import yaml

REPO_ROOT = Path(__file__).resolve().parents[2]
BENCHMARK_DIR = REPO_ROOT / "benchmark"
BASE_COMPOSE = REPO_ROOT / "docker" / "docker-compose.yml"
OUTPUT = REPO_ROOT / "docker" / "docker-compose.experiment.yml"

INTERNAL_PORT = 8000
MEM_LIMIT = "128m"
CPUS = "0.25"

_TOGGLE_RE = re.compile(r"^BUG_\d{3}$")
_CODE_RE = re.compile(r"^BUG-\d{3}$")


@dataclass(frozen=True)
class Bug:
    code: str
    toggle_env: str


@dataclass(frozen=True)
class Benchmark:
    slug: str
    clean_service: str
    image: str
    bugs: tuple[Bug, ...]


def variant_service_name(slug: str, code: str) -> str:
    """``simple-store`` + ``BUG-001`` → ``simple-store-bug-001`` (= ``{slug}-{code_lower}``)."""
    return f"{slug}-{code.lower()}"


def variant_url_template() -> str:
    return f"http://{{slug}}-{{code_lower}}:{INTERNAL_PORT}"


def clean_base_url(slug: str) -> str:
    return f"http://{slug}:{INTERNAL_PORT}"


def _clean_service_for(slug: str, services: dict[str, Any]) -> tuple[str, dict[str, Any]]:
    mount = f"../benchmark/{slug}:/app"
    for name, service in services.items():
        volumes = service.get("volumes") or []
        if any(str(volume).split(":ro")[0] == mount for volume in volumes):
            return name, service
    raise ValueError(f"No clean service in {BASE_COMPOSE.name} mounts {mount}")


def load_benchmarks(
    benchmark_dir: Path = BENCHMARK_DIR, base_compose: Path = BASE_COMPOSE
) -> list[Benchmark]:
    with base_compose.open(encoding="utf-8") as file:
        services: dict[str, Any] = yaml.safe_load(file)["services"]

    benchmarks: list[Benchmark] = []
    for ground_truth in sorted(benchmark_dir.glob("*/ground-truth.yaml")):
        with ground_truth.open(encoding="utf-8") as file:
            data: dict[str, Any] = yaml.safe_load(file)
        slug = str(data["benchmark"])
        if slug != ground_truth.parent.name:
            raise ValueError(f"{ground_truth}: benchmark '{slug}' differs from its directory")

        clean_name, clean = _clean_service_for(slug, services)
        image = clean.get("image")
        if not image:
            raise ValueError(f"Clean service '{clean_name}' must declare an image tag to share")
        aliases = ((clean.get("networks") or {}).get("default") or {}).get("aliases") or []
        if slug not in aliases:
            raise ValueError(f"Clean service '{clean_name}' must have network alias '{slug}'")
        # The web side uses ground-truth base_url unchanged as the clean-variant target
        # (UrlTemplateBenchmarkSandbox), from inside the web/worker containers: it must be the
        # clean service's network alias, not http://localhost:800x (host-only port mapping).
        base_url = str(data.get("base_url", ""))
        if base_url != clean_base_url(slug):
            raise ValueError(
                f"{ground_truth}: base_url must be {clean_base_url(slug)} (reachable inside "
                f"Docker), got '{base_url}'"
            )

        bugs: list[Bug] = []
        for bug in data["bugs"]:
            code, toggle = str(bug["code"]), str(bug["toggle_env"])
            if not _CODE_RE.match(code) or not _TOGGLE_RE.match(toggle):
                raise ValueError(f"{ground_truth}: unexpected code/toggle {code}/{toggle}")
            bugs.append(Bug(code=code, toggle_env=toggle))
        benchmarks.append(Benchmark(slug, clean_name, str(image), tuple(bugs)))
    return benchmarks


def allowed_hosts(benchmarks: list[Benchmark]) -> list[str]:
    hosts: list[str] = []
    for benchmark in benchmarks:
        hosts.append(benchmark.slug)
        hosts.extend(variant_service_name(benchmark.slug, bug.code) for bug in benchmark.bugs)
    return hosts


def render(benchmarks: list[Benchmark]) -> str:
    lines = [
        "# GENERATED by benchmark/tools/gen_variant_compose.py from benchmark/*/ground-truth.yaml.",
        "# Do not edit by hand: run `make experiment-compose` and commit the result.",
        "#",
        "# One sandbox per seeded bug (single-fault isolation, experiment-design.md §4.3, D14).",
        "# Use together with the base file:",
        "#   make up-experiment",
        "#   (= docker compose -f docker/docker-compose.yml",
        "#      -f docker/docker-compose.experiment.yml",
        "#      -f docker/docker-compose.experiment-llm.yml --env-file .env up -d --build --wait;",
        "#      the -llm override forces a real LLM via a host Ollama and the real drivers)",
        "# Hostnames inside the compose network (web side, docs/DESIGN.md §7):",
        f"#   BENCHMARK_VARIANT_URL_TEMPLATE={variant_url_template()}",
        f"#   clean variant base_url: http://<slug>:{INTERNAL_PORT}"
        " (network alias of the clean service)",
        "",
        "x-variant-limits: &variant-limits",
        f"  mem_limit: {MEM_LIMIT}",
        f'  cpus: "{CPUS}"',
        "  restart: unless-stopped",
        "  pull_policy: never",
        "  command:",
        '    ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000", "--no-access-log"]',
        "  healthcheck:",
        "    test:",
        "      [",
        '        "CMD",',
        '        "python",',
        '        "-c",',
        "        \"import urllib.request; urllib.request.urlopen('http://localhost:8000/health')\",",
        "      ]",
        "    interval: 10s",
        "    timeout: 3s",
        "    retries: 20",
        "",
        "services:",
    ]
    for benchmark in benchmarks:
        for bug in benchmark.bugs:
            lines += [
                f"  {variant_service_name(benchmark.slug, bug.code)}:",
                "    <<: *variant-limits",
                f"    image: {benchmark.image}",
                "    environment:",
                f'      {bug.toggle_env}: "1"',
                "    volumes:",
                f"      - ../benchmark/{benchmark.slug}:/app:ro",
                "    depends_on:",
                f"      - {benchmark.clean_service}",
                "",
            ]
    return "\n".join(lines).rstrip("\n") + "\n"


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    group = parser.add_mutually_exclusive_group()
    group.add_argument("--check", action="store_true", help="fail if the file is out of date")
    group.add_argument(
        "--print-allowlist", action="store_true", help="print hosts for ALLOWED_TARGET_HOSTS"
    )
    args = parser.parse_args(argv)

    benchmarks = load_benchmarks()
    if args.print_allowlist:
        print(",".join(allowed_hosts(benchmarks)))
        return 0

    content = render(benchmarks)
    if args.check:
        current = OUTPUT.read_text(encoding="utf-8") if OUTPUT.exists() else ""
        if current != content:
            print(f"{OUTPUT.relative_to(REPO_ROOT)} is out of date; regenerate it.")
            return 1
        return 0

    OUTPUT.write_text(content, encoding="utf-8")
    print(f"Wrote {OUTPUT.relative_to(REPO_ROOT)} ({len(allowed_hosts(benchmarks))} hosts)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
