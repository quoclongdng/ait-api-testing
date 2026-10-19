"""Tests confirming seeded bug toggles in simple-store benchmark API (Task S2-04).

Each test proves that when a bug toggle is activated, the API exhibits
the specific faulty behavior contrasting with the clean baseline.
"""

from collections.abc import Generator
from pathlib import Path
from typing import Any

import pytest
import yaml
from fastapi.testclient import TestClient

from app.config import settings


@pytest.fixture
def clean_toggles(monkeypatch: pytest.MonkeyPatch) -> Generator[None, None, None]:
    """Ensure all bug toggles are disabled before and after test."""
    for i in range(1, 11):
        setattr(settings, f"bug_{i:03d}", False)
        monkeypatch.delenv(f"BUG_{i:03d}", raising=False)
    yield
    for i in range(1, 11):
        setattr(settings, f"bug_{i:03d}", False)
        monkeypatch.delenv(f"BUG_{i:03d}", raising=False)


def test_ground_truth_file_validity() -> None:
    """Verify ground-truth.yaml exists and contains all 10 bug definitions."""
    gt_path = Path(__file__).resolve().parents[1] / "ground-truth.yaml"
    assert gt_path.exists(), f"ground-truth.yaml missing at {gt_path}"

    with gt_path.open("r", encoding="utf-8") as f:
        data: dict[str, Any] = yaml.safe_load(f)

    assert data.get("benchmark") == "simple-store"
    assert data.get("total_bugs") == 10

    bugs = data.get("bugs", [])
    assert len(bugs) == 10

    expected_codes = [f"BUG-{i:03d}" for i in range(1, 11)]
    actual_codes = [b.get("code") for b in bugs]
    assert actual_codes == expected_codes

    valid_categories = {
        "VALIDATION_MISSING",
        "STATUS_CODE_WRONG",
        "SCHEMA_MISMATCH",
        "BOUNDARY_ERROR",
        "BUSINESS_RULE_VIOLATION",
        "SEQUENCE_ERROR",
        "AUTH_ERROR",
    }
    for b in bugs:
        category = b.get("category")
        assert category in valid_categories, f"Bug {b.get('code')} has invalid category: {category}"

    # Specific category alignment with docs/architecture/experiment-design.md
    assert bugs[0]["category"] == "VALIDATION_MISSING"  # BUG-001
    assert bugs[1]["category"] == "BOUNDARY_ERROR"  # BUG-002
    assert bugs[2]["category"] == "STATUS_CODE_WRONG"  # BUG-003
    assert bugs[3]["category"] == "STATUS_CODE_WRONG"  # BUG-004
    assert bugs[4]["category"] == "SCHEMA_MISMATCH"  # BUG-005
    assert bugs[5]["category"] == "BOUNDARY_ERROR"  # BUG-006
    assert bugs[6]["category"] == "VALIDATION_MISSING"  # BUG-007
    assert bugs[7]["category"] == "BUSINESS_RULE_VIOLATION"  # BUG-008
    assert bugs[8]["category"] == "STATUS_CODE_WRONG"  # BUG-009
    assert bugs[9]["category"] == "VALIDATION_MISSING"  # BUG-010


def test_bug_001_missing_validation_name(client: TestClient, clean_toggles: None) -> None:
    """BUG-001: Missing validation accepts product creation without name."""
    payload = {
        "sku": "BUG001-TEST",
        "price": 49.99,
        "category_id": 1,
        "quantity": 10,
    }

    # 1. Clean behavior: 422 Unprocessable Entity
    settings.bug_001 = False
    clean_resp = client.post("/products", json=payload)
    assert clean_resp.status_code == 422

    # 2. Bug behavior: 201 Created accepting missing name
    settings.bug_001 = True
    bug_resp = client.post("/products", json=payload)
    assert bug_resp.status_code == 201
    assert "id" in bug_resp.json()

    # 3. Clean behavior: whitespace-only name " " satisfies minLength: 1 contract
    settings.bug_001 = False
    clean_space_resp = client.post(
        "/products",
        json={
            "name": " ",
            "sku": "BUG001-SPACE",
            "price": 49.99,
            "category_id": 1,
            "quantity": 10,
        },
    )
    assert clean_space_resp.status_code == 201
    assert clean_space_resp.json()["name"] == " "


def test_bug_002_boundary_error_negative_price(client: TestClient, clean_toggles: None) -> None:
    """BUG-002: Boundary error allows negative product price."""
    payload = {
        "name": "Negative Price Item",
        "sku": "BUG002-TEST",
        "price": -15.50,
        "category_id": 1,
        "quantity": 5,
    }

    # 1. Clean behavior: 422 Unprocessable Entity
    settings.bug_002 = False
    clean_resp = client.post("/products", json=payload)
    assert clean_resp.status_code == 422

    # 2. Bug behavior: 201 Created accepting negative price
    settings.bug_002 = True
    bug_resp = client.post("/products", json=payload)
    assert bug_resp.status_code == 201
    assert bug_resp.json()["price"] == -15.50


