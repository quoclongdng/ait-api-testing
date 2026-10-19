from typing import Annotated

from fastapi import APIRouter, Path, Response, status
from fastapi.responses import JSONResponse

from app.auth import CurrentIdentity
from app.config import is_bug_active
from app.schemas import CheckoutRequest, OrderCreate, OrderResponse
from app.store import store

router = APIRouter(tags=["Orders"])


@router.post(
    "/checkout",
    response_model=OrderResponse,
    status_code=status.HTTP_201_CREATED,
    operation_id="checkoutCart",
)
def checkout(data: CheckoutRequest, response: Response, identity: CurrentIdentity) -> OrderResponse:
    order = store.checkout(identity, data.cart_id)
    if is_bug_active("bug_014"):
        response.status_code = status.HTTP_200_OK
    else:
        response.headers["Location"] = f"/orders/{order.id}"
    return order


@router.post(
    "/orders",
    response_model=OrderResponse,
    status_code=status.HTTP_201_CREATED,
    operation_id="createOrder",
)
def create_order(
    data: OrderCreate, response: Response, identity: CurrentIdentity
) -> Response | OrderResponse:
    order = store.create_order(identity, data)
    response.headers["Location"] = f"/orders/{order.id}"
    if is_bug_active("bug_019"):
        payload = order.model_dump(mode="json")
        payload["total_amount"] = str(payload["total_amount"])
        return JSONResponse(
            status_code=status.HTTP_201_CREATED,
            content=payload,
            headers={"Location": f"/orders/{order.id}"},
        )
    return order


@router.get("/orders/{order_id}", response_model=OrderResponse, operation_id="getOrder")
def get_order(order_id: Annotated[int, Path(ge=1)], identity: CurrentIdentity) -> OrderResponse:
    return store.get_order(identity, order_id)


@router.post("/orders/{order_id}/pay", response_model=OrderResponse, operation_id="payOrder")
def pay_order(order_id: Annotated[int, Path(ge=1)], identity: CurrentIdentity) -> OrderResponse:
    return store.pay_order(identity, order_id)


@router.post(
    "/orders/{order_id}/cancel",
    response_model=OrderResponse,
    operation_id="cancelOrder",
)
def cancel_order(order_id: Annotated[int, Path(ge=1)], identity: CurrentIdentity) -> OrderResponse:
    return store.cancel_order(identity, order_id)
