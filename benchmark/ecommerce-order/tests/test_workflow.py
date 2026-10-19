from concurrent.futures import ThreadPoolExecutor
from contextlib import suppress
from threading import Barrier, BrokenBarrierError

from fastapi.testclient import TestClient
from pytest import MonkeyPatch

import app.store as store_module
from app.store import store
from tests.conftest import ADMIN_HEADERS, ALICE_HEADERS, BOB_HEADERS


def wait_for_competing_request(barrier: Barrier) -> None:
    with suppress(BrokenBarrierError):
        barrier.wait(timeout=1.0)


def test_cart_checkout_payment_sequence(client: TestClient) -> None:
    add_response = client.post(
        "/cart/items", json={"product_id": 1, "quantity": 1}, headers=ALICE_HEADERS
    )
    assert add_response.status_code == 201
    assert add_response.json()["line_total"] == 1200.0

    cart_response = client.get("/cart", headers=ALICE_HEADERS)
    assert cart_response.status_code == 200
    assert cart_response.json()["id"] == 101
    assert len(cart_response.json()["items"]) == 1

    checkout_response = client.post("/checkout", json={"cart_id": 101}, headers=ALICE_HEADERS)
    assert checkout_response.status_code == 201
    assert checkout_response.headers["Location"].startswith("/orders/")
    order = checkout_response.json()
    assert order["status"] == "PENDING"
    assert order["total_amount"] == 1200.0

    order_response = client.get(f"/orders/{order['id']}", headers=ALICE_HEADERS)
    assert order_response.status_code == 200
    assert order_response.json()["id"] == order["id"]

    payment_response = client.post(f"/orders/{order['id']}/pay", headers=ALICE_HEADERS)
    assert payment_response.status_code == 200
    assert payment_response.json()["status"] == "PAID"

    duplicate_payment = client.post(f"/orders/{order['id']}/pay", headers=ALICE_HEADERS)
    assert duplicate_payment.status_code == 409
    assert duplicate_payment.json()["detail"]["code"] == "ORDER_NOT_PAYABLE"


def test_direct_order_creation_and_cancellation(client: TestClient) -> None:
    create_response = client.post(
        "/orders",
        json={"items": [{"product_id": 2, "quantity": 2}]},
        headers=ALICE_HEADERS,
    )
    assert create_response.status_code == 201
    order = create_response.json()
    assert order["total_amount"] == 50.0
    assert create_response.headers["Location"] == f"/orders/{order['id']}"

    cancel_response = client.post(f"/orders/{order['id']}/cancel", headers=ALICE_HEADERS)
    assert cancel_response.status_code == 200
    assert cancel_response.json()["status"] == "CANCELLED"


def test_duplicate_product_lines_are_aggregated_before_stock_check(client: TestClient) -> None:
    over_stock = client.post(
        "/orders",
        json={
            "items": [
                {"product_id": 1, "quantity": 5},
                {"product_id": 1, "quantity": 5},
            ]
        },
        headers=ALICE_HEADERS,
    )
    assert over_stock.status_code == 409
    assert over_stock.json()["detail"]["code"] == "INSUFFICIENT_STOCK"

    valid = client.post(
        "/orders",
        json={
            "items": [
                {"product_id": 1, "quantity": 2},
                {"product_id": 1, "quantity": 3},
            ]
        },
        headers=ALICE_HEADERS,
    )
    assert valid.status_code == 201
    assert valid.json()["items"] == [
        {
            "product_id": 1,
            "product_name": "Developer Laptop",
            "quantity": 5,
            "unit_price": 1200.0,
            "line_total": 6000.0,
        }
    ]


