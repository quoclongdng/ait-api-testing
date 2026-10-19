"""Tests confirming seeded bug toggles in ecommerce-order benchmark API (Task S7-04).

Each test proves that when a bug toggle is activated, the API exhibits
the specific faulty behavior contrasting with the clean baseline.
"""

import json
import os
from collections.abc import Generator
from pathlib import Path
from typing import Any

import jsonschema
import pytest
import yaml
from fastapi.testclient import TestClient

from app.config import Settings, get_settings, is_bug_active, settings
from app.store import store

DSL_SCHEMA_RELATIVE = Path("docs") / "contracts" / "test-dsl.schema.json"


def find_dsl_schema(start: Path | None = None) -> Path:
    """Locate the shared DSL schema by walking up from this file.

    Regression (S10-01 audit r2): indexing a fixed ancestor of this file raised IndexError
    when the benchmark is mounted at /app in Docker (`make test-bench`). Compose mounts
    ../docs at /docs, which the walk reaches through the filesystem root.
    """
    origin = (start or Path(__file__)).resolve()
    for directory in origin.parents:
        candidate = directory / DSL_SCHEMA_RELATIVE
        if candidate.is_file():
            return candidate
    pytest.fail(f"{DSL_SCHEMA_RELATIVE} not found above {origin}; mount ../docs at /docs")


def test_find_dsl_schema_handles_shallow_docker_layout(tmp_path: Path) -> None:
    """A test file at <root>/app/tests/x.py (like /app/tests in Docker) must not IndexError."""
    test_file = tmp_path / "app" / "tests" / "test_x.py"
    test_file.parent.mkdir(parents=True)
    test_file.write_text("", encoding="utf-8")
    schema = tmp_path / DSL_SCHEMA_RELATIVE
    schema.parent.mkdir(parents=True)
    schema.write_text("{}", encoding="utf-8")

    assert find_dsl_schema(test_file) == schema


@pytest.fixture
def clean_toggles(monkeypatch: pytest.MonkeyPatch) -> Generator[None, None, None]:
    """Ensure all bug toggles are disabled before and after each test."""
    for i in range(11, 23):
        setattr(settings, f"bug_{i:03d}", False)
        monkeypatch.delenv(f"BUG_{i:03d}", raising=False)
    store.seed_defaults()
    yield
    for i in range(11, 23):
        setattr(settings, f"bug_{i:03d}", False)
        monkeypatch.delenv(f"BUG_{i:03d}", raising=False)
    store.seed_defaults()


def test_ground_truth_file_validity() -> None:
    """Verify ground-truth.yaml exists and contains all 12 bug definitions."""
    gt_path = Path(__file__).resolve().parents[1] / "ground-truth.yaml"
    assert gt_path.exists(), f"ground-truth.yaml missing at {gt_path}"

    with gt_path.open("r", encoding="utf-8") as f:
        data: dict[str, Any] = yaml.safe_load(f)

    assert data.get("benchmark") == "ecommerce-order"
    assert data.get("total_bugs") == 12

    bugs = data.get("bugs", [])
    assert len(bugs) == 12

    expected_codes = [f"BUG-{i:03d}" for i in range(11, 23)]
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

    # Category alignment with docs/architecture/experiment-design.md
    assert bugs[0]["category"] == "BOUNDARY_ERROR"  # BUG-011
    assert bugs[1]["category"] == "BUSINESS_RULE_VIOLATION"  # BUG-012
    assert bugs[2]["category"] == "SEQUENCE_ERROR"  # BUG-013
    assert bugs[3]["category"] == "STATUS_CODE_WRONG"  # BUG-014
    assert bugs[4]["category"] == "SEQUENCE_ERROR"  # BUG-015
    assert bugs[5]["category"] == "BUSINESS_RULE_VIOLATION"  # BUG-016
    assert bugs[6]["category"] == "AUTH_ERROR"  # BUG-017
    assert bugs[7]["category"] == "AUTH_ERROR"  # BUG-018
    assert bugs[8]["category"] == "SCHEMA_MISMATCH"  # BUG-019
    assert bugs[9]["category"] == "BUSINESS_RULE_VIOLATION"  # BUG-020
    assert bugs[10]["category"] == "STATUS_CODE_WRONG"  # BUG-021
    assert bugs[11]["category"] == "SCHEMA_MISMATCH"  # BUG-022


