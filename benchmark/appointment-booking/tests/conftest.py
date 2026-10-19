import os
import sys
from collections.abc import Generator
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

# Ensure app package is importable when pytest is run from repository root
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from app.config import settings
from app.main import app
from app.store import store


@pytest.fixture(autouse=True)
def clean_baseline() -> Generator[None, None, None]:
    """Ensure all seeded bug toggles are disabled and in-memory state is pristine."""
    for i in range(23, 33):
        os.environ.pop(f"BUG_{i:03d}", None)
        setattr(settings, f"bug_{i:03d}", False)
    store.seed_defaults()
    yield
    for i in range(23, 33):
        os.environ.pop(f"BUG_{i:03d}", None)
        setattr(settings, f"bug_{i:03d}", False)
    store.seed_defaults()


@pytest.fixture
def client() -> TestClient:
    """FastAPI TestClient fixture."""
    return TestClient(app)
