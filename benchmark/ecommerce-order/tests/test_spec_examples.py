"""S11-01: the `examples` of openapi.yaml match the seeded data of the clean sandbox.

RULE builds its valid requests from these examples (the default role is customer alice), so an
example that points at nothing would turn a valid case into a false positive on the clean variant.
"""

from pathlib import Path
from typing import Any

import yaml
from fastapi.testclient import TestClient

from tests.conftest import ALICE_HEADERS

SPEC = yaml.safe_load((Path(__file__).resolve().parents[1] / "openapi.yaml").read_text())
SCHEMAS = SPEC["components"]["schemas"]
PARAMETERS = SPEC["components"]["parameters"]


def _example(schema: dict[str, Any]) -> Any:
    return schema["examples"][0]


def _body(schema_name: str) -> dict[str, Any]:
    return {name: _example(prop) for name, prop in SCHEMAS[schema_name]["properties"].items()}


def test_product_example_is_a_seeded_product_with_stock(client: TestClient) -> None:
    product_id = _example(PARAMETERS["ProductId"]["schema"])
    response = client.get(f"/products/{product_id}")
    assert response.status_code == 200
    item = _body("CartItemCreate")
    assert item["product_id"] == product_id
    assert response.json()["stock"] >= item["quantity"]


def test_order_example_is_an_order_of_the_default_customer(client: TestClient) -> None:
    order_id = _example(PARAMETERS["OrderId"]["schema"])
    assert client.get(f"/orders/{order_id}", headers=ALICE_HEADERS).status_code == 200


def test_cart_id_examples_are_the_default_customers_cart(client: TestClient) -> None:
    cart_id = client.get("/cart", headers=ALICE_HEADERS).json()["id"]
    assert _example(SCHEMAS["CheckoutRequest"]["properties"]["cart_id"]) == cart_id
    assert _example(SCHEMAS["CouponApplyRequest"]["properties"]["cart_id"]) == cart_id


def test_body_examples_are_accepted_by_the_clean_sandbox(client: TestClient) -> None:
    added = client.post("/cart/items", json=_body("CartItemCreate"), headers=ALICE_HEADERS)
    assert added.status_code == 201
    checkout = client.post("/checkout", json=_body("CheckoutRequest"), headers=ALICE_HEADERS)
    assert checkout.status_code == 201
    order = client.post(
        "/orders", json={"items": [_body("OrderItemCreate")]}, headers=ALICE_HEADERS
    )
    assert order.status_code == 201


def test_coupon_code_example_is_a_known_coupon(client: TestClient) -> None:
    response = client.post(
        "/coupons/apply", json=_body("CouponApplyRequest"), headers=ALICE_HEADERS
    )
    # The seeded cart is empty: the coupon exists (no 404) but the minimum subtotal is not met.
    assert response.status_code == 409
