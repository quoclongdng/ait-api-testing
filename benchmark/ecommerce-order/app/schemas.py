"""Pydantic schemas for the e-commerce order benchmark."""

from enum import StrEnum

from pydantic import BaseModel, ConfigDict, Field, field_validator


class StrictModel(BaseModel):
    model_config = ConfigDict(extra="forbid", strict=True)


class OrderStatus(StrEnum):
    PENDING = "PENDING"
    PAID = "PAID"
    SHIPPED = "SHIPPED"
    CANCELLED = "CANCELLED"


class HealthResponse(StrictModel):
    status: str
    service: str


class ProductResponse(StrictModel):
    id: int
    sku: str
    name: str
    price: float
    stock: int


class CartItemCreate(StrictModel):
    product_id: int = Field(ge=1)
    quantity: int = Field(ge=0, le=100)

    @field_validator("quantity")
    @classmethod
    def validate_quantity(cls, v: int) -> int:
        from app.config import is_bug_active

        min_val = 0 if is_bug_active("bug_011") else 1
        if v < min_val:
            raise ValueError(f"Input should be greater than or equal to {min_val}")
        return v


class CartItemResponse(StrictModel):
    id: int
    product_id: int
    product_name: str
    quantity: int
    unit_price: float
    line_total: float


class CartResponse(StrictModel):
    id: int
    customer_id: int
    items: list[CartItemResponse]
    total_amount: float


class CheckoutRequest(StrictModel):
    cart_id: int = Field(ge=1)


class OrderItemCreate(StrictModel):
    product_id: int = Field(ge=1)
    quantity: int = Field(ge=1, le=100)


class OrderCreate(StrictModel):
    items: list[OrderItemCreate] = Field(min_length=1, max_length=20)


class OrderItemResponse(StrictModel):
    product_id: int
    product_name: str
    quantity: int
    unit_price: float
    line_total: float


class OrderResponse(StrictModel):
    id: int
    customer_id: int
    items: list[OrderItemResponse]
    subtotal: float
    discount: float
    total_amount: float
    status: OrderStatus


class CouponApplyRequest(StrictModel):
    cart_id: int = Field(ge=1)
    code: str = Field(min_length=1, max_length=32, pattern=r"^[A-Z0-9_-]+$")


class CouponResponse(StrictModel):
    code: str
    subtotal: float
    discount: float
    total_amount: float


class ErrorDetail(StrictModel):
    code: str
    message: str


class ErrorResponse(StrictModel):
    detail: ErrorDetail | list[dict[str, object]]
