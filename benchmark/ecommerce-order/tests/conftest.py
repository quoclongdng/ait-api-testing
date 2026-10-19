from collections.abc import Generator

import pytest
from fastapi.testclient import TestClient

from app.main import app
from app.store import store

ALICE_HEADERS = {"Authorization": "Bearer customer-alice-token"}
BOB_HEADERS = {"Authorization": "Bearer customer-bob-token"}
ADMIN_HEADERS = {"Authorization": "Bearer admin-token"}


@pytest.fixture(autouse=True)
def reset_store() -> Generator[None, None, None]:
    store.seed_defaults()
    yield
    store.seed_defaults()


@pytest.fixture
def client() -> Generator[TestClient, None, None]:
    with TestClient(app) as test_client:
        yield test_client
