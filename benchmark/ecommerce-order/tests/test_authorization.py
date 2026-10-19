from fastapi.testclient import TestClient

from tests.conftest import ADMIN_HEADERS, ALICE_HEADERS, BOB_HEADERS


def test_protected_endpoint_requires_valid_bearer_token(client: TestClient) -> None:
    missing = client.get("/cart")
    assert missing.status_code == 401
    assert missing.json()["detail"]["code"] == "UNAUTHENTICATED"

    invalid = client.get("/cart", headers={"Authorization": "Bearer invalid-token"})
    assert invalid.status_code == 401
    assert invalid.json()["detail"]["code"] == "UNAUTHENTICATED"


def test_customer_cannot_access_admin_orders(client: TestClient) -> None:
    forbidden = client.get("/admin/orders", headers=ALICE_HEADERS)
    assert forbidden.status_code == 403
    assert forbidden.json()["detail"]["code"] == "FORBIDDEN"

    allowed = client.get("/admin/orders", headers=ADMIN_HEADERS)
    assert allowed.status_code == 200
    assert {order["id"] for order in allowed.json()} == {901, 902}


def test_customer_cannot_read_another_customers_order(client: TestClient) -> None:
    bob_order = client.get("/orders/901", headers=BOB_HEADERS)
    assert bob_order.status_code == 200

    alice_attempt = client.get("/orders/901", headers=ALICE_HEADERS)
    assert alice_attempt.status_code == 403
    assert alice_attempt.json()["detail"]["code"] == "FORBIDDEN"

    admin_access = client.get("/orders/901", headers=ADMIN_HEADERS)
    assert admin_access.status_code == 200


def test_customer_cannot_checkout_another_customers_cart(client: TestClient) -> None:
    response = client.post("/checkout", json={"cart_id": 102}, headers=ALICE_HEADERS)
    assert response.status_code == 403
    assert response.json()["detail"]["code"] == "FORBIDDEN"


def test_admin_cannot_use_customer_cart_actions(client: TestClient) -> None:
    response = client.get("/cart", headers=ADMIN_HEADERS)
    assert response.status_code == 403
    assert response.json()["detail"]["code"] == "FORBIDDEN"

    payment = client.post("/orders/901/pay", headers=ADMIN_HEADERS)
    assert payment.status_code == 403
    assert payment.json()["detail"]["code"] == "FORBIDDEN"
