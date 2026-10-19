"""Doctor and scheduling endpoints for appointment-booking benchmark."""

from datetime import datetime
from typing import Annotated

from fastapi import APIRouter, Path, Query, status

from app.auth import api_error
from app.schemas import DoctorResponse, TimeSlotResponse
from app.store import store

router = APIRouter(prefix="/doctors", tags=["Doctors"])


@router.get("", response_model=list[DoctorResponse])
def list_doctors(department_id: int | None = Query(None, ge=1)) -> list[DoctorResponse]:
    """List doctors, optionally filtered by department ID."""
    return store.list_doctors(department_id=department_id)


@router.get("/{doctor_id}", response_model=DoctorResponse)
def get_doctor(doctor_id: Annotated[int, Path(ge=1)]) -> DoctorResponse:
    """Get doctor details by ID."""
    return store.get_doctor(doctor_id)


@router.get("/{doctor_id}/slots", response_model=list[TimeSlotResponse])
def get_doctor_slots(
    doctor_id: Annotated[int, Path(ge=1)],
    date: str = Query(..., pattern=r"^\d{4}-\d{2}-\d{2}$"),
) -> list[TimeSlotResponse]:
    """Get available and booked time slots for a doctor on a specific date.

    BUG-029: When bug is active, slot start/end times violate ISO 8601 formatting.
    """
    try:
        datetime.strptime(date, "%Y-%m-%d")
    except ValueError as err:
        raise api_error(
            status.HTTP_422_UNPROCESSABLE_ENTITY,
            "INVALID_DATE",
            f"Query date '{date}' is not a valid calendar date",
        ) from err
    return store.get_doctor_slots(doctor_id=doctor_id, date=date)