def test_ground_truth_oracle_dsl_schema_and_openapi_methods() -> None:
    """Validate all oracle assertions against test-dsl JSON Schema and check OpenAPI methods."""
    base_dir = Path(__file__).resolve().parents[1]
    gt_path = base_dir / "ground-truth.yaml"
    openapi_path = base_dir / "openapi.yaml"
    dsl_schema_path = find_dsl_schema()

    with gt_path.open("r", encoding="utf-8") as f:
        gt_data: dict[str, Any] = yaml.safe_load(f)

    with openapi_path.open("r", encoding="utf-8") as f:
        spec: dict[str, Any] = yaml.safe_load(f)

    with dsl_schema_path.open("r", encoding="utf-8") as f:
        dsl_schema: dict[str, Any] = json.load(f)

    # Build standalone assertion validator from #/$defs/assertion in canonical DSL contract
    assertion_schema = {
        "$schema": dsl_schema.get("$schema", "https://json-schema.org/draft/2020-12/schema"),
        "$defs": dsl_schema.get("$defs", {}),
        "$ref": "#/$defs/assertion",
    }
    dsl_assertion_validator = jsonschema.Draft202012Validator(assertion_schema)

    spec_paths = spec.get("paths", {})

    for bug in gt_data.get("bugs", []):
        code = bug["code"]
        method = bug["method"].lower()
        path = bug["path"]

        # 1. Path must exist in OpenAPI spec
        assert path in spec_paths, f"Bug {code}: Path '{path}' not found in openapi.yaml"

        # 2. HTTP Method must exist under that path in OpenAPI spec
        assert method in spec_paths[path], (
            f"Bug {code}: Method '{method.upper()}' not found under '{path}' in openapi.yaml"
        )

        # 3. Oracle assertion must validate strictly against canonical test-dsl schema
        oracle = bug.get("oracle_assertion", {})
        try:
            dsl_assertion_validator.validate(oracle)
        except jsonschema.ValidationError as err:
            pytest.fail(
                f"Bug {code}: Oracle assertion violated canonical DSL schema: {err.message}"
            )


def _evaluate_oracle(oracle: dict[str, Any], resp: Any, openapi_spec: dict[str, Any]) -> bool:
    """Evaluate whether an HTTP response passes a given oracle assertion."""
    o_type = oracle.get("type")
    if o_type == "status":
        return resp.status_code == oracle.get("equals")

    if o_type == "json_path":
        path = oracle.get("path")
        op = oracle.get("op")
        val = oracle.get("value")
        try:
            body = resp.json()
        except Exception:
            return False
        if path == "$.items" and op == "type" and val == "array":
            return isinstance(body.get("items"), list)
        return False

    if o_type == "json_schema":
        ref = oracle.get("schema_ref")
        try:
            body = resp.json()
        except Exception:
            return False
        if ref and ref.startswith("#/components/schemas/"):
            schema_name = ref.split("/")[-1]
            schemas = openapi_spec.get("components", {}).get("schemas", {})
            target_schema = schemas.get(schema_name)
            if target_schema:
                schema_str = json.dumps(schemas).replace("#/components/schemas/", "#/$defs/")
                defs = json.loads(schema_str)
                schema_doc = {
                    "$schema": "https://json-schema.org/draft/2020-12/schema",
                    "$defs": defs,
                    **defs[schema_name],
                }
                try:
                    jsonschema.Draft202012Validator(schema_doc).validate(body)
                    return True
                except jsonschema.ValidationError:
                    return False
        return False

    return False


