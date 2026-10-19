"""Locate the repo-root files that the ai-service tests read (S13-03).

On the host (CI, `uv run pytest`) the repo root is the parent of ai-service/. Inside the
ai-service container only ai-service/ (/app) and docs/ (/docs) are mounted, so the same
`parents[2]` lookup lands on `/`: docs/ is still found there, but docker/docker-compose.yml,
web/.env.example and the other repo-root files are not, and `make test` was red on a clean clone.

`make test-ai` therefore runs pytest in a throwaway container with the repo mounted read-only at
/repo and sets:

- AIT_REPO_ROOT=/repo: where the repo root is;
- AIT_REQUIRE_REPO_FILES=1: a missing repo file fails the test instead of skipping it, so
  `make test` keeps the coverage of CI.

A plain `docker compose exec ai-service pytest` (no /repo mount, no AIT_REQUIRE_REPO_FILES) skips
the tests whose files are absent and says why, instead of failing on FileNotFoundError.
"""

import os
from collections.abc import Mapping
from pathlib import Path

import pytest

REPO_ROOT_ENV = "AIT_REPO_ROOT"
REQUIRE_REPO_FILES_ENV = "AIT_REQUIRE_REPO_FILES"


def find_repo_root(env: Mapping[str, str], tests_dir: Path) -> Path:
    """Return AIT_REPO_ROOT when set, else the parent of ai-service/ (two levels above tests/)."""
    configured = env.get(REPO_ROOT_ENV, "").strip()
    if configured:
        return Path(configured)
    return tests_dir.resolve().parent.parent


def require_repo_file(root: Path, parts: tuple[str, ...], required: bool) -> Path:
    """Return root/parts, or skip (fail when required) with the reason when it does not exist."""
    path = root.joinpath(*parts)
    if path.exists():
        return path
    message = (
        f"repo file {'/'.join(parts)} not found under {root}: run the ai-service tests with "
        f"`make test-ai` (mounts the repo at /repo) or on the host with `uv run pytest`"
    )
    if required:
        pytest.fail(message + f" ({REQUIRE_REPO_FILES_ENV}=1)")
    pytest.skip(message)


REPO_ROOT = find_repo_root(os.environ, Path(__file__).parent)


def repo_file(*parts: str) -> Path:
    """Path of a repo-root file such as repo_file("docker", "docker-compose.yml")."""
    required = os.environ.get(REQUIRE_REPO_FILES_ENV, "").strip() == "1"
    return require_repo_file(REPO_ROOT, parts, required)
