"""FastAPI application for Benchmark #2: E-commerce Order."""

from collections.abc import AsyncGenerator
from contextlib import asynccontextmanager
from functools import lru_cache
from pathlib import Path
from typing import Any

import yaml
from fastapi import FastAPI

from app.routes import admin, cart, coupons, health, internal, orders, products
from app.store import store

OPENAPI_SPEC_PATH = Path(__file__).resolve().parent.parent / "openapi.yaml"


@asynccontextmanager
async def lifespan(_app: FastAPI) -> AsyncGenerator[None, None]:
    store.seed_defaults()
    yield


app = FastAPI(
    title="E-commerce Order Benchmark API",
    version="1.0.0",
    description="Benchmark #2 sandbox for multi-step workflows and RBAC.",
    lifespan=lifespan,
    docs_url="/docs",
    redoc_url="/redoc",
    openapi_url="/openapi.json",
)


@lru_cache
def get_baseline_openapi() -> dict[str, Any]:
    with OPENAPI_SPEC_PATH.open(encoding="utf-8") as file:
        data: dict[str, Any] = yaml.safe_load(file)
    return data


app.openapi = get_baseline_openapi
app.include_router(health.router)
app.include_router(products.router)
app.include_router(cart.router)
app.include_router(orders.router)
app.include_router(admin.router)
app.include_router(coupons.router)
app.include_router(internal.router)
