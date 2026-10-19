"""Regression for S10-01: the simple-store image must build on a clean machine.

`uv pip install` without an active virtualenv, `--python` or `--system` exits with code 2
("No virtual environment found"), which broke `make up` for the `benchmark` service.
"""

import re
from pathlib import Path

import pytest

BENCHMARK_DOCKERFILES = ["benchmark", "benchmark-order", "benchmark-booking"]


def find_docker_dir(start: Path) -> Path | None:
    """Return the repo `docker/` directory above `start`, or None outside the repo layout.

    Regression (S10-01 audit r2): indexing a fixed ancestor of this file raised IndexError at
    collection time when the benchmark is mounted at /app (`make test-bench` in Docker).
    """
    for directory in start.resolve().parents:
        candidate = directory / "docker"
        if (candidate / "benchmark" / "Dockerfile").is_file():
            return candidate
    return None


DOCKER_DIR = find_docker_dir(Path(__file__))

needs_docker_dir = pytest.mark.skipif(
    DOCKER_DIR is None, reason="docker/ is not mounted (running inside the benchmark container)"
)


def test_find_docker_dir_returns_none_for_shallow_docker_layout(tmp_path: Path) -> None:
    test_file = tmp_path / "app" / "tests" / "test_dockerfile.py"
    test_file.parent.mkdir(parents=True)
    test_file.write_text("", encoding="utf-8")

    assert find_docker_dir(test_file) is None


def _run_instructions(dockerfile: Path) -> list[str]:
    text = dockerfile.read_text(encoding="utf-8").replace("\\\n", " ")
    return [line.strip() for line in text.splitlines() if line.strip().startswith("RUN ")]


@needs_docker_dir
@pytest.mark.parametrize("name", BENCHMARK_DOCKERFILES)
def test_uv_pip_install_targets_an_explicit_environment(name: str) -> None:
    assert DOCKER_DIR is not None
    dockerfile = DOCKER_DIR / name / "Dockerfile"
    installs = [
        command
        for run in _run_instructions(dockerfile)
        for command in re.split(r"&&|;", run)
        if "uv pip install" in command
    ]

    assert installs, f"{dockerfile} does not install requirements with uv pip"
    for command in installs:
        assert "--python" in command or "--system" in command, (
            f"{dockerfile}: `{command.strip()}` has no target environment"
        )


@needs_docker_dir
@pytest.mark.parametrize("name", BENCHMARK_DOCKERFILES)
def test_virtualenv_on_path_is_created(name: str) -> None:
    assert DOCKER_DIR is not None
    dockerfile = DOCKER_DIR / name / "Dockerfile"
    runs = " ".join(_run_instructions(dockerfile))

    assert 'uv venv "$UV_PROJECT_ENVIRONMENT"' in runs
