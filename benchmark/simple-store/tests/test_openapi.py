"""Tests validating the openapi.yaml specification file and runtime /openapi.json endpoint."""

from pathlib import Path
from typing import Any

import yaml
from fastapi.testclient import TestClient

from app.config import settings


def test_openapi_yaml_exists_and_is_valid() -> None:
    spec_path = Path(__file__).resolve().parent.parent / "openapi.yaml"
    assert spec_path.exists(), f"openapi.yaml must exist at {spec_path}"

    content = spec_path.read_text(encoding="utf-8")
    data = yaml.safe_load(content)

    assert isinstance(data, dict), "openapi.yaml must parse as a dictionary"
    assert "openapi" in data, "OpenAPI version must be specified"
    assert data["openapi"].startswith("3."), f"Expected OpenAPI 3.x, got {data['openapi']}"

    assert "info" in data
    assert data["info"]["title"] == "Simple Store Benchmark API"

    assert "paths" in data
    paths = data["paths"]
    assert "/health" in paths
    assert "/categories" in paths
    assert "/categories/{id}" in paths
    assert "/products" in paths
    assert "/products/{id}" in paths

    assert "components" in data
    assert "schemas" in data["components"]
    schemas = data["components"]["schemas"]
    assert "ProductResponse" in schemas
    assert "CategoryResponse" in schemas


def _assert_baseline_constraints(data: dict[str, Any]) -> None:
    """Verify that all baseline constraints required by AIT test generators are present."""
    schemas = data["components"]["schemas"]

    # 1. ProductCreate constraints
    product_create = schemas["ProductCreate"]
    assert "name" in product_create["required"], "ProductCreate must require 'name'"
    assert product_create["properties"]["name"]["minLength"] == 1, (
        "ProductCreate.name must have minLength: 1"
    )
    assert product_create["properties"]["name"]["maxLength"] == 100, (
        "ProductCreate.name must have maxLength: 100"
    )
    assert product_create["properties"]["price"]["minimum"] == 0.0, (
        "ProductCreate.price must have minimum: 0.0"
    )
    assert product_create["properties"]["quantity"]["maximum"] == 100000.0, (
        "ProductCreate.quantity must have maximum: 100,000"
    )

    # 2. ProductUpdate constraints
    product_update = schemas["ProductUpdate"]
    qty_prop = product_update["properties"]["quantity"]
    qty_max = (
        qty_prop.get("maximum")
        if "maximum" in qty_prop
        else qty_prop.get("anyOf", [{}])[0].get("maximum")
    )
    assert qty_max == 100000.0, "ProductUpdate.quantity must have maximum: 100,000"

    # 3. GET /products query parameter 'limit' constraints
    paths = data["paths"]
    list_params = paths["/products"]["get"]["parameters"]
    limit_param = next(p for p in list_params if p["name"] == "limit")
    limit_schema = limit_param["schema"]
    assert limit_schema.get("minimum") == 1, "limit query parameter must have minimum: 1"
    assert limit_schema.get("maximum") == 100, "limit query parameter must have maximum: 100"

    # 4. GET /products/{id} path parameter 'id' constraints (integer, not string)
    get_params = paths["/products/{id}"]["get"]["parameters"]
    id_param = next(p for p in get_params if p["name"] == "id")
    id_schema = id_param["schema"]
    assert id_schema.get("type") == "integer", (
        f"GET /products/{{id}} path param id must be integer, got {id_schema.get('type')}"
    )
    assert id_schema.get("minimum") == 1, "id path parameter must have minimum: 1"


def test_runtime_openapi_json_publishes_baseline_contract_clean(client: TestClient) -> None:
    """Ensure GET /openapi.json publishes the complete baseline contract with clean toggles."""
    resp = client.get("/openapi.json")
    assert resp.status_code == 200
    data = resp.json()

    _assert_baseline_constraints(data)


def test_runtime_openapi_json_publishes_baseline_contract_under_bug_toggles(
    client: TestClient,
) -> None:
    """Ensure GET /openapi.json remains identical to the baseline contract with bug toggles."""
    settings.bug_001 = True
    settings.bug_002 = True
    settings.bug_006 = True
    settings.bug_007 = True
    settings.bug_010 = True

    try:
        resp = client.get("/openapi.json")
        assert resp.status_code == 200
        data = resp.json()

        _assert_baseline_constraints(data)
    finally:
        settings.bug_001 = False
        settings.bug_002 = False
        settings.bug_006 = False
        settings.bug_007 = False
        settings.bug_010 = False