def _run_witness_request(client: TestClient, bug_code: str) -> Any:
    """Execute the canonical witness HTTP request for a given bug."""
    alice_headers = {"Authorization": "Bearer customer-alice-token"}
    bob_headers = {"Authorization": "Bearer customer-bob-token"}

    if bug_code == "BUG-011":
        return client.post(
            "/cart/items", json={"product_id": 2, "quantity": 0}, headers=alice_headers
        )
    if bug_code == "BUG-012":
        return client.post(
            "/cart/items", json={"product_id": 1, "quantity": 10}, headers=alice_headers
        )
    if bug_code == "BUG-013":
        return client.post("/checkout", json={"cart_id": 102}, headers=bob_headers)
    if bug_code == "BUG-014":
        client.post("/cart/items", json={"product_id": 2, "quantity": 1}, headers=alice_headers)
        return client.post("/checkout", json={"cart_id": 101}, headers=alice_headers)
    if bug_code == "BUG-015":
        client.post("/orders/901/pay", headers=bob_headers)
        return client.post("/orders/901/pay", headers=bob_headers)
    if bug_code == "BUG-016":
        return client.post("/orders/902/cancel", headers=alice_headers)
    if bug_code == "BUG-017":
        return client.get("/admin/orders", headers=alice_headers)
    if bug_code == "BUG-018":
        return client.get("/orders/901", headers=alice_headers)
    if bug_code == "BUG-019":
        return client.post(
            "/orders",
            json={"items": [{"product_id": 2, "quantity": 1}]},
            headers=alice_headers,
        )
    if bug_code == "BUG-020":
        client.post("/cart/items", json={"product_id": 2, "quantity": 1}, headers=alice_headers)
        return client.post(
            "/coupons/apply",
            json={"cart_id": 101, "code": "SAVE10"},
            headers=alice_headers,
        )
    if bug_code == "BUG-021":
        return client.delete("/cart/items/9999", headers=alice_headers)
    if bug_code == "BUG-022":
        return client.get("/cart", headers=bob_headers)

    raise ValueError(f"Unknown bug code: {bug_code}")


@pytest.mark.parametrize("bug_code", [f"BUG-{i:03d}" for i in range(11, 23)])
def test_oracle_witness_clean_pass_and_mutant_fail(
    client: TestClient, clean_toggles: None, bug_code: str
) -> None:
    """Verify each oracle PASSES on clean baseline and FAILS when bug is activated (24 cases)."""
    base_dir = Path(__file__).resolve().parents[1]
    with (base_dir / "ground-truth.yaml").open("r", encoding="utf-8") as f:
        gt_data: dict[str, Any] = yaml.safe_load(f)
    with (base_dir / "openapi.yaml").open("r", encoding="utf-8") as f:
        spec: dict[str, Any] = yaml.safe_load(f)

    bug_meta = next(b for b in gt_data["bugs"] if b["code"] == bug_code)
    oracle = bug_meta["oracle_assertion"]
    toggle_attr = bug_meta["toggle_env"].lower()

    # 1. Clean run: Oracle MUST PASS
    store.seed_defaults()
    setattr(settings, toggle_attr, False)
    clean_resp = _run_witness_request(client, bug_code)
    assert _evaluate_oracle(oracle, clean_resp, spec) is True, (
        f"Bug {bug_code}: Oracle expected to PASS on clean baseline but failed."
    )

    # 2. Mutant run: Oracle MUST FAIL
    store.seed_defaults()
    setattr(settings, toggle_attr, True)
    mutant_resp = _run_witness_request(client, bug_code)
    assert _evaluate_oracle(oracle, mutant_resp, spec) is False, (
        f"Bug {bug_code}: Oracle expected to FAIL on seeded mutant but passed."
    )


def test_toggle_switching_in_same_process(client: TestClient, clean_toggles: None) -> None:
    """Verify switching a toggle from '1' to '0' dynamically turns off the bug in-process."""
    alice_headers = {"Authorization": "Bearer customer-alice-token"}
    payload = {"product_id": 2, "quantity": 0}

    # 1. Turn on via os.environ:
    os.environ["BUG_011"] = "1"
    assert is_bug_active("BUG_011") is True
    resp_on = client.post("/cart/items", json=payload, headers=alice_headers)
    assert resp_on.status_code == 201

    # 2. Switch to '0' in the same process:
    os.environ["BUG_011"] = "0"
    assert is_bug_active("BUG_011") is False
    resp_off = client.post("/cart/items", json=payload, headers=alice_headers)
    assert resp_off.status_code == 422

    # 3. Switch back to '1':
    os.environ["BUG_011"] = "1"
    assert is_bug_active("BUG_011") is True
    resp_on2 = client.post("/cart/items", json=payload, headers=alice_headers)
    assert resp_on2.status_code == 201

    # 4. Remove from os.environ:
    del os.environ["BUG_011"]
    assert is_bug_active("BUG_011") is False
    resp_off2 = client.post("/cart/items", json=payload, headers=alice_headers)
    assert resp_off2.status_code == 422