def test_payment_atomically_checks_and_deducts_stock(client: TestClient) -> None:
    payload = {"items": [{"product_id": 1, "quantity": 5}]}
    first_order = client.post("/orders", json=payload, headers=ALICE_HEADERS).json()
    second_order = client.post("/orders", json=payload, headers=ALICE_HEADERS).json()

    first_payment = client.post(f"/orders/{first_order['id']}/pay", headers=ALICE_HEADERS)
    assert first_payment.status_code == 200
    assert client.get("/products/1").json()["stock"] == 0

    second_payment = client.post(f"/orders/{second_order['id']}/pay", headers=ALICE_HEADERS)
    assert second_payment.status_code == 409
    assert second_payment.json()["detail"]["code"] == "INSUFFICIENT_STOCK"

    unchanged_order = client.get(f"/orders/{second_order['id']}", headers=ALICE_HEADERS)
    assert unchanged_order.json()["status"] == "PENDING"
    assert client.get("/products/1").json()["stock"] == 0


def test_concurrent_payments_are_serialized(client: TestClient, monkeypatch: MonkeyPatch) -> None:
    payload = {"items": [{"product_id": 1, "quantity": 5}]}
    first_order = client.post("/orders", json=payload, headers=ALICE_HEADERS).json()
    second_order = client.post("/orders", json=payload, headers=ALICE_HEADERS).json()

    original_get_product = store.get_product
    stock_read_barrier = Barrier(2)

    def synchronized_stock_read(product_id: int):
        product = original_get_product(product_id)
        if product_id == 1:
            wait_for_competing_request(stock_read_barrier)
        return product

    monkeypatch.setattr(store, "get_product", synchronized_stock_read)

    def pay(order_id: int):
        return client.post(f"/orders/{order_id}/pay", headers=ALICE_HEADERS)

    with ThreadPoolExecutor(max_workers=2) as executor:
        responses = list(executor.map(pay, [first_order["id"], second_order["id"]]))

    assert sorted(response.status_code for response in responses) == [200, 409]
    assert client.get("/products/1").json()["stock"] == 0

    orders = [
        client.get(f"/orders/{order['id']}", headers=ALICE_HEADERS).json()
        for order in (first_order, second_order)
    ]
    assert sorted(order["status"] for order in orders) == ["PAID", "PENDING"]


def test_concurrent_same_order_pay_and_cancel_apply_once(
    client: TestClient, monkeypatch: MonkeyPatch
) -> None:
    created = client.post(
        "/orders",
        json={"items": [{"product_id": 2, "quantity": 2}]},
        headers=ALICE_HEADERS,
    ).json()
    order_id = created["id"]

    original_deduct_stock = store._deduct_stock
    deduction_barrier = Barrier(2)

    def synchronized_deduction(items):
        wait_for_competing_request(deduction_barrier)
        original_deduct_stock(items)

    monkeypatch.setattr(store, "_deduct_stock", synchronized_deduction)

    def pay():
        return client.post(f"/orders/{order_id}/pay", headers=ALICE_HEADERS)

    with ThreadPoolExecutor(max_workers=2) as executor:
        payment_responses = list(executor.map(lambda _index: pay(), range(2)))

    assert sorted(response.status_code for response in payment_responses) == [200, 409]
    assert client.get("/products/2").json()["stock"] == 98

    monkeypatch.setattr(store, "_deduct_stock", original_deduct_stock)
    original_restore_stock = store._restore_stock
    restoration_barrier = Barrier(2)

    def synchronized_restoration(items):
        wait_for_competing_request(restoration_barrier)
        original_restore_stock(items)

    monkeypatch.setattr(store, "_restore_stock", synchronized_restoration)

    def cancel():
        return client.post(f"/orders/{order_id}/cancel", headers=ALICE_HEADERS)

    with ThreadPoolExecutor(max_workers=2) as executor:
        cancellation_responses = list(executor.map(lambda _index: cancel(), range(2)))

    assert sorted(response.status_code for response in cancellation_responses) == [200, 409]
    assert client.get("/products/2").json()["stock"] == 100
    assert client.get(f"/orders/{order_id}", headers=ALICE_HEADERS).json()["status"] == "CANCELLED"


