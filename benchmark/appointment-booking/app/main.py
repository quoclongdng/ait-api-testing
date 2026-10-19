"""FastAPI application entry point for appointment-booking benchmark."""

from functools import lru_cache
from pathlib import Path
from typing import Any

import yaml
from fastapi import FastAPI
from fastapi.responses import JSONResponse

from app.routes.appointments import router as appointments_router
from app.routes.departments import router as departments_router
from app.routes.doctors import router as doctors_router
from app.routes.internal import router as internal_router
from app.schemas import HealthResponse

OPENAPI_SPEC_PATH = Path(__file__).resolve().parent.parent / "openapi.yaml"

app = FastAPI(
    title="Appointment Booking Benchmark API",
    version="1.0.0",
    description=(
        "Benchmark #3 sandbox for AI Testing Platform. It models doctors, departments, "
        "complex time-based scheduling rules, 24h reschedule window, and authorization."
    ),
)


@app.get("/health", response_model=HealthResponse, tags=["Health"])
def health_check() -> HealthResponse:
    """Check service health and sandbox readiness."""
    return HealthResponse()


@lru_cache
def get_baseline_openapi() -> dict[str, Any]:
    """Publish the authoritative openapi.yaml contract instead of the generated schema."""
    with open(OPENAPI_SPEC_PATH, encoding="utf-8") as f:
        data: dict[str, Any] = yaml.safe_load(f)
        return data


app.openapi = get_baseline_openapi

app.include_router(departments_router)
app.include_router(doctors_router)
app.include_router(appointments_router)
app.include_router(internal_router)


@app.get("/", include_in_schema=False)
def root_redirect() -> JSONResponse:
    """Root endpoint for status inspection."""
    return JSONResponse(
        content={
            "service": "appointment-booking",
            "version": "1.0.0",
            "status": "healthy",
            "docs": "/docs",
        }
    )
