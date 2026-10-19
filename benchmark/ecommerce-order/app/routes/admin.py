from fastapi import APIRouter

from app.auth import AdminIdentity
from app.schemas import OrderResponse
from app.store import store

router = APIRouter(prefix="/admin", tags=["Administration"])


@router.get("/orders", response_model=list[OrderResponse], operation_id="listAllOrders")
def list_all_orders(_identity: AdminIdentity) -> list[OrderResponse]:
    return store.list_orders()
