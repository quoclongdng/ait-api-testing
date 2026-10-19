from fastapi.testclient import TestClient


def test_health_and_product_catalog_are_public(client: TestClient) -> None:
    health = client.get("/health")
    assert health.status_code == 200
    assert health.json() == {"status": "ok", "service": "ecommerce-order"}

    products = client.get("/products")
    assert products.status_code == 200
    assert len(products.json()) == 3
    assert all(product["stock"] > 0 for product in products.json())


def test_product_not_found_has_stable_error_code(client: TestClient) -> None:
    response = client.get("/products/999")
    assert response.status_code == 404
    assert response.json()["detail"]["code"] == "PRODUCT_NOT_FOUND"

    invalid_id = client.get("/products/0")
    assert invalid_id.status_code == 422
