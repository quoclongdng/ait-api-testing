"""Regression tests for `make test` on a clean clone (S13-03).

`make test-ai` ran pytest inside the ai-service container, where only ai-service/ (/app) and
docs/ (/docs) are mounted: tests reading docker/docker-compose.yml or web/.env.example failed
with FileNotFoundError while CI (pytest on the host) stayed green.
"""

import re
from pathlib import Path

import pytest

from tests.repo_files import (
    REPO_ROOT,
    REPO_ROOT_ENV,
    REQUIRE_REPO_FILES_ENV,
    find_repo_root,
    repo_file,
    require_repo_file,
)

TESTS_DIR = Path(__file__).resolve().parent


def test_repo_root_defaults_to_the_parent_of_ai_service(tmp_path: Path) -> None:
    tests_dir = tmp_path / "ai-service" / "tests"
    tests_dir.mkdir(parents=True)

    assert find_repo_root({}, tests_dir) == tmp_path
    assert find_repo_root({REPO_ROOT_ENV: "  "}, tests_dir) == tmp_path


def test_repo_root_env_points_to_the_mounted_repo(tmp_path: Path) -> None:
    # Inside the container tests live in /app/tests: the default would be `/`.
    assert find_repo_root({REPO_ROOT_ENV: "/repo"}, tmp_path / "app" / "tests") == Path("/repo")


def test_existing_repo_file_is_returned(tmp_path: Path) -> None:
    compose = tmp_path / "docker" / "docker-compose.yml"
    compose.parent.mkdir()
    compose.write_text("services: {}\n", encoding="utf-8")

    assert require_repo_file(tmp_path, ("docker", "docker-compose.yml"), required=True) == compose


def test_missing_repo_file_is_skipped_with_the_reason(tmp_path: Path) -> None:
    with pytest.raises(pytest.skip.Exception, match=r"docker/docker-compose\.yml.*make test-ai"):
        require_repo_file(tmp_path, ("docker", "docker-compose.yml"), required=False)


def test_missing_repo_file_fails_when_required(tmp_path: Path) -> None:
    with pytest.raises(pytest.fail.Exception, match=REQUIRE_REPO_FILES_ENV):
        require_repo_file(tmp_path, ("web", ".env.example"), required=True)


def test_repo_root_files_read_by_the_tests_are_reachable() -> None:
    # Runs in `make test-ai` (repo mounted at /repo, AIT_REQUIRE_REPO_FILES=1) and in CI.
    for parts in [
        ("docker", "docker-compose.yml"),
        ("web", ".env.example"),
        ("ai-service", ".env.example"),
        ("docs", "contracts", "test-dsl.schema.json"),
    ]:
        assert repo_file(*parts).is_file()
    assert (REPO_ROOT / "docs" / "contracts" / "examples").is_dir()


def test_tests_locate_the_repo_root_only_through_repo_files() -> None:
    offenders = [
        path.name
        for path in sorted(TESTS_DIR.glob("*.py"))
        if path.name != "repo_files.py"
        and re.search(r"parents\[[1-9]\]", path.read_text(encoding="utf-8"))
    ]
    # Two levels above /app/tests/x.py is `/` in the container: use REPO_ROOT / repo_file.
    assert offenders == []
