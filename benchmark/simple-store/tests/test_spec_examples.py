"""S11-01: the `examples` of openapi.yaml match the seeded data of the clean sandbox.

RULE builds its valid requests from these examples, so an example that points at nothing (an
unknown id) would turn a valid case into a false positive on the clean variant.
"""

from pathlib import Path
from typing import Any

import yaml
from fastapi.testclient import TestClient

SPEC = yaml.safe_load((Path(__file__).resolve().parents[1] / "openapi.yaml").read_text())


def _example(schema: dict[str, Any]) -> Any:
    if "$ref" in schema:
        name = schema["$ref"].rsplit("/", 1)[-1]
        return _example(SPEC["components"]["schemas"][name])
    if "examples" in schema:
        return schema["examples"][0]
    for branch in schema.get("anyOf", []):
        if "examples" in branch:
            return branch["examples"][0]
    raise KeyError("no example")


def _param_example(path: str, method: str, name: str) -> Any:
    for param in SPEC["paths"][path][method]["parameters"]:
        if param["name"] == name:
            return _example(param["schema"])
    raise KeyError(name)


def test_every_path_parameter_has_an_example() -> None:
    for path, item in SPEC["paths"].items():
        for method, operation in item.items():
            for param in operation.get("parameters", []):
                if param["in"] == "path":
                    assert _example(param["schema"]) is not None, f"{method} {path} {param['name']}"


def test_path_parameter_examples_name_seeded_resources(client: TestClient) -> None:
    category_id = _param_example("/categories/{id}", "get", "id")
    assert client.get(f"/categories/{category_id}").status_code == 200
    for method in ("get", "put", "delete"):
        product_id = _param_example("/products/{id}", method, "id")
        assert client.get(f"/products/{product_id}").status_code == 200, method


def test_category_filter_example_returns_products(client: TestClient) -> None:
    category_id = _param_example("/products", "get", "category_id")
    response = client.get("/products", params={"category_id": category_id})
    assert response.status_code == 200
    assert response.json() != []


def test_product_create_examples_are_accepted(client: TestClient) -> None:
    props = SPEC["components"]["schemas"]["ProductCreate"]["properties"]
    body = {
        "name": "Example product",
        "sku": "EXAMPLE-001",
        "price": _example(props["price"]),
        "category_id": _example(props["category_id"]),
        "quantity": _example(props["quantity"]),
    }
    assert client.post("/products", json=body).status_code == 201


def test_slug_examples_do_not_collide_with_seeded_categories(client: TestClient) -> None:
    seeded = {category["slug"] for category in client.get("/categories").json()}
    slug_schema = SPEC["components"]["schemas"]["CategoryCreate"]["properties"]["slug"]
    for slug in slug_schema["examples"]:
        assert slug not in seeded
        response = client.post("/categories", json={"name": slug.title(), "slug": slug})
        assert response.status_code == 201, slug
