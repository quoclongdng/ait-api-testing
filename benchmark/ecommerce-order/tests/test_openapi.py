from pathlib import Path
from typing import Any

import yaml
from fastapi.testclient import TestClient

from app.main import app

HTTP_METHODS = {"get", "post", "put", "patch", "delete"}


def load_spec() -> dict[str, Any]:
    path = Path(__file__).resolve().parent.parent / "openapi.yaml"
    assert path.exists()
    data: dict[str, Any] = yaml.safe_load(path.read_text(encoding="utf-8"))
    return data


def spec_operations(spec: dict[str, Any]) -> set[tuple[str, str]]:
    return {
        (method.upper(), path)
        for path, path_item in spec["paths"].items()
        for method in path_item
        if method in HTTP_METHODS
    }


def app_operations() -> set[tuple[str, str]]:
    # /__admin/reset: sandbox reset for experiments (§4.3.1), not a business route.
    excluded = {"/docs", "/docs/oauth2-redirect", "/redoc", "/openapi.json", "/__admin/reset"}
    operations: set[tuple[str, str]] = set()

    def collect(routes: list[object]) -> None:
        for route in routes:
            nested_router = getattr(route, "original_router", None)
            if nested_router is not None:
                collect(nested_router.routes)
                continue

            path = getattr(route, "path", None)
            methods = getattr(route, "methods", set())
            # Internal sandbox routes (e.g. POST /__admin/reset) are hidden from the schema.
            if path not in excluded and getattr(route, "include_in_schema", True):
                operations.update(
                    (method, path)
                    for method in methods
                    if method in {"GET", "POST", "PUT", "PATCH", "DELETE"}
                )

    collect(app.routes)
    return operations


def test_static_openapi_is_valid_and_matches_every_runtime_route() -> None:
    spec = load_spec()
    assert spec["openapi"].startswith("3.")
    assert spec["info"]["title"] == "E-commerce Order Benchmark API"
    assert spec_operations(spec) == app_operations()

    business_operations = {
        operation for operation in spec_operations(spec) if operation[1] != "/health"
    }
    assert len(business_operations) == 12


def test_openapi_describes_workflow_and_rbac_contracts() -> None:
    spec = load_spec()
    assert spec["components"]["securitySchemes"]["bearerAuth"]["scheme"] == "bearer"

    protected_paths = {
        "/cart",
        "/cart/items",
        "/checkout",
        "/orders",
        "/orders/{order_id}",
        "/orders/{order_id}/pay",
        "/orders/{order_id}/cancel",
        "/admin/orders",
        "/coupons/apply",
    }
    for path in protected_paths:
        for operation in spec["paths"][path].values():
            if isinstance(operation, dict) and "responses" in operation:
                assert operation["security"] == [{"bearerAuth": []}]

    order_schema = spec["components"]["schemas"]["OrderResponse"]
    assert order_schema["properties"]["total_amount"]["type"] == "number"
    assert order_schema["properties"]["status"]["enum"] == [
        "PENDING",
        "PAID",
        "SHIPPED",
        "CANCELLED",
    ]


def test_runtime_openapi_publishes_authoritative_static_contract(client: TestClient) -> None:
    response = client.get("/openapi.json")
    assert response.status_code == 200
    assert response.json() == load_spec()
