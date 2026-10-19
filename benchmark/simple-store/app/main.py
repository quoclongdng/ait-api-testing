"""Main FastAPI application for simple-store benchmark API."""

from collections.abc import AsyncGenerator
from contextlib import asynccontextmanager
from functools import lru_cache
from pathlib import Path
from typing import Any

import yaml
from fastapi import FastAPI

from app.config import settings
from app.routes import categories, health, internal, products
from app.store import store

OPENAPI_SPEC_PATH = Path(__file__).resolve().parent.parent / "openapi.yaml"


@asynccontextmanager
async def lifespan(app: FastAPI) -> AsyncGenerator[None, None]:
    # Ensure fresh seed state on application startup
    store.seed_defaults()
    yield


app = FastAPI(
    title=settings.app_name,
    version=settings.app_version,
    description=(
        "Benchmark #1 sandbox REST API for AI Testing Platform (AIT). "
        "Provides product catalog, categories, and inventory management endpoints."
    ),
    servers=[
        {"url": "http://localhost:8002", "description": "Sandbox host port"},
        {"url": "http://benchmark:8000", "description": "Internal docker network"},
    ],
    lifespan=lifespan,
    docs_url="/docs",
    redoc_url="/redoc",
    openapi_url="/openapi.json",
)


@lru_cache
def get_baseline_openapi() -> dict[str, Any]:
    """Publish the authoritative baseline OpenAPI contract for runtime discovery."""
    with open(OPENAPI_SPEC_PATH, encoding="utf-8") as f:
        data: dict[str, Any] = yaml.safe_load(f)
        return data


app.openapi = get_baseline_openapi

# Mount feature routers
app.include_router(health.router)
app.include_router(categories.router)
app.include_router(products.router)
app.include_router(internal.router)
