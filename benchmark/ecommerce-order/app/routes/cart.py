from typing import Annotated

from fastapi import APIRouter, Path, Response, status
from fastapi.responses import JSONResponse

from app.auth import CurrentIdentity
from app.config import is_bug_active
from app.schemas import CartItemCreate, CartItemResponse, CartResponse
from app.store import store

router = APIRouter(tags=["Cart"])


@router.get("/cart", response_model=CartResponse, operation_id="getCart")
def get_cart(identity: CurrentIdentity) -> Response | CartResponse:
    cart = store.get_cart(identity)
    if is_bug_active("bug_022") and len(cart.items) == 0:
        payload = cart.model_dump(mode="json")
        payload["items"] = None
        return JSONResponse(status_code=status.HTTP_200_OK, content=payload)
    return cart


@router.post(
    "/cart/items",
    response_model=CartItemResponse,
    status_code=status.HTTP_201_CREATED,
    operation_id="addCartItem",
)
def add_cart_item(data: CartItemCreate, identity: CurrentIdentity) -> CartItemResponse:
    return store.add_cart_item(identity, data)


@router.delete(
    "/cart/items/{item_id}",
    status_code=status.HTTP_204_NO_CONTENT,
    operation_id="removeCartItem",
)
def remove_cart_item(item_id: Annotated[int, Path(ge=1)], identity: CurrentIdentity) -> Response:
    store.remove_cart_item(identity, item_id)
    return Response(status_code=status.HTTP_204_NO_CONTENT)