def test_environment_debug_variable_immunity(monkeypatch: pytest.MonkeyPatch) -> None:
    """Ensure non-boolean local DEBUG env (e.g. DEBUG=release) does not crash config."""
    monkeypatch.setenv("DEBUG", "release")
    test_settings = Settings()
    assert test_settings.app_name == "E-Commerce Order Benchmark API"
    snapshot = get_settings()
    assert snapshot.bug_011 is False


def test_bug_011_boundary_quantity_zero(client: TestClient, clean_toggles: None) -> None:
    """BUG-011: Allows adding item with quantity = 0 bypassing boundary."""
    alice_headers = {"Authorization": "Bearer customer-alice-token"}
    payload = {"product_id": 2, "quantity": 0}

    # 1. Clean behavior: 422 Unprocessable Entity
    settings.bug_011 = False
    clean_resp = client.post("/cart/items", json=payload, headers=alice_headers)
    assert clean_resp.status_code == 422

    # 2. Bug behavior: 201 Created accepting quantity = 0
    settings.bug_011 = True
    bug_resp = client.post("/cart/items", json=payload, headers=alice_headers)
    assert bug_resp.status_code == 201
    assert bug_resp.json()["quantity"] == 0


def test_bug_012_stock_exceeded(client: TestClient, clean_toggles: None) -> None:
    """BUG-012: Allows adding quantity exceeding product stock."""
    alice_headers = {"Authorization": "Bearer customer-alice-token"}
    payload = {"product_id": 1, "quantity": 10}

    # 1. Clean behavior: 409 Conflict (INSUFFICIENT_STOCK)
    settings.bug_012 = False
    clean_resp = client.post("/cart/items", json=payload, headers=alice_headers)
    assert clean_resp.status_code == 409
    assert clean_resp.json()["detail"]["code"] == "INSUFFICIENT_STOCK"

    # 2. Bug behavior: 201 Created allowing quantity > stock
    settings.bug_012 = True
    bug_resp = client.post("/cart/items", json=payload, headers=alice_headers)
    assert bug_resp.status_code == 201
    assert bug_resp.json()["quantity"] == 10


def test_bug_013_checkout_empty_cart(client: TestClient, clean_toggles: None) -> None:
    """BUG-013: Allows checking out empty cart."""
    bob_headers = {"Authorization": "Bearer customer-bob-token"}
    payload = {"cart_id": 102}

    # 1. Clean behavior: 409 Conflict (EMPTY_CART)
    settings.bug_013 = False
    clean_resp = client.post("/checkout", json=payload, headers=bob_headers)
    assert clean_resp.status_code == 409
    assert clean_resp.json()["detail"]["code"] == "EMPTY_CART"

    # 2. Bug behavior: 201 Created allowing empty order
    settings.bug_013 = True
    bug_resp = client.post("/checkout", json=payload, headers=bob_headers)
    assert bug_resp.status_code == 201
    assert bug_resp.json()["items"] == []


def test_bug_014_checkout_wrong_status_code(client: TestClient, clean_toggles: None) -> None:
    """BUG-014: Checkout returns 200 instead of 201 and omits Location header."""
    alice_headers = {"Authorization": "Bearer customer-alice-token"}
    payload = {"cart_id": 101}

    # Add item to cart first so checkout has items
    client.post("/cart/items", json={"product_id": 2, "quantity": 1}, headers=alice_headers)

    # 1. Clean behavior: 201 Created with Location header
    settings.bug_014 = False
    clean_resp = client.post("/checkout", json=payload, headers=alice_headers)
    assert clean_resp.status_code == 201
    assert "Location" in clean_resp.headers

    # Reset Alice's cart with an item for bug run (checkout clears the cart)
    client.post("/cart/items", json={"product_id": 2, "quantity": 1}, headers=alice_headers)

    # 2. Bug behavior: 200 OK and missing Location header
    settings.bug_014 = True
    bug_resp = client.post("/checkout", json=payload, headers=alice_headers)
    assert bug_resp.status_code == 200
    assert "Location" not in bug_resp.headers


