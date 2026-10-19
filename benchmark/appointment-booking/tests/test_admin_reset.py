"""Regression: experiments reset the sandbox to its baseline seed data before every run.

experiment-design.md §4.3.1 requires `POST /__admin/reset`; without it, state left by earlier
clean/bug runs leaked into later repetitions.
"""

from fastapi.testclient import TestClient

from app.main import app
from app.store import store


def test_admin_reset_restores_baseline_seed_data() -> None:
    baseline = len(store.departments)
    assert baseline > 0
    store.departments.clear()

    with TestClient(app) as client:
        response = client.post("/__admin/reset")

    assert response.status_code == 200
    assert len(store.departments) == baseline


def test_admin_reset_is_not_part_of_the_published_contract() -> None:
    with TestClient(app) as client:
        paths = client.get("/openapi.json").json()["paths"]

    assert "/__admin/reset" not in paths
