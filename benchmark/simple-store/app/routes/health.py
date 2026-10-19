"""Health check route for simple-store benchmark API."""

from fastapi import APIRouter

from app.schemas import HealthResponse

router = APIRouter(tags=["Health"])


@router.get(
    "/health",
    response_model=HealthResponse,
    summary="Health check",
    description="Check the operational health of the simple-store service.",
)
def get_health() -> HealthResponse:
    return HealthResponse(
        status="ok",
        service="simple-store",
        version="1.0.0",
    )