def test_bug_015_double_payment(client: TestClient, clean_toggles: None) -> None:
    """BUG-015: Allows double payment on already paid order."""
    bob_headers = {"Authorization": "Bearer customer-bob-token"}

    # Pay once: succeeds
    first_pay = client.post("/orders/901/pay", headers=bob_headers)
    assert first_pay.status_code == 200
    assert first_pay.json()["status"] == "PAID"

    # 1. Clean behavior on second payment: 409 Conflict (ORDER_NOT_PAYABLE)
    settings.bug_015 = False
    clean_second = client.post("/orders/901/pay", headers=bob_headers)
    assert clean_second.status_code == 409
    assert clean_second.json()["detail"]["code"] == "ORDER_NOT_PAYABLE"

    # 2. Bug behavior on second payment: 200 OK allowing repeated payment
    settings.bug_015 = True
    bug_second = client.post("/orders/901/pay", headers=bob_headers)
    assert bug_second.status_code == 200
    assert bug_second.json()["status"] == "PAID"


def test_bug_015_preserves_shipped_and_cancelled_guards(
    client: TestClient, clean_toggles: None
) -> None:
    """BUG-015: Paying SHIPPED or CANCELLED order still raises 409 ORDER_NOT_PAYABLE."""
    alice_headers = {"Authorization": "Bearer customer-alice-token"}
    bob_headers = {"Authorization": "Bearer customer-bob-token"}

    settings.bug_015 = True

    # 1. Order 902 belongs to Alice and is SHIPPED
    shipped_resp = client.post("/orders/902/pay", headers=alice_headers)
    assert shipped_resp.status_code == 409
    assert shipped_resp.json()["detail"]["code"] == "ORDER_NOT_PAYABLE"

    # 2. Order 901 belongs to Bob, cancel it first
    cancel_resp = client.post("/orders/901/cancel", headers=bob_headers)
    assert cancel_resp.status_code == 200
    assert cancel_resp.json()["status"] == "CANCELLED"

    # Trying to pay cancelled order must still be rejected with 409
    cancelled_pay = client.post("/orders/901/pay", headers=bob_headers)
    assert cancelled_pay.status_code == 409
    assert cancelled_pay.json()["detail"]["code"] == "ORDER_NOT_PAYABLE"


def test_bug_016_cancel_shipped_order(client: TestClient, clean_toggles: None) -> None:
    """BUG-016: Allows cancelling order with SHIPPED status."""
    alice_headers = {"Authorization": "Bearer customer-alice-token"}

    # 1. Clean behavior: 409 Conflict (ORDER_NOT_CANCELLABLE)
    settings.bug_016 = False
    clean_resp = client.post("/orders/902/cancel", headers=alice_headers)
    assert clean_resp.status_code == 409
    assert clean_resp.json()["detail"]["code"] == "ORDER_NOT_CANCELLABLE"

    # 2. Bug behavior: 200 OK cancelling shipped order
    settings.bug_016 = True
    bug_resp = client.post("/orders/902/cancel", headers=alice_headers)
    assert bug_resp.status_code == 200
    assert bug_resp.json()["status"] == "CANCELLED"


def test_bug_017_customer_access_admin_orders(client: TestClient, clean_toggles: None) -> None:
    """BUG-017: Customer role accesses admin-only endpoint."""
    alice_headers = {"Authorization": "Bearer customer-alice-token"}

    # 1. Clean behavior: 403 Forbidden
    settings.bug_017 = False
    clean_resp = client.get("/admin/orders", headers=alice_headers)
    assert clean_resp.status_code == 403

    # 2. Bug behavior: 200 OK returning orders
    settings.bug_017 = True
    bug_resp = client.get("/admin/orders", headers=alice_headers)
    assert bug_resp.status_code == 200
    assert isinstance(bug_resp.json(), list)


