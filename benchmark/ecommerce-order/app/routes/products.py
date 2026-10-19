from typing import Annotated

from fastapi import APIRouter, Path

from app.schemas import ProductResponse
from app.store import store

router = APIRouter(prefix="/products", tags=["Products"])


@router.get("", response_model=list[ProductResponse], operation_id="listProducts")
def list_products() -> list[ProductResponse]:
    return store.list_products()


@router.get("/{product_id}", response_model=ProductResponse, operation_id="getProduct")
def get_product(product_id: Annotated[int, Path(ge=1)]) -> ProductResponse:
    return store.get_product(product_id)
