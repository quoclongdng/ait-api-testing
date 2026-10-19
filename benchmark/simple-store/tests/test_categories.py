"""Tests for categories endpoints."""

from fastapi.testclient import TestClient


def test_list_categories_default(client: TestClient) -> None:
    response = client.get("/categories")
    assert response.status_code == 200
    data = response.json()
    assert isinstance(data, list)
    assert len(data) >= 2
    assert any(c["slug"] == "books" for c in data)
    assert any(c["slug"] == "electronics" for c in data)


def test_list_categories_pagination(client: TestClient) -> None:
    response = client.get("/categories?limit=1&offset=0")
    assert response.status_code == 200
    data = response.json()
    assert len(data) == 1

    response_offset = client.get("/categories?limit=1&offset=1")
    assert response_offset.status_code == 200
    data_offset = response_offset.json()
    assert len(data_offset) == 1
    assert data[0]["id"] != data_offset[0]["id"]


def test_get_category_by_id_success(client: TestClient) -> None:
    response = client.get("/categories/1")
    assert response.status_code == 200
    data = response.json()
    assert data["id"] == 1
    assert data["name"] == "Books"
    assert data["slug"] == "books"


def test_get_category_by_id_not_found(client: TestClient) -> None:
    response = client.get("/categories/99999")
    assert response.status_code == 404
    data = response.json()
    assert "not found" in data["detail"].lower()


def test_create_category_success(client: TestClient) -> None:
    payload = {
        "name": "Home & Garden",
        "slug": "home-garden",
        "description": "Furniture and home decor",
    }
    response = client.post("/categories", json=payload)
    assert response.status_code == 201
    data = response.json()
    assert data["id"] > 0
    assert data["name"] == payload["name"]
    assert data["slug"] == payload["slug"]
    assert data["description"] == payload["description"]


def test_create_category_duplicate_slug_conflict(client: TestClient) -> None:
    # "books" already exists in default seed
    payload = {
        "name": "Different Books",
        "slug": "books",
        "description": "Duplicate slug test",
    }
    response = client.post("/categories", json=payload)
    assert response.status_code == 409
    data = response.json()
    assert "already exists" in data["detail"].lower()


def test_create_category_missing_name_fails(client: TestClient) -> None:
    payload = {
        "slug": "no-name-category",
    }
    response = client.post("/categories", json=payload)
    assert response.status_code == 422


def test_create_category_invalid_slug_pattern_fails(client: TestClient) -> None:
    payload = {
        "name": "Invalid Slug",
        "slug": "INVALID SLUG WITH SPACES!",
    }
    response = client.post("/categories", json=payload)
    assert response.status_code == 422
