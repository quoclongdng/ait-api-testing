"""Pytest fixtures for simple-store benchmark API."""

from collections.abc import Generator

import pytest
from fastapi.testclient import TestClient

from app.main import app
from app.store import store


@pytest.fixture(autouse=True)
def reset_store() -> Generator[None, None, None]:
    """Ensure every test runs against a clean, deterministic seed state."""
    store.seed_defaults()
    yield
    store.seed_defaults()


@pytest.fixture
def client() -> Generator[TestClient, None, None]:
    """Provide a TestClient instance for testing the FastAPI application."""
    with TestClient(app) as test_client:
        yield test_client
