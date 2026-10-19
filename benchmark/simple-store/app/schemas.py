"""Pydantic data models and schemas for simple-store benchmark API."""

from typing import Any

from pydantic import BaseModel, ConfigDict, Field, field_validator, model_validator


class HealthResponse(BaseModel):
    status: str = Field(..., examples=["ok"])
    service: str = Field(..., examples=["simple-store"])
    version: str = Field(..., examples=["1.0.0"])


class CategoryCreate(BaseModel):
    name: str = Field(..., min_length=1, max_length=100, description="Category name")
    slug: str = Field(
        ...,
        min_length=1,
        max_length=50,
        pattern=r"^[a-z0-9]+(?:-[a-z0-9]+)*$",
        description="URL-friendly unique slug (lowercase alphanumeric and hyphens)",
        examples=["home-appliances", "garden-tools"],
    )
    description: str | None = Field(
        default=None, max_length=255, description="Category description"
    )


class CategoryResponse(BaseModel):
    id: int = Field(..., description="Unique category identifier", examples=[1])
    name: str = Field(..., description="Category name", examples=["Electronics"])
    slug: str = Field(..., description="Category slug", examples=["electronics"])
    description: str | None = Field(default=None, description="Category description")

    model_config = ConfigDict(from_attributes=True)


class ProductCreate(BaseModel):
    name: str = Field(default="", max_length=100, description="Product name")
    sku: str = Field(
        ...,
        min_length=3,
        max_length=50,
        pattern=r"^[A-Z0-9_-]+$",
        description="Stock Keeping Unit (uppercase alphanumeric, hyphens, underscores)",
        examples=["PROD-001", "TECH_100"],
    )
    price: float = Field(
        ..., json_schema_extra={"minimum": 0.0}, description="Product price in USD"
    )
    category_id: int = Field(..., ge=1, description="Associated category ID")
    quantity: int = Field(
        default=0,
        ge=0,
        le=100000,
        description="Stock quantity, must be between 0 and 100,000",
    )
    description: str | None = Field(default=None, max_length=500, description="Product description")

    @model_validator(mode="before")
    @classmethod
    def validate_name_bug_001(cls, data: Any) -> Any:
        from app.config import is_bug_active

        if isinstance(data, dict):
            if not is_bug_active("bug_001"):
                if "name" not in data:
                    raise ValueError("name is required")
                if len(str(data.get("name", ""))) < 1:
                    raise ValueError("name must have at least 1 character")
            elif "name" not in data:
                data = dict(data)
                data["name"] = "Untitled Product"
        return data

    @field_validator("price")
    @classmethod
    def validate_price(cls, v: float) -> float:
        from app.config import is_bug_active

        if v < 0 and not is_bug_active("bug_002"):
            raise ValueError("Product price must be >= 0")
        return v


class ProductUpdate(BaseModel):
    name: str | None = Field(
        default=None, min_length=1, max_length=100, description="Updated product name"
    )
    price: float | None = Field(default=None, ge=0.0, description="Updated price, must be >= 0")
    category_id: int | None = Field(default=None, ge=1, description="Updated category ID")
    quantity: int | None = Field(
        default=None,
        ge=0,
        json_schema_extra={"maximum": 100000},
        description="Updated stock quantity (0 to 100,000)",
    )
    description: str | None = Field(default=None, max_length=500, description="Updated description")

    @field_validator("quantity")
    @classmethod
    def validate_quantity(cls, v: int | None) -> int | None:
        from app.config import is_bug_active

        if v is not None and v > 100000 and not is_bug_active("bug_006"):
            raise ValueError("Updated stock quantity must be between 0 and 100,000")
        return v


class ProductResponse(BaseModel):
    id: int = Field(..., description="Unique product identifier", examples=[1])
    name: str = Field(..., description="Product name", examples=["Wireless Mouse"])
    sku: str = Field(..., description="Stock Keeping Unit", examples=["MOUSE-001"])
    price: float = Field(..., description="Product price", examples=[29.99])
    category_id: int = Field(..., description="Category ID", examples=[1])
    quantity: int = Field(..., description="Stock quantity", examples=[100])
    description: str | None = Field(default=None, description="Product description")
    created_at: str = Field(..., description="ISO 8601 creation timestamp")

    model_config = ConfigDict(from_attributes=True)


class ErrorResponse(BaseModel):
    detail: str = Field(..., description="Error message description")