def test_concurrent_order_creation_allocates_unique_owned_orders(
    client: TestClient, monkeypatch: MonkeyPatch
) -> None:
    original_order_response = store_module.OrderResponse
    allocation_barrier = Barrier(2)

    def synchronized_order_response(**data):
        if data["id"] >= 10001:
            wait_for_competing_request(allocation_barrier)
        return original_order_response(**data)

    monkeypatch.setattr(store_module, "OrderResponse", synchronized_order_response)
    payload = {"items": [{"product_id": 2, "quantity": 1}]}

    def create_order(headers: dict[str, str]):
        return client.post("/orders", json=payload, headers=headers)

    with ThreadPoolExecutor(max_workers=2) as executor:
        alice_response, bob_response = executor.map(create_order, [ALICE_HEADERS, BOB_HEADERS])

    assert alice_response.status_code == 201
    assert bob_response.status_code == 201
    alice_order_id = alice_response.json()["id"]
    bob_order_id = bob_response.json()["id"]
    assert alice_order_id != bob_order_id

    assert client.get(f"/orders/{alice_order_id}", headers=ALICE_HEADERS).status_code == 200
    assert client.get(f"/orders/{bob_order_id}", headers=BOB_HEADERS).status_code == 200
    assert client.get(f"/orders/{bob_order_id}", headers=ALICE_HEADERS).status_code == 403
    assert client.get(f"/orders/{alice_order_id}", headers=BOB_HEADERS).status_code == 403


def test_concurrent_checkout_consumes_cart_once(
    client: TestClient, monkeypatch: MonkeyPatch
) -> None:
    added = client.post("/cart/items", json={"product_id": 2, "quantity": 2}, headers=ALICE_HEADERS)
    assert added.status_code == 201
    initial_order_count = len(client.get("/admin/orders", headers=ADMIN_HEADERS).json())

    original_build_order_items = store._build_order_items
    cart_read_barrier = Barrier(2)

    def synchronized_cart_read(entries):
        items = original_build_order_items(entries)
        wait_for_competing_request(cart_read_barrier)
        return items

    monkeypatch.setattr(store, "_build_order_items", synchronized_cart_read)

    def checkout():
        return client.post("/checkout", json={"cart_id": 101}, headers=ALICE_HEADERS)

    with ThreadPoolExecutor(max_workers=2) as executor:
        responses = list(executor.map(lambda _index: checkout(), range(2)))

    assert sorted(response.status_code for response in responses) == [201, 409]
    conflict = next(response for response in responses if response.status_code == 409)
    assert conflict.json()["detail"]["code"] == "EMPTY_CART"
    assert client.get("/cart", headers=ALICE_HEADERS).json()["items"] == []
    final_order_count = len(client.get("/admin/orders", headers=ADMIN_HEADERS).json())
    assert final_order_count == initial_order_count + 1


def test_concurrent_cart_additions_do_not_lose_quantity(
    client: TestClient, monkeypatch: MonkeyPatch
) -> None:
    initial = client.post(
        "/cart/items", json={"product_id": 2, "quantity": 1}, headers=ALICE_HEADERS
    )
    assert initial.status_code == 201
    cart_read_barrier = Barrier(2)

    class SnapshotItems(dict[int, tuple[int, int]]):
        def items(self):
            snapshot = list(super().items())
            wait_for_competing_request(cart_read_barrier)
            return snapshot

    monkeypatch.setattr(store.carts[1], "items", SnapshotItems(store.carts[1].items))

    def add_one():
        return client.post(
            "/cart/items", json={"product_id": 2, "quantity": 1}, headers=ALICE_HEADERS
        )

    with ThreadPoolExecutor(max_workers=2) as executor:
        responses = list(executor.map(lambda _index: add_one(), range(2)))

    assert [response.status_code for response in responses] == [201, 201]
    assert sorted(response.json()["quantity"] for response in responses) == [2, 3]
    cart = client.get("/cart", headers=ALICE_HEADERS).json()
    assert len(cart["items"]) == 1
    assert cart["items"][0]["quantity"] == 3


