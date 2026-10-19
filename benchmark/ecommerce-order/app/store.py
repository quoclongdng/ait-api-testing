"""Deterministic in-memory state for the e-commerce order benchmark."""

from collections.abc import Iterable
from dataclasses import dataclass, field
from threading import RLock

from fastapi import status

from app.auth import Identity, Role, api_error
from app.config import is_bug_active
from app.schemas import (
    CartItemCreate,
    CartItemResponse,
    CartResponse,
    CouponResponse,
    OrderCreate,
    OrderItemResponse,
    OrderResponse,
    OrderStatus,
    ProductResponse,
)


@dataclass
class CartState:
    id: int
    customer_id: int
    items: dict[int, tuple[int, int]] = field(default_factory=dict)


class OrderStore:
    def __init__(self) -> None:
        self._state_lock = RLock()
        self.seed_defaults()

    def seed_defaults(self) -> None:
        with self._state_lock:
            self._seed_defaults()

    def _seed_defaults(self) -> None:
        self.products = {
            1: ProductResponse(
                id=1, sku="LAPTOP-01", name="Developer Laptop", price=1200.0, stock=5
            ),
            2: ProductResponse(id=2, sku="MOUSE-01", name="Wireless Mouse", price=25.0, stock=100),
            3: ProductResponse(
                id=3, sku="KEYBOARD-01", name="Mechanical Keyboard", price=75.0, stock=50
            ),
        }
        self.carts = {
            1: CartState(id=101, customer_id=1),
            2: CartState(id=102, customer_id=2),
        }
        self.orders = {
            901: OrderResponse(
                id=901,
                customer_id=2,
                items=[self._order_item(2, 1)],
                subtotal=25.0,
                discount=0.0,
                total_amount=25.0,
                status=OrderStatus.PENDING,
            ),
            902: OrderResponse(
                id=902,
                customer_id=1,
                items=[self._order_item(3, 1)],
                subtotal=75.0,
                discount=0.0,
                total_amount=75.0,
                status=OrderStatus.SHIPPED,
            ),
        }
        self._next_cart_item_id = 1001
        self._next_order_id = 10001

    def list_products(self) -> list[ProductResponse]:
        with self._state_lock:
            return [product.model_copy(deep=True) for product in self.products.values()]

    def get_product(self, product_id: int) -> ProductResponse:
        with self._state_lock:
            product = self.products.get(product_id)
            if product is None:
                raise api_error(status.HTTP_404_NOT_FOUND, "PRODUCT_NOT_FOUND", "Product not found")
            return product.model_copy(deep=True)

    def get_cart(self, identity: Identity) -> CartResponse:
        with self._state_lock:
            cart = self._customer_cart(identity)
            items = [
                self._cart_item(item_id, product_id, quantity)
                for item_id, (product_id, quantity) in cart.items.items()
            ]
            return CartResponse(
                id=cart.id,
                customer_id=cart.customer_id,
                items=items,
                total_amount=round(sum(item.line_total for item in items), 2),
            )

    def add_cart_item(self, identity: Identity, data: CartItemCreate) -> CartItemResponse:
        with self._state_lock:
            cart = self._customer_cart(identity)
            product = self.get_product(data.product_id)
            existing = next(
                (
                    (item_id, quantity)
                    for item_id, (product_id, quantity) in cart.items.items()
                    if product_id == data.product_id
                ),
                None,
            )
            new_quantity = data.quantity + (existing[1] if existing else 0)
            if new_quantity > product.stock and not is_bug_active("bug_012"):
                raise api_error(
                    status.HTTP_409_CONFLICT,
                    "INSUFFICIENT_STOCK",
                    "Requested quantity exceeds stock",
                )

            if existing:
                item_id = existing[0]
            else:
                item_id = self._next_cart_item_id
                self._next_cart_item_id += 1
            cart.items[item_id] = (data.product_id, new_quantity)
            return self._cart_item(item_id, data.product_id, new_quantity)

    def remove_cart_item(self, identity: Identity, item_id: int) -> None:
        with self._state_lock:
            cart = self._customer_cart(identity)
            if item_id not in cart.items:
                if is_bug_active("bug_021"):
                    raise api_error(
                        status.HTTP_500_INTERNAL_SERVER_ERROR,
                        "INTERNAL_SERVER_ERROR",
                        "Internal server error",
                    )
                raise api_error(
                    status.HTTP_404_NOT_FOUND, "CART_ITEM_NOT_FOUND", "Cart item not found"
                )
            del cart.items[item_id]

    def checkout(self, identity: Identity, cart_id: int) -> OrderResponse:
        with self._state_lock:
            cart = self._customer_cart(identity)
            if cart.id != cart_id:
                raise api_error(
                    status.HTTP_403_FORBIDDEN, "FORBIDDEN", "Cart belongs to another customer"
                )
            if not cart.items and not is_bug_active("bug_013"):
                raise api_error(
                    status.HTTP_409_CONFLICT, "EMPTY_CART", "Cannot checkout an empty cart"
                )

            items = self._build_order_items(cart.items.values()) if cart.items else []
            order = self._create_order(identity.user_id, items)
            cart.items.clear()
            return order

    def create_order(self, identity: Identity, data: OrderCreate) -> OrderResponse:
        with self._state_lock:
            self._require_customer(identity)
            items = self._build_order_items((item.product_id, item.quantity) for item in data.items)
            return self._create_order(identity.user_id, items)

    def get_order(self, identity: Identity, order_id: int) -> OrderResponse:
        with self._state_lock:
            order = self.orders.get(order_id)
            if order is None:
                raise api_error(status.HTTP_404_NOT_FOUND, "ORDER_NOT_FOUND", "Order not found")
            if (
                identity.role is not Role.ADMIN
                and order.customer_id != identity.user_id
                and not is_bug_active("bug_018")
            ):
                raise api_error(
                    status.HTTP_403_FORBIDDEN, "FORBIDDEN", "Order belongs to another customer"
                )
            return order.model_copy(deep=True)

    def pay_order(self, identity: Identity, order_id: int) -> OrderResponse:
        with self._state_lock:
            order = self._owned_order(identity, order_id)
            # BUG-015 specifically allows double payment on an already PAID order.
            # Other non-pending states (SHIPPED, CANCELLED) remain unpayable (HTTP 409).
            if order.status is not OrderStatus.PENDING and not (
                order.status is OrderStatus.PAID and is_bug_active("bug_015")
            ):
                raise api_error(
                    status.HTTP_409_CONFLICT,
                    "ORDER_NOT_PAYABLE",
                    "Only pending orders can be paid",
                )
            stored = self.orders[order_id]
            if stored.status is OrderStatus.PENDING:
                self._deduct_stock(stored.items)
            stored.status = OrderStatus.PAID
            return stored.model_copy(deep=True)

    def cancel_order(self, identity: Identity, order_id: int) -> OrderResponse:
        with self._state_lock:
            order = self._owned_order(identity, order_id)
            cannot_cancel = {OrderStatus.SHIPPED, OrderStatus.CANCELLED}
            if is_bug_active("bug_016"):
                cannot_cancel = {OrderStatus.CANCELLED}
            if order.status in cannot_cancel:
                raise api_error(
                    status.HTTP_409_CONFLICT,
                    "ORDER_NOT_CANCELLABLE",
                    "Order cannot be cancelled in its current state",
                )
            stored = self.orders[order_id]
            if stored.status is OrderStatus.PAID:
                self._restore_stock(stored.items)
            stored.status = OrderStatus.CANCELLED
            return stored.model_copy(deep=True)

    def list_orders(self) -> list[OrderResponse]:
        with self._state_lock:
            return [order.model_copy(deep=True) for order in self.orders.values()]

    def apply_coupon(self, identity: Identity, cart_id: int, code: str) -> CouponResponse:
        with self._state_lock:
            cart = self.get_cart(identity)
            if cart.id != cart_id:
                raise api_error(
                    status.HTTP_403_FORBIDDEN, "FORBIDDEN", "Cart belongs to another customer"
                )
            if code != "SAVE10":
                raise api_error(status.HTTP_404_NOT_FOUND, "COUPON_NOT_FOUND", "Coupon not found")
            if cart.total_amount < 100.0 and not is_bug_active("bug_020"):
                raise api_error(
                    status.HTTP_409_CONFLICT,
                    "COUPON_MINIMUM_NOT_MET",
                    "Cart subtotal must be at least 100",
                )
            discount = round(cart.total_amount * 0.10, 2)
            return CouponResponse(
                code=code,
                subtotal=cart.total_amount,
                discount=discount,
                total_amount=round(cart.total_amount - discount, 2),
            )

    def _customer_cart(self, identity: Identity) -> CartState:
        self._require_customer(identity)
        cart = self.carts.get(identity.user_id)
        if cart is None:
            raise api_error(status.HTTP_404_NOT_FOUND, "CART_NOT_FOUND", "Cart not found")
        return cart

    def _owned_order(self, identity: Identity, order_id: int) -> OrderResponse:
        self._require_customer(identity)
        order = self.orders.get(order_id)
        if order is None:
            raise api_error(status.HTTP_404_NOT_FOUND, "ORDER_NOT_FOUND", "Order not found")
        if order.customer_id != identity.user_id:
            raise api_error(
                status.HTTP_403_FORBIDDEN, "FORBIDDEN", "Order belongs to another customer"
            )
        return order.model_copy(deep=True)

    @staticmethod
    def _require_customer(identity: Identity) -> None:
        if identity.role is not Role.CUSTOMER:
            raise api_error(status.HTTP_403_FORBIDDEN, "FORBIDDEN", "Customer role required")

    def _cart_item(self, item_id: int, product_id: int, quantity: int) -> CartItemResponse:
        product = self.get_product(product_id)
        return CartItemResponse(
            id=item_id,
            product_id=product.id,
            product_name=product.name,
            quantity=quantity,
            unit_price=product.price,
            line_total=round(product.price * quantity, 2),
        )

    def _order_item(self, product_id: int, quantity: int) -> OrderItemResponse:
        product = self.get_product(product_id)
        if quantity > product.stock:
            raise api_error(
                status.HTTP_409_CONFLICT,
                "INSUFFICIENT_STOCK",
                "Requested quantity exceeds stock",
            )
        return OrderItemResponse(
            product_id=product.id,
            product_name=product.name,
            quantity=quantity,
            unit_price=product.price,
            line_total=round(product.price * quantity, 2),
        )

    def _build_order_items(self, entries: Iterable[tuple[int, int]]) -> list[OrderItemResponse]:
        quantities = self._quantities_by_product(entries)
        return [
            self._order_item(product_id, quantity) for product_id, quantity in quantities.items()
        ]

    @staticmethod
    def _quantities_by_product(entries: Iterable[tuple[int, int]]) -> dict[int, int]:
        quantities: dict[int, int] = {}
        for product_id, quantity in entries:
            quantities[product_id] = quantities.get(product_id, 0) + quantity
        return quantities

    def _deduct_stock(self, items: list[OrderItemResponse]) -> None:
        quantities = self._quantities_by_product((item.product_id, item.quantity) for item in items)

        # Validate every line before mutating stock so payment is atomic.
        for product_id, quantity in quantities.items():
            product = self.get_product(product_id)
            if quantity > product.stock:
                raise api_error(
                    status.HTTP_409_CONFLICT,
                    "INSUFFICIENT_STOCK",
                    "Requested quantity exceeds stock",
                )

        for product_id, quantity in quantities.items():
            self.products[product_id].stock -= quantity

    def _restore_stock(self, items: list[OrderItemResponse]) -> None:
        quantities = self._quantities_by_product((item.product_id, item.quantity) for item in items)
        for product_id, quantity in quantities.items():
            self.products[product_id].stock += quantity

    def _create_order(self, customer_id: int, items: list[OrderItemResponse]) -> OrderResponse:
        with self._state_lock:
            subtotal = round(sum(item.line_total for item in items), 2)
            order = OrderResponse(
                id=self._next_order_id,
                customer_id=customer_id,
                items=items,
                subtotal=subtotal,
                discount=0.0,
                total_amount=subtotal,
                status=OrderStatus.PENDING,
            )
            self.orders[order.id] = order
            self._next_order_id += 1
            return order.model_copy(deep=True)


store = OrderStore()
