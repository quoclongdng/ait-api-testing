"""Tests for the single-bug sandbox compose generator [S12-02]."""

import re
from pathlib import Path
from typing import Any
from urllib.parse import urlsplit

import pytest
import yaml

import gen_variant_compose as gen

EXPECTED_SLUGS = ["appointment-booking", "ecommerce-order", "simple-store"]


@pytest.fixture(scope="module")
def benchmarks() -> list[gen.Benchmark]:
    return gen.load_benchmarks()


@pytest.fixture(scope="module")
def generated(benchmarks: list[gen.Benchmark]) -> dict[str, Any]:
    data: dict[str, Any] = yaml.safe_load(gen.render(benchmarks))
    return data


@pytest.fixture(scope="module")
def base_compose() -> dict[str, Any]:
    with gen.BASE_COMPOSE.open(encoding="utf-8") as file:
        data: dict[str, Any] = yaml.safe_load(file)
    return data


def _env_value(name: str) -> str:
    text = (gen.REPO_ROOT / "web" / ".env.example").read_text(encoding="utf-8")
    match = re.search(rf"^{name}=(.*)$", text, flags=re.MULTILINE)
    assert match is not None, f"{name} missing from web/.env.example"
    return match.group(1).strip()


def test_committed_compose_file_is_up_to_date(benchmarks: list[gen.Benchmark]) -> None:
    assert gen.OUTPUT.exists(), "run: python benchmark/tools/gen_variant_compose.py"
    assert gen.OUTPUT.read_text(encoding="utf-8") == gen.render(benchmarks), (
        "docker/docker-compose.experiment.yml is stale; run `make experiment-compose`"
    )
    assert gen.main(["--check"]) == 0


def test_check_mode_detects_stale_file(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch, capsys: pytest.CaptureFixture[str]
) -> None:
    stale = tmp_path / "docker-compose.experiment.yml"
    stale.write_text("services: {}\n", encoding="utf-8")
    monkeypatch.setattr(gen, "OUTPUT", stale)
    monkeypatch.setattr(gen, "REPO_ROOT", tmp_path)

    assert gen.main(["--check"]) == 1
    assert "out of date" in capsys.readouterr().out
    assert gen.main([]) == 0
    assert gen.main(["--check"]) == 0


def test_one_service_per_seeded_bug(
    benchmarks: list[gen.Benchmark], generated: dict[str, Any]
) -> None:
    assert [b.slug for b in benchmarks] == EXPECTED_SLUGS
    services = generated["services"]
    assert len(services) == sum(len(b.bugs) for b in benchmarks) == 32
    assert "simple-store-bug-001" in services
    assert "ecommerce-order-bug-022" in services
    assert "appointment-booking-bug-032" in services


def test_service_names_follow_url_template_convention(benchmarks: list[gen.Benchmark]) -> None:
    template = gen.variant_url_template()
    assert template == "http://{slug}-{code_lower}:8000"
    for benchmark in benchmarks:
        for bug in benchmark.bugs:
            name = gen.variant_service_name(benchmark.slug, bug.code)
            # Same substitution as web UrlTemplateBenchmarkSandbox: {code_lower} = strtolower(code).
            url = template.replace("{slug}", benchmark.slug).replace(
                "{code_lower}", bug.code.lower()
            )
            assert url == f"http://{name}:8000"
            # Also equals the toggle with '_' → '-', and is a valid DNS label set.
            assert name == f"{benchmark.slug}-{bug.toggle_env.lower().replace('_', '-')}"
            assert re.fullmatch(r"[a-z0-9]+(-[a-z0-9]+)*", name)


def test_each_variant_enables_exactly_its_own_bug(
    benchmarks: list[gen.Benchmark], generated: dict[str, Any]
) -> None:
    services = generated["services"]
    for benchmark in benchmarks:
        for bug in benchmark.bugs:
            service = services[gen.variant_service_name(benchmark.slug, bug.code)]
            assert service["environment"] == {bug.toggle_env: "1"}
            assert service["image"] == benchmark.image
            assert service["volumes"] == [f"../benchmark/{benchmark.slug}:/app:ro"]
            assert service["depends_on"] == [benchmark.clean_service]


def test_variants_are_internal_and_resource_limited(generated: dict[str, Any]) -> None:
    for name, service in generated["services"].items():
        assert "ports" not in service, f"{name} must not publish host ports"
        assert service["mem_limit"] == "128m"
        assert service["cpus"] == "0.25"
        assert service["pull_policy"] == "never"
        assert "--port" in service["command"]
        assert service["command"][service["command"].index("--port") + 1] == "8000"
        assert "--reload" not in service["command"]