def test_cancelling_paid_order_restores_stock(client: TestClient) -> None:
    created = client.post(
        "/orders",
        json={"items": [{"product_id": 2, "quantity": 2}]},
        headers=ALICE_HEADERS,
    ).json()

    paid = client.post(f"/orders/{created['id']}/pay", headers=ALICE_HEADERS)
    assert paid.status_code == 200
    assert client.get("/products/2").json()["stock"] == 98

    cancelled = client.post(f"/orders/{created['id']}/cancel", headers=ALICE_HEADERS)
    assert cancelled.status_code == 200
    assert cancelled.json()["status"] == "CANCELLED"
    assert client.get("/products/2").json()["stock"] == 100


def test_cart_item_removal_and_empty_checkout(client: TestClient) -> None:
    add_response = client.post(
        "/cart/items", json={"product_id": 2, "quantity": 2}, headers=ALICE_HEADERS
    )
    item_id = add_response.json()["id"]

    remove_response = client.delete(f"/cart/items/{item_id}", headers=ALICE_HEADERS)
    assert remove_response.status_code == 204
    assert remove_response.content == b""

    missing_item = client.delete(f"/cart/items/{item_id}", headers=ALICE_HEADERS)
    assert missing_item.status_code == 404
    assert missing_item.json()["detail"]["code"] == "CART_ITEM_NOT_FOUND"

    empty_cart = client.get("/cart", headers=ALICE_HEADERS)
    assert empty_cart.status_code == 200
    assert empty_cart.json()["items"] == []

    checkout_response = client.post("/checkout", json={"cart_id": 101}, headers=ALICE_HEADERS)
    assert checkout_response.status_code == 409
    assert checkout_response.json()["detail"]["code"] == "EMPTY_CART"


def test_coupon_requires_minimum_subtotal(client: TestClient) -> None:
    client.post("/cart/items", json={"product_id": 2, "quantity": 1}, headers=ALICE_HEADERS)
    too_small = client.post(
        "/coupons/apply", json={"cart_id": 101, "code": "SAVE10"}, headers=ALICE_HEADERS
    )
    assert too_small.status_code == 409
    assert too_small.json()["detail"]["code"] == "COUPON_MINIMUM_NOT_MET"

    client.post("/cart/items", json={"product_id": 3, "quantity": 1}, headers=ALICE_HEADERS)
    applied = client.post(
        "/coupons/apply", json={"cart_id": 101, "code": "SAVE10"}, headers=ALICE_HEADERS
    )
    assert applied.status_code == 200
    assert applied.json() == {
        "code": "SAVE10",
        "subtotal": 100.0,
        "discount": 10.0,
        "total_amount": 90.0,
    }


def test_clean_baseline_enforces_stock_and_state_rules(client: TestClient) -> None:
    over_stock = client.post(
        "/cart/items", json={"product_id": 1, "quantity": 6}, headers=ALICE_HEADERS
    )
    assert over_stock.status_code == 409
    assert over_stock.json()["detail"]["code"] == "INSUFFICIENT_STOCK"

    shipped_cancel = client.post("/orders/902/cancel", headers=ALICE_HEADERS)
    assert shipped_cancel.status_code == 409
    assert shipped_cancel.json()["detail"]["code"] == "ORDER_NOT_CANCELLABLE"


def test_request_validation_is_strict(client: TestClient) -> None:
    zero_quantity = client.post(
        "/cart/items", json={"product_id": 1, "quantity": 0}, headers=ALICE_HEADERS
    )
    assert zero_quantity.status_code == 422

    extra_field = client.post(
        "/cart/items",
        json={"product_id": 1, "quantity": 1, "unexpected": True},
        headers=ALICE_HEADERS,
    )
    assert extra_field.status_code == 422

    for invalid_quantity in (True, "1"):
        invalid_cart_item = client.post(
            "/cart/items",
            json={"product_id": 1, "quantity": invalid_quantity},
            headers=ALICE_HEADERS,
        )
        assert invalid_cart_item.status_code == 422

    invalid_order_item = client.post(
        "/orders",
        json={"items": [{"product_id": "1", "quantity": 1}]},
        headers=ALICE_HEADERS,
    )
    assert invalid_order_item.status_code == 422

    invalid_checkout = client.post("/checkout", json={"cart_id": "101"}, headers=ALICE_HEADERS)
    assert invalid_checkout.status_code == 422