def test_bug_003_wrong_status_code_create_product(client: TestClient, clean_toggles: None) -> None:
    """BUG-003: Wrong status code returns 200 instead of 201 on create."""
    payload_clean = {
        "name": "Clean Status Item",
        "sku": "BUG003-CLEAN",
        "price": 25.00,
        "category_id": 1,
    }
    payload_bug = {
        "name": "Bug Status Item",
        "sku": "BUG003-BUG",
        "price": 25.00,
        "category_id": 1,
    }

    # 1. Clean behavior: 201 Created
    settings.bug_003 = False
    clean_resp = client.post("/products", json=payload_clean)
    assert clean_resp.status_code == 201

    # 2. Bug behavior: 200 OK
    settings.bug_003 = True
    bug_resp = client.post("/products", json=payload_bug)
    assert bug_resp.status_code == 200


def test_bug_004_wrong_status_code_not_found(client: TestClient, clean_toggles: None) -> None:
    """BUG-004: Returns 200 with {} instead of 404 when product is missing."""
    # 1. Clean behavior: 404 Not Found
    settings.bug_004 = False
    clean_resp = client.get("/products/999999")
    assert clean_resp.status_code == 404

    # 2. Bug behavior: 200 OK with empty dict {}
    settings.bug_004 = True
    bug_resp = client.get("/products/999999")
    assert bug_resp.status_code == 200
    assert bug_resp.json() == {}


def test_bug_005_schema_mismatch_omits_sku(client: TestClient, clean_toggles: None) -> None:
    """BUG-005: Schema mismatch omits required 'sku' property."""
    # 1. Clean behavior: response includes 'sku'
    settings.bug_005 = False
    clean_resp = client.get("/products/1")
    assert clean_resp.status_code == 200
    assert "sku" in clean_resp.json()

    # 2. Bug behavior: response omits 'sku'
    settings.bug_005 = True
    bug_resp = client.get("/products/1")
    assert bug_resp.status_code == 200
    assert "sku" not in bug_resp.json()


def test_bug_006_boundary_error_quantity_overflow(client: TestClient, clean_toggles: None) -> None:
    """BUG-006: Boundary error allows updating quantity > 100,000."""
    update_payload = {"quantity": 150000}

    # 1. Clean behavior: 422 Unprocessable Entity
    settings.bug_006 = False
    clean_resp = client.put("/products/1", json=update_payload)
    assert clean_resp.status_code == 422

    # 2. Bug behavior: 200 OK with updated quantity
    settings.bug_006 = True
    bug_resp = client.put("/products/1", json=update_payload)
    assert bug_resp.status_code == 200
    assert bug_resp.json()["quantity"] == 150000


def test_bug_007_missing_validation_limit_crash(client: TestClient, clean_toggles: None) -> None:
    """BUG-007: Limit outside 1..100 causes 500 error instead of 422."""
    # 1. Clean behavior: 422 Unprocessable Entity
    settings.bug_007 = False
    clean_resp = client.get("/products?limit=0")
    assert clean_resp.status_code == 422

    # 2. Bug behavior: 500 Internal Server Error (server crash)
    settings.bug_007 = True
    with pytest.raises(RuntimeError):
        client.get("/products?limit=0")


def test_bug_008_business_rule_duplicate_slug(client: TestClient, clean_toggles: None) -> None:
    """BUG-008: Business rule violation allows duplicate category slug."""
    payload = {
        "name": "Another Electronics",
        "slug": "electronics",  # already seeded
        "description": "Duplicate category slug",
    }

    # 1. Clean behavior: 409 Conflict
    settings.bug_008 = False
    clean_resp = client.post("/categories", json=payload)
    assert clean_resp.status_code == 409

    # 2. Bug behavior: 201 Created accepting duplicate slug
    settings.bug_008 = True
    bug_resp = client.post("/categories", json=payload)
    assert bug_resp.status_code == 201
    assert bug_resp.json()["slug"] == "electronics"


def test_bug_009_wrong_status_code_delete_product(client: TestClient, clean_toggles: None) -> None:
    """BUG-009: Delete product returns 200 with body instead of 204."""
    # 1. Clean behavior: 204 No Content
    settings.bug_009 = False
    clean_resp = client.delete("/products/1")
    assert clean_resp.status_code == 204
    assert clean_resp.text == ""

    # 2. Bug behavior: 200 OK with JSON body
    settings.bug_009 = True
    bug_resp = client.delete("/products/2")
    assert bug_resp.status_code == 200
    assert "message" in bug_resp.json()


def test_bug_010_missing_validation_string_id(client: TestClient, clean_toggles: None) -> None:
    """BUG-010: Non-numeric string ID in path causes unhandled 500 crash."""
    # 1. Clean behavior: 422 Unprocessable Entity
    settings.bug_010 = False
    clean_resp = client.get("/products/invalid-id-string")
    assert clean_resp.status_code == 422

    # 2. Bug behavior: unhandled exception (500)
    settings.bug_010 = True
    with pytest.raises(ValueError):
        client.get("/products/invalid-id-string")