def test_clean_services_share_image_and_expose_slug_alias(
    benchmarks: list[gen.Benchmark], base_compose: dict[str, Any]
) -> None:
    services = base_compose["services"]
    for benchmark in benchmarks:
        clean = services[benchmark.clean_service]
        assert clean["image"] == benchmark.image
        assert benchmark.slug in clean["networks"]["default"]["aliases"]
        assert gen.clean_base_url(benchmark.slug) == f"http://{benchmark.slug}:8000"
    assert {b.clean_service for b in benchmarks} == {
        "benchmark",
        "benchmark-order",
        "benchmark-booking",
    }


def test_only_ai_service_can_reach_host_ollama(base_compose: dict[str, Any]) -> None:
    extra_hosts = base_compose["services"]["ai-service"].get("extra_hosts", [])
    assert "host.docker.internal:host-gateway" in extra_hosts
    # web and worker run the executor (SSRF surface, D07) and never call the LLM (S13-03 r3).
    for name in ("web", "worker"):
        assert "extra_hosts" not in base_compose["services"][name], name


def test_web_env_example_allowlists_every_sandbox_host(benchmarks: list[gen.Benchmark]) -> None:
    allowed = set(_env_value("ALLOWED_TARGET_HOSTS").split(","))
    missing = set(gen.allowed_hosts(benchmarks)) - allowed
    assert not missing, f"add to ALLOWED_TARGET_HOSTS in web/.env.example: {sorted(missing)}"
    assert {"localhost", "127.0.0.1", "benchmark"} <= allowed


def test_web_env_example_uses_variant_url_template() -> None:
    assert _env_value("BENCHMARK_VARIANT_URL_TEMPLATE") == gen.variant_url_template()
    sandbox = (
        gen.REPO_ROOT / "web" / "app" / "Services" / "Benchmark" / "UrlTemplateBenchmarkSandbox.php"
    ).read_text(encoding="utf-8")
    assert "'{code_lower}' => strtolower($bug->code)" in sandbox


def test_ground_truth_base_url_is_clean_alias_reachable_and_allowlisted(
    benchmarks: list[gen.Benchmark],
) -> None:
    """Regression (PR #101 review): clean target = imported base_url, used inside Docker."""
    hosts = set(gen.allowed_hosts(benchmarks))
    web_allowlist = set(_env_value("ALLOWED_TARGET_HOSTS").split(","))
    for benchmark in benchmarks:
        path = gen.BENCHMARK_DIR / benchmark.slug / "ground-truth.yaml"
        with path.open(encoding="utf-8") as file:
            base_url = str(yaml.safe_load(file)["base_url"])
        assert base_url == gen.clean_base_url(benchmark.slug)
        host = urlsplit(base_url).hostname
        assert host is not None and host != "localhost"
        assert host in hosts
        assert host in web_allowlist


def test_rejects_ground_truth_with_host_only_base_url(tmp_path: Path) -> None:
    bench = tmp_path / "benchmark" / "demo"
    bench.mkdir(parents=True)
    (bench / "ground-truth.yaml").write_text(
        'benchmark: "demo"\nbase_url: "http://localhost:8002"\n'
        'bugs:\n  - code: "BUG-099"\n    toggle_env: "BUG_099"\n',
        encoding="utf-8",
    )
    compose = tmp_path / "docker-compose.yml"
    compose.write_text(
        "services:\n  benchmark-demo:\n    image: demo:local\n"
        "    volumes: ['../benchmark/demo:/app']\n"
        "    networks:\n      default:\n        aliases: ['demo']\n",
        encoding="utf-8",
    )

    with pytest.raises(ValueError, match="base_url must be http://demo:8000"):
        gen.load_benchmarks(tmp_path / "benchmark", compose)

    (bench / "ground-truth.yaml").write_text(
        'benchmark: "demo"\nbase_url: "http://demo:8000"\n'
        'bugs:\n  - code: "BUG-099"\n    toggle_env: "BUG_099"\n',
        encoding="utf-8",
    )
    assert [b.slug for b in gen.load_benchmarks(tmp_path / "benchmark", compose)] == ["demo"]


def test_rejects_benchmark_without_clean_service(tmp_path: Path) -> None:
    bench = tmp_path / "benchmark" / "orphan"
    bench.mkdir(parents=True)
    (bench / "ground-truth.yaml").write_text(
        'benchmark: "orphan"\nbugs:\n  - code: "BUG-099"\n    toggle_env: "BUG_099"\n',
        encoding="utf-8",
    )
    compose = tmp_path / "docker-compose.yml"
    compose.write_text("services:\n  web:\n    image: x\n", encoding="utf-8")

    with pytest.raises(ValueError, match="No clean service"):
        gen.load_benchmarks(tmp_path / "benchmark", compose)


def test_print_allowlist(capsys: pytest.CaptureFixture[str]) -> None:
    assert gen.main(["--print-allowlist"]) == 0
    hosts = capsys.readouterr().out.strip().split(",")
    assert len(hosts) == 35
    assert hosts[0] == "appointment-booking"
