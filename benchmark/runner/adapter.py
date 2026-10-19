"""Benchmark Sandbox Adapter for Experiment Runner.

Connects to any of the 3 FastAPI benchmarks (simple-store, ecommerce-order, appointment-booking),
loads ground-truth specifications and OpenAPI contracts, and enforces the
Single-Fault Isolation Protocol and Sandbox Reset required by Section 4.3.1.
"""

from __future__ import annotations

import os
import sys
from collections.abc import Generator
from contextlib import contextmanager
from pathlib import Path
from typing import Any

import yaml
from benchmark.runner.models import BugDefinition
from fastapi.testclient import TestClient


class BenchmarkAdapter:
    """Manages benchmark loading, bug toggles, and state isolation."""

    VALID_BENCHMARKS = ("simple-store", "ecommerce-order", "appointment-booking")

    def __init__(self, slug: str, base_dir: Path | str | None = None) -> None:
        if slug not in self.VALID_BENCHMARKS:
            raise ValueError(
                f"Unknown benchmark '{slug}'. Must be one of: {', '.join(self.VALID_BENCHMARKS)}"
            )
        self.slug = slug
        if base_dir is None:
            self.base_dir = Path(__file__).resolve().parents[1] / slug
        else:
            self.base_dir = (
                Path(base_dir) / slug if not str(base_dir).endswith(slug) else Path(base_dir)
            )

        if not self.base_dir.exists():
            raise FileNotFoundError(f"Benchmark directory not found: {self.base_dir}")

        self.app: Any = None
        self.settings: Any = None
        self.store: Any = None
        self.bugs: list[BugDefinition] = []
        self.bugs_by_code: dict[str, BugDefinition] = {}
        self.openapi_spec: dict[str, Any] = {}
        self.total_bugs: int = 0
        self._client: TestClient | None = None

        self._load_ground_truth()
        self._load_openapi_spec()
        self._bind_app()

    def _load_ground_truth(self) -> None:
        gt_path = self.base_dir / "ground-truth.yaml"
        if not gt_path.exists():
            raise FileNotFoundError(f"ground-truth.yaml missing at {gt_path}")

        with gt_path.open("r", encoding="utf-8") as f:
            data = yaml.safe_load(f)

        self.total_bugs = int(data.get("total_bugs", 0))
        raw_bugs = data.get("bugs", [])
        self.bugs = [BugDefinition.model_validate(b) for b in raw_bugs]
        self.bugs_by_code = {b.code: b for b in self.bugs}

    def _load_openapi_spec(self) -> None:
        spec_path = self.base_dir / "openapi.yaml"
        if spec_path.exists():
            with spec_path.open("r", encoding="utf-8") as f:
                self.openapi_spec = yaml.safe_load(f) or {}

    def _bind_app(self) -> None:
        """Bind FastAPI app, settings, and store module for this benchmark."""
        bench_dir_str = str(self.base_dir.resolve())
        # Remove any other benchmark directories from sys.path to ensure correct import resolution
        sys.path = [p for p in sys.path if not any(slug in p for slug in self.VALID_BENCHMARKS)]
        sys.path.insert(0, bench_dir_str)

        # Clear any previously cached 'app' imports to avoid cross-benchmark collisions
        for mod in list(sys.modules.keys()):
            if mod == "app" or mod.startswith("app."):
                del sys.modules[mod]

        # Isolate external DEBUG environment variable (e.g. DEBUG=release) from crashing Settings
        saved_debug = os.environ.get("DEBUG")
        if "DEBUG" in os.environ and os.environ["DEBUG"].lower() not in ("1", "0", "true", "false"):
            del os.environ["DEBUG"]

        try:
            import app.config
            import app.main
            import app.store
        finally:
            if saved_debug is not None and "DEBUG" not in os.environ:
                os.environ["DEBUG"] = saved_debug

        self.app = app.main.app
        self.settings = app.config.settings
        self.store = getattr(app.store, "store", None)
        self._client = TestClient(self.app, raise_server_exceptions=False)

    @property
    def client(self) -> TestClient:
        if self._client is None:
            self._client = TestClient(self.app, raise_server_exceptions=False)
        return self._client

    def reset_sandbox(self) -> None:
        """Reset internal in-memory store to baseline seed data."""
        if self.store is not None and hasattr(self.store, "seed_defaults"):
            self.store.seed_defaults()

    def set_clean_state(self) -> None:
        """Disable all bug toggles and reset sandbox to clean baseline state."""
        for bug in self.bugs:
            toggle_attr = bug.toggle_env.lower()
            if hasattr(self.settings, toggle_attr):
                setattr(self.settings, toggle_attr, False)
            os.environ[bug.toggle_env] = "0"
            if bug.toggle_env in os.environ:
                del os.environ[bug.toggle_env]

        self.reset_sandbox()

    def set_bug_state(self, bug_code: str) -> None:
        """Enforce Single-Fault Isolation: ONLY specified bug is active, others inactive."""
        if bug_code not in self.bugs_by_code:
            raise KeyError(f"Unknown bug code '{bug_code}' for benchmark '{self.slug}'")

        for bug in self.bugs:
            toggle_attr = bug.toggle_env.lower()
            if bug.code == bug_code:
                if hasattr(self.settings, toggle_attr):
                    setattr(self.settings, toggle_attr, True)
                os.environ[bug.toggle_env] = "1"
            else:
                if hasattr(self.settings, toggle_attr):
                    setattr(self.settings, toggle_attr, False)
                os.environ[bug.toggle_env] = "0"
                if bug.toggle_env in os.environ:
                    del os.environ[bug.toggle_env]

        self.reset_sandbox()

    @contextmanager
    def isolated_bug(self, bug_code: str) -> Generator[None, None, None]:
        """Context manager to activate a single bug and guarantee clean state afterwards."""
        try:
            self.set_bug_state(bug_code)
            yield
        finally:
            self.set_clean_state()
