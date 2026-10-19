from fastapi import APIRouter

from app.auth import CurrentIdentity
from app.schemas import CouponApplyRequest, CouponResponse
from app.store import store

router = APIRouter(prefix="/coupons", tags=["Coupons"])


@router.post("/apply", response_model=CouponResponse, operation_id="applyCoupon")
def apply_coupon(data: CouponApplyRequest, identity: CurrentIdentity) -> CouponResponse:
    return store.apply_coupon(identity, data.cart_id, data.code)
