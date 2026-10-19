"""Tests for products endpoints."""

from fastapi.testclient import TestClient


def test_list_products_default(client: TestClient) -> None:
    response = client.get("/products")
    assert response.status_code == 200
    data = response.json()
    assert isinstance(data, list)
    assert len(data) >= 2
    # Verify required schema fields on seeded products
    for item in data:
        assert "id" in item
        assert "name" in item
        assert "sku" in item
        assert "price" in item
        assert "category_id" in item
        assert "quantity" in item
        assert "created_at" in item


def test_list_products_filter_by_category(client: TestClient) -> None:
    response = client.get("/products?category_id=1")
    assert response.status_code == 200
    data = response.json()
    assert all(p["category_id"] == 1 for p in data)


def test_list_products_invalid_limit_boundary_fails(client: TestClient) -> None:
    # limit <= 0 should fail validation (422)
    res_zero = client.get("/products?limit=0")
    assert res_zero.status_code == 422

    res_negative = client.get("/products?limit=-5")
    assert res_negative.status_code == 422

    # limit > 100 should fail validation (422)
    res_excess = client.get("/products?limit=101")
    assert res_excess.status_code == 422


def test_get_product_by_id_success(client: TestClient) -> None:
    response = client.get("/products/1")
    assert response.status_code == 200
    data = response.json()
    assert data["id"] == 1
    assert data["name"] == "Software Testing Guide"
    assert data["sku"] == "BOOK-001"  # Required field
    assert data["price"] == 39.99
    assert data["category_id"] == 1


def test_get_product_by_id_not_found(client: TestClient) -> None:
    response = client.get("/products/99999")
    assert response.status_code == 404
    data = response.json()
    assert "not found" in data["detail"].lower()


def test_get_product_by_invalid_string_id_fails(client: TestClient) -> None:
    # Passing a string to an integer path param must return 422, not 500
    response = client.get("/products/invalid-id-string")
    assert response.status_code == 422


def test_create_product_success(client: TestClient) -> None:
    payload = {
        "name": "USB-C Fast Charger",
        "sku": "CHG-65W",
        "price": 24.99,
        "category_id": 2,
        "quantity": 200,
        "description": "65W GaN Charger",
    }
    response = client.post("/products", json=payload)
    assert response.status_code == 201
    data = response.json()
    assert data["id"] > 0
    assert data["name"] == payload["name"]
    assert data["sku"] == payload["sku"]
    assert data["price"] == payload["price"]
    assert data["category_id"] == payload["category_id"]
    assert data["quantity"] == payload["quantity"]
    assert data["description"] == payload["description"]
    assert "created_at" in data


def test_create_product_missing_name_fails(client: TestClient) -> None:
    payload = {
        "sku": "NONAME-01",
        "price": 10.0,
        "category_id": 1,
    }
    response = client.post("/products", json=payload)
    assert response.status_code == 422


def test_create_product_negative_price_fails(client: TestClient) -> None:
    payload = {
        "name": "Negative Price Item",
        "sku": "NEG-001",
        "price": -15.5,
        "category_id": 1,
    }
    response = client.post("/products", json=payload)
    assert response.status_code == 422


def test_create_product_category_not_found(client: TestClient) -> None:
    payload = {
        "name": "No Category Product",
        "sku": "NOCAT-01",
        "price": 19.99,
        "category_id": 99999,
    }
    response = client.post("/products", json=payload)
    assert response.status_code == 404
    data = response.json()
    assert "category with id 99999 not found" in data["detail"].lower()


def test_create_product_duplicate_sku_conflict(client: TestClient) -> None:
    # "BOOK-001" already exists in default seed
    payload = {
        "name": "Another Guide",
        "sku": "BOOK-001",
        "price": 15.0,
        "category_id": 1,
    }
    response = client.post("/products", json=payload)
    assert response.status_code == 409
    data = response.json()
    assert "already exists" in data["detail"].lower()


def test_update_product_success(client: TestClient) -> None:
    payload = {
        "name": "Software Testing Masterclass (2nd Ed)",
        "price": 49.99,
        "quantity": 300,
    }
    response = client.put("/products/1", json=payload)
    assert response.status_code == 200
    data = response.json()
    assert data["id"] == 1
    assert data["name"] == payload["name"]
    assert data["price"] == payload["price"]
    assert data["quantity"] == payload["quantity"]
    assert data["sku"] == "BOOK-001"  # Unchanged


def test_update_product_quantity_exceeds_max_fails(client: TestClient) -> None:
    payload = {
        "quantity": 100001,  # Max allowed is 100,000
    }
    response = client.put("/products/1", json=payload)
    assert response.status_code == 422


def test_update_product_not_found(client: TestClient) -> None:
    payload = {"name": "Nonexistent"}
    response = client.put("/products/99999", json=payload)
    assert response.status_code == 404


def test_delete_product_success(client: TestClient) -> None:
    response = client.delete("/products/1")
    assert response.status_code == 204
    assert response.content == b""  # 204 No Content has empty body

    # Verify subsequent GET returns 404
    get_res = client.get("/products/1")
    assert get_res.status_code == 404


def test_delete_product_not_found(client: TestClient) -> None:
    response = client.delete("/products/99999")
    assert response.status_code == 404
