"""Internal sandbox administration routes (experiment-design.md §4.3.1).

These routes are deliberately excluded from openapi.yaml and from the runtime OpenAPI schema so
test generators never target them. The experiment runner calls `POST /__admin/reset` to restore
the baseline seed data before each clean or single-bug run.
"""

from fastapi import APIRouter

from app.store import store

SERVICE_NAME = "appointment-booking"

router = APIRouter(include_in_schema=False)


@router.post("/__admin/reset")
def reset_sandbox() -> dict[str, str]:
    """Restore the in-memory store to the deterministic baseline seed data."""
    store.seed_defaults()
    return {"status": "reset", "service": SERVICE_NAME}
