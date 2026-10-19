"""Appointment workflow endpoints for appointment-booking benchmark."""

from typing import Annotated

from fastapi import APIRouter, Depends, Path, Response, status

from app.auth import Identity, get_current_user
from app.schemas import (
    AppointmentCreate,
    AppointmentReschedule,
    AppointmentResponse,
)
from app.store import store

router = APIRouter(prefix="/appointments", tags=["Appointments"])


@router.get("", response_model=list[AppointmentResponse])
def list_appointments(
    identity: Annotated[Identity, Depends(get_current_user)],
) -> list[AppointmentResponse]:
    """List appointments for the current user (or all if doctor/admin)."""
    return store.list_appointments(identity)


@router.post("", response_model=AppointmentResponse, status_code=status.HTTP_201_CREATED)
def create_appointment(
    payload: AppointmentCreate,
    identity: Annotated[Identity, Depends(get_current_user)],
    response: Response,
) -> AppointmentResponse:
    """Book a new medical appointment.

    Requires valid date (not in past), doctor available slot, clinic open day,
    and valid patient contact phone number.
    """
    app = store.create_appointment(identity, payload)
    response.headers["Location"] = f"/appointments/{app.id}"
    return app


@router.patch("/{appointment_id}", response_model=AppointmentResponse)
def reschedule_appointment(
    appointment_id: Annotated[int, Path(ge=1)],
    payload: AppointmentReschedule,
    identity: Annotated[Identity, Depends(get_current_user)],
) -> AppointmentResponse:
    """Reschedule an existing appointment to a new date/slot.

    Requires at least 24 hours prior notice before original appointment start.
    """
    return store.reschedule_appointment(identity, appointment_id, payload)


@router.delete("/{appointment_id}", status_code=status.HTTP_204_NO_CONTENT)
def cancel_appointment(
    appointment_id: Annotated[int, Path(ge=1)],
    identity: Annotated[Identity, Depends(get_current_user)],
) -> Response:
    """Cancel an existing appointment.

    Patients can only cancel their own appointments unless doctor or admin.
    """
    store.cancel_appointment(identity, appointment_id)
    return Response(status_code=status.HTTP_204_NO_CONTENT)
