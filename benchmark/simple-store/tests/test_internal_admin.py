"""Tests for the internal sandbox reset endpoint and per-container bug toggles [S12-02]."""

import os
import subprocess
import sys
from pathlib import Path
from typing import Any

import yaml
from fastapi.testclient import TestClient

from app.store import store

BENCHMARK_DIR = Path(__file__).resolve().parents[1]
RESET_PATH = "/__admin/reset"


def test_reset_restores_seed_data(client: TestClient) -> None:
    store.clear_all()
    assert client.get("/products/1").status_code == 404

    response = client.post(RESET_PATH)

    assert response.status_code == 200
    assert response.json() == {"status": "reset", "service": "simple-store"}
    assert client.get("/products/1").status_code == 200


def test_reset_is_idempotent(client: TestClient) -> None:
    first = client.post(RESET_PATH)
    second = client.post(RESET_PATH)

    assert first.status_code == second.status_code == 200
    assert client.get("/products/1").status_code == 200


def test_reset_only_accepts_post(client: TestClient) -> None:
    assert client.get(RESET_PATH).status_code == 405


def test_reset_is_hidden_from_published_and_runtime_openapi(client: TestClient) -> None:
    with (BENCHMARK_DIR / "openapi.yaml").open(encoding="utf-8") as file:
        spec: dict[str, Any] = yaml.safe_load(file)

    assert not any(path.startswith("/__admin") for path in spec["paths"])
    runtime_paths = client.app.openapi()["paths"]
    assert not any(path.startswith("/__admin") for path in runtime_paths)


def _read_toggles_in_fresh_process(extra_env: dict[str, str]) -> str:
    env = {key: value for key, value in os.environ.items() if not key.startswith("BUG_")}
    env.update(extra_env)
    code = (
        "from app.config import get_settings, is_bug_active\n"
        "s = get_settings()\n"
        "print(is_bug_active('BUG_001'), s.bug_001, is_bug_active('BUG_002'), s.bug_002)"
    )
    result = subprocess.run(  # noqa: S603 - fixed interpreter and inline code
        [sys.executable, "-c", code],
        cwd=BENCHMARK_DIR,
        env=env,
        capture_output=True,
        text=True,
        check=True,
    )
    return result.stdout.strip()


def test_bug_toggle_from_container_environment_is_read_at_startup() -> None:
    """A variant container starts with exactly one BUG_xxx=1; only that bug is active."""
    assert _read_toggles_in_fresh_process({"BUG_001": "1"}) == "True True False False"


def test_clean_container_environment_has_no_active_bug() -> None:
    assert _read_toggles_in_fresh_process({}) == "False False False False"
