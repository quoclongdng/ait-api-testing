"""Data contracts and validation schemas for appointment-booking benchmark API."""

import re
from datetime import date, datetime
from enum import StrEnum
from typing import Annotated

from pydantic import BaseModel, ConfigDict, Field, field_validator

from app.config import is_bug_active


class StrictModel(BaseModel):
    model_config = ConfigDict(extra="forbid")


class AppointmentStatus(StrEnum):
    BOOKED = "BOOKED"
    COMPLETED = "COMPLETED"
    CANCELLED = "CANCELLED"
    RESCHEDULED = "RESCHEDULED"


class HealthResponse(StrictModel):
    status: str = "ok"
    service: str = "appointment-booking"
    version: str = "1.0.0"


class DepartmentResponse(StrictModel):
    id: int
    name: str
    code: str
    description: str


class DoctorResponse(StrictModel):
    id: int
    name: str
    department_id: int
    specialization: str
    max_daily_quota: int = 20


class TimeSlotResponse(StrictModel):
    slot_id: str
    doctor_id: int
    start_time: str
    end_time: str
    is_available: bool


class AppointmentCreate(StrictModel):
    doctor_id: int = Field(ge=1)
    appointment_date: str = Field(pattern=r"^\d{4}-\d{2}-\d{2}$")
    slot_id: str = Field(min_length=1, max_length=32)
    patient_phone: str
    notes: Annotated[str | None, Field(default=None, max_length=500)] = None

    @field_validator("appointment_date")
    @classmethod
    def validate_appointment_date(cls, v: str) -> str:
        """Validate calendar date and enforce past date rule (BUG-023)."""
        try:
            parsed_date = datetime.strptime(v, "%Y-%m-%d").date()
        except ValueError as err:
            raise ValueError(f"Date '{v}' is not a valid calendar date") from err

        simulated_today = date(2026, 10, 10)
        if parsed_date < simulated_today and not is_bug_active("bug_023"):
            raise ValueError(
                f"Appointment date '{v}' cannot be in the past (must be >= 2026-10-10)"
            )
        return v

    @field_validator("patient_phone")
    @classmethod
    def validate_phone(cls, v: str) -> str:
        """BUG-032: Enforce phone number format regex unless bug toggle active.

        fullmatch (not match + ``$``): Python's ``$`` also matches before a trailing newline,
        which the spec pattern (ECMA semantics) rejects.
        """
        if not is_bug_active("bug_032") and not re.fullmatch(r"\+?[0-9]{9,15}", v):
            raise ValueError("Invalid phone number format (must be 9 to 15 digits)")
        return v


class AppointmentReschedule(StrictModel):
    new_date: str = Field(pattern=r"^\d{4}-\d{2}-\d{2}$")
    new_slot_id: str = Field(min_length=1, max_length=32)
    notes: Annotated[str | None, Field(default=None, max_length=500)] = None

    @field_validator("new_date")
    @classmethod
    def validate_new_date(cls, v: str) -> str:
        """Validate calendar date and enforce past date rule (BUG-023)."""
        try:
            parsed_date = datetime.strptime(v, "%Y-%m-%d").date()
        except ValueError as err:
            raise ValueError(f"Date '{v}' is not a valid calendar date") from err

        simulated_today = date(2026, 10, 10)
        if parsed_date < simulated_today and not is_bug_active("bug_023"):
            raise ValueError(
                f"Appointment date '{v}' cannot be in the past (must be >= 2026-10-10)"
            )
        return v


class AppointmentResponse(StrictModel):
    id: int
    patient_id: int
    doctor_id: int
    department_id: int
    appointment_date: str
    slot_id: str
    start_time: str
    end_time: str
    patient_name: str
    patient_phone: str
    notes: str | None = None
    status: AppointmentStatus