def test_bug_018_idor_order_access(client: TestClient, clean_toggles: None) -> None:
    """BUG-018: Customer Alice views Bob's order 901 (IDOR)."""
    alice_headers = {"Authorization": "Bearer customer-alice-token"}

    # 1. Clean behavior: 403 Forbidden
    settings.bug_018 = False
    clean_resp = client.get("/orders/901", headers=alice_headers)
    assert clean_resp.status_code == 403

    # 2. Bug behavior: 200 OK returning Bob's order
    settings.bug_018 = True
    bug_resp = client.get("/orders/901", headers=alice_headers)
    assert bug_resp.status_code == 200
    assert bug_resp.json()["id"] == 901


def test_bug_019_order_total_amount_schema(client: TestClient, clean_toggles: None) -> None:
    """BUG-019: Create order returns total_amount as string instead of number."""
    alice_headers = {"Authorization": "Bearer customer-alice-token"}
    payload = {"items": [{"product_id": 2, "quantity": 1}]}

    # 1. Clean behavior: total_amount is float/number
    settings.bug_019 = False
    clean_resp = client.post("/orders", json=payload, headers=alice_headers)
    assert clean_resp.status_code == 201
    assert isinstance(clean_resp.json()["total_amount"], (float, int))

    # 2. Bug behavior: total_amount is string
    settings.bug_019 = True
    bug_resp = client.post("/orders", json=payload, headers=alice_headers)
    assert bug_resp.status_code == 201
    assert isinstance(bug_resp.json()["total_amount"], str)


def test_bug_020_coupon_minimum_not_met(client: TestClient, clean_toggles: None) -> None:
    """BUG-020: Applies coupon when cart subtotal < 100."""
    alice_headers = {"Authorization": "Bearer customer-alice-token"}
    client.post("/cart/items", json={"product_id": 2, "quantity": 1}, headers=alice_headers)
    payload = {"cart_id": 101, "code": "SAVE10"}

    # 1. Clean behavior: 409 Conflict (COUPON_MINIMUM_NOT_MET)
    settings.bug_020 = False
    clean_resp = client.post("/coupons/apply", json=payload, headers=alice_headers)
    assert clean_resp.status_code == 409
    assert clean_resp.json()["detail"]["code"] == "COUPON_MINIMUM_NOT_MET"

    # 2. Bug behavior: 200 OK applying discount
    settings.bug_020 = True
    bug_resp = client.post("/coupons/apply", json=payload, headers=alice_headers)
    assert bug_resp.status_code == 200
    assert bug_resp.json()["discount"] == 2.5


def test_bug_021_remove_cart_item_error_code(client: TestClient, clean_toggles: None) -> None:
    """BUG-021: Deleting non-existent cart item returns 500 instead of 404."""
    alice_headers = {"Authorization": "Bearer customer-alice-token"}

    # 1. Clean behavior: 404 Not Found
    settings.bug_021 = False
    clean_resp = client.delete("/cart/items/9999", headers=alice_headers)
    assert clean_resp.status_code == 404
    assert clean_resp.json()["detail"]["code"] == "CART_ITEM_NOT_FOUND"

    # 2. Bug behavior: 500 Internal Server Error
    settings.bug_021 = True
    bug_resp = client.delete("/cart/items/9999", headers=alice_headers)
    assert bug_resp.status_code == 500


def test_bug_022_empty_cart_items_schema(client: TestClient, clean_toggles: None) -> None:
    """BUG-022: Empty cart response returns items: null instead of []."""
    alice_headers = {"Authorization": "Bearer customer-alice-token"}

    # 1. Clean behavior: items is empty list []
    settings.bug_022 = False
    clean_resp = client.get("/cart", headers=alice_headers)
    assert clean_resp.status_code == 200
    assert clean_resp.json()["items"] == []

    # 2. Bug behavior: items is null
    settings.bug_022 = True
    bug_resp = client.get("/cart", headers=alice_headers)
    assert bug_resp.status_code == 200
    assert bug_resp.json()["items"] is None
