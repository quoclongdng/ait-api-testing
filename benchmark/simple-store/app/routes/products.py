"""Products API routes for simple-store benchmark API."""

from typing import Any

from fastapi import APIRouter, HTTPException, Path, Query, Response, status
from fastapi.responses import JSONResponse

from app.config import is_bug_active
from app.schemas import (
    ErrorResponse,
    ProductCreate,
    ProductResponse,
    ProductUpdate,
)
from app.store import store

router = APIRouter(prefix="/products", tags=["Products"])


@router.post(
    "",
    response_model=ProductResponse,
    status_code=status.HTTP_201_CREATED,
    summary="Create product",
    description="Create a new product associated with an existing category.",
    responses={
        404: {"model": ErrorResponse, "description": "Category not found"},
        409: {"model": ErrorResponse, "description": "Duplicate SKU"},
        422: {"description": "Validation error"},
    },
)
def create_product(payload: ProductCreate, response: Response) -> Any:
    # Verify category exists
    category = store.get_category(payload.category_id)
    if not category:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail=f"Category with ID {payload.category_id} not found.",
        )

    # Check SKU uniqueness
    existing_sku = store.get_product_by_sku(payload.sku)
    if existing_sku:
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail=f"Product with SKU '{payload.sku}' already exists.",
        )

    prod = store.create_product(
        name=payload.name,
        sku=payload.sku,
        price=payload.price,
        category_id=payload.category_id,
        quantity=payload.quantity,
        description=payload.description,
    )

    # BUG-003: return HTTP 200 instead of 201
    if is_bug_active("bug_003"):
        response.status_code = status.HTTP_200_OK

    return ProductResponse(**prod)


@router.get(
    "",
    response_model=list[ProductResponse],
    summary="List products",
    description="Retrieve a paginated list of products, optionally filtered by category.",
    responses={
        422: {"description": "Validation error on limit/offset parameters"},
    },
)
def list_products(
    limit: int = Query(default=20, description="Page limit (1 to 100)"),
    offset: int = Query(default=0, ge=0, description="Offset for pagination"),
    category_id: int | None = Query(default=None, ge=1, description="Filter by category ID"),
) -> list[ProductResponse]:
    # BUG-007: allow limit <= 0 or > 100 causing unhandled crash (500)
    if not is_bug_active("bug_007"):
        if limit < 1 or limit > 100:
            raise HTTPException(
                status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
                detail="limit must be between 1 and 100",
            )
    else:
        if limit <= 0 or limit > 100:
            raise RuntimeError(f"Internal database pagination crash: invalid limit {limit}")

    items = store.list_products(limit=limit, offset=offset, category_id=category_id)
    return [ProductResponse(**item) for item in items]


@router.get(
    "/{id}",
    response_model=ProductResponse,
    summary="Get product by ID",
    description="Retrieve a single product by its unique integer identifier.",
    responses={
        404: {"model": ErrorResponse, "description": "Product not found"},
        422: {"description": "Invalid product ID format"},
    },
)
def get_product(
    id: str = Path(..., description="Product integer identifier"),
) -> Any:
    # BUG-010: unhandled non-numeric string ID causing 500
    if is_bug_active("bug_010") and not id.isdigit():
        raise ValueError(f"Crash processing unvalidated non-numeric product ID: '{id}'")

    try:
        product_id = int(id)
        if product_id < 1:
            raise ValueError()
    except ValueError:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail="Product ID must be an integer >= 1.",
        ) from None

    prod = store.get_product(product_id)
    if not prod:
        # BUG-004: return 200 with empty dict instead of 404
        if is_bug_active("bug_004"):
            return JSONResponse(status_code=status.HTTP_200_OK, content={})

        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail=f"Product with ID {product_id} not found.",
        )

    # BUG-005: omit required 'sku' field in product response
    if is_bug_active("bug_005"):
        data = dict(prod)
        data.pop("sku", None)
        return JSONResponse(status_code=status.HTTP_200_OK, content=data)

    return ProductResponse(**prod)


@router.put(
    "/{id}",
    response_model=ProductResponse,
    summary="Update product",
    description=(
        "Update mutable fields of a product (name, price, quantity, etc). Only the fields sent in "
        "the body are changed: omitted fields and fields sent as null keep their current value."
    ),
    responses={
        404: {"model": ErrorResponse, "description": "Product or category not found"},
        422: {"description": "Validation error on updated fields"},
    },
)
def update_product(
    payload: ProductUpdate,
    id: int = Path(..., ge=1, description="Product identifier"),
) -> ProductResponse:
    existing = store.get_product(id)
    if not existing:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail=f"Product with ID {id} not found.",
        )

    if payload.category_id is not None:
        category = store.get_category(payload.category_id)
        if not category:
            raise HTTPException(
                status_code=status.HTTP_404_NOT_FOUND,
                detail=f"Category with ID {payload.category_id} not found.",
            )

    updates = payload.model_dump(exclude_unset=True)
    updated = store.update_product(id, updates)
    if not updated:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail=f"Product with ID {id} not found.",
        )
    return ProductResponse(**updated)


@router.delete(
    "/{id}",
    status_code=status.HTTP_204_NO_CONTENT,
    summary="Delete product",
    description="Delete a product by its unique identifier.",
    responses={
        204: {"description": "Product successfully deleted, no content returned"},
        404: {"model": ErrorResponse, "description": "Product not found"},
    },
)
def delete_product(
    id: int = Path(..., ge=1, description="Product identifier"),
) -> Response:
    deleted = store.delete_product(id)
    if not deleted:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail=f"Product with ID {id} not found.",
        )

    # BUG-009: return HTTP 200 with body instead of 204 No Content
    if is_bug_active("bug_009"):
        return JSONResponse(
            status_code=status.HTTP_200_OK,
            content={"message": "Product successfully deleted"},
        )

    return Response(status_code=status.HTTP_204_NO_CONTENT)
