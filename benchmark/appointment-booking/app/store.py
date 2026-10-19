"""In-memory state and domain logic for the appointment-booking benchmark."""

from datetime import datetime
from threading import RLock

from fastapi import status

from app.auth import Identity, Role, api_error
from app.config import is_bug_active
from app.schemas import (
    AppointmentCreate,
    AppointmentReschedule,
    AppointmentResponse,
    AppointmentStatus,
    DepartmentResponse,
    DoctorResponse,
    TimeSlotResponse,
)

# Reference baseline date for time-dependent operations
SIMULATED_NOW = datetime(2026, 10, 10, 8, 0, 0)
SIMULATED_TODAY_STR = "2026-10-10"

HOLIDAYS = {"2026-12-25", "2026-01-01"}
STANDARD_SLOTS = [
    ("SLOT-0800", "08:00:00", "08:30:00"),
    ("SLOT-0830", "08:30:00", "09:00:00"),
    ("SLOT-0900", "09:00:00", "09:30:00"),
    ("SLOT-0930", "09:30:00", "10:00:00"),
    ("SLOT-1000", "10:00:00", "10:30:00"),
    ("SLOT-1030", "10:30:00", "11:00:00"),
    ("SLOT-1100", "11:00:00", "11:30:00"),
    ("SLOT-1130", "11:30:00", "12:00:00"),
    ("SLOT-1300", "13:00:00", "13:30:00"),
    ("SLOT-1330", "13:30:00", "14:00:00"),
    ("SLOT-1400", "14:00:00", "14:30:00"),
    ("SLOT-1430", "14:30:00", "15:00:00"),
    ("SLOT-1500", "15:00:00", "15:30:00"),
    ("SLOT-1530", "15:30:00", "16:00:00"),
    ("SLOT-1600", "16:00:00", "16:30:00"),
    ("SLOT-1630", "16:30:00", "17:00:00"),
    ("SLOT-1700", "17:00:00", "17:30:00"),
    ("SLOT-1730", "17:30:00", "18:00:00"),
    ("SLOT-1800", "18:00:00", "18:30:00"),
    ("SLOT-1830", "18:30:00", "19:00:00"),
    ("SLOT-1900", "19:00:00", "19:30:00"),
    ("SLOT-1930", "19:30:00", "20:00:00"),
]


class BookingStore:
    def __init__(self) -> None:
        self._state_lock = RLock()
        self.seed_defaults()

    def seed_defaults(self) -> None:
        with self._state_lock:
            self._seed_defaults()

    def _seed_defaults(self) -> None:
        self.departments: dict[int, DepartmentResponse] = {
            1: DepartmentResponse(
                id=1,
                name="Cardiology",
                code="CARDIO",
                description="Heart and cardiovascular healthcare",
            ),
            2: DepartmentResponse(
                id=2,
                name="Dermatology",
                code="DERM",
                description="Skin, hair, and nail clinical care",
            ),
            3: DepartmentResponse(
                id=3,
                name="Pediatrics",
                code="PEDIA",
                description="Infant, child, and adolescent medicine",
            ),
        }

        self.doctors: dict[int, DoctorResponse] = {
            101: DoctorResponse(
                id=101,
                name="Dr. Carol Danvers",
                department_id=1,
                specialization="Interventional Cardiology",
                max_daily_quota=20,
            ),
            102: DoctorResponse(
                id=102,
                name="Dr. Stephen Strange",
                department_id=2,
                specialization="Dermatology & Skin Surgery",
                max_daily_quota=20,
            ),
        }

        # Seed pre-existing appointments
        # 501: Alice, Dr. Carol on 2026-10-15 (future, > 24h away)
        # 502: Bob, Dr. Carol on 2026-10-10 at 09:00 (starts in 1 hour, < 24h away)
        self.appointments: dict[int, AppointmentResponse] = {
            501: AppointmentResponse(
                id=501,
                patient_id=1,
                doctor_id=101,
                department_id=1,
                appointment_date="2026-10-15",
                slot_id="SLOT-0900",
                start_time="2026-10-15T09:00:00Z",
                end_time="2026-10-15T09:30:00Z",
                patient_name="Alice Smith",
                patient_phone="+1234567890",
                notes="Routine checkup",
                status=AppointmentStatus.BOOKED,
            ),
            502: AppointmentResponse(
                id=502,
                patient_id=2,
                doctor_id=101,
                department_id=1,
                appointment_date="2026-10-10",
                slot_id="SLOT-0900",
                start_time="2026-10-10T09:00:00Z",
                end_time="2026-10-10T09:30:00Z",
                patient_name="Bob Jones",
                patient_phone="+1987654321",
                notes="Follow-up consultation",
                status=AppointmentStatus.BOOKED,
            ),
        }
        self.next_appointment_id = 503

    def list_departments(self) -> list[DepartmentResponse]:
        with self._state_lock:
            return list(self.departments.values())

    def get_department(self, department_id: int) -> DepartmentResponse:
        with self._state_lock:
            dept = self.departments.get(department_id)
            if dept is None:
                raise api_error(
                    status.HTTP_404_NOT_FOUND,
                    "DEPARTMENT_NOT_FOUND",
                    f"Department {department_id} not found",
                )
            return dept

    def list_doctors(self, department_id: int | None = None) -> list[DoctorResponse]:
        with self._state_lock:
            doctors = list(self.doctors.values())
            if department_id is not None:
                doctors = [d for d in doctors if d.department_id == department_id]
            return doctors

    def get_doctor(self, doctor_id: int) -> DoctorResponse:
        with self._state_lock:
            doc = self.doctors.get(doctor_id)
            if doc is None:
                raise api_error(
                    status.HTTP_404_NOT_FOUND,
                    "DOCTOR_NOT_FOUND",
                    f"Doctor {doctor_id} not found",
                )
            return doc

    def get_doctor_slots(self, doctor_id: int, date: str) -> list[TimeSlotResponse]:
        with self._state_lock:
            self.get_doctor(doctor_id)

            try:
                datetime.strptime(date, "%Y-%m-%d")
            except ValueError as err:
                raise api_error(
                    status.HTTP_422_UNPROCESSABLE_ENTITY,
                    "INVALID_DATE",
                    f"Date '{date}' is not a valid calendar date",
                ) from err

            # Find booked slots for this doctor on this date
            booked_slots = {
                app.slot_id
                for app in self.appointments.values()
                if app.doctor_id == doctor_id
                and app.appointment_date == date
                and app.status in {AppointmentStatus.BOOKED, AppointmentStatus.RESCHEDULED}
            }

            slots: list[TimeSlotResponse] = []
            for slot_id, start_clock, end_clock in STANDARD_SLOTS:
                is_available = slot_id not in booked_slots

                # BUG-029: Schema mismatch (non-ISO 8601 string)
                if is_bug_active("bug_029"):
                    start_str = f"{start_clock[:5]} AM {date.replace('-', '/')}"
                    end_str = f"{end_clock[:5]} AM {date.replace('-', '/')}"
                else:
                    start_str = f"{date}T{start_clock}Z"
                    end_str = f"{date}T{end_clock}Z"

                slots.append(
                    TimeSlotResponse(
                        slot_id=slot_id,
                        doctor_id=doctor_id,
                        start_time=start_str,
                        end_time=end_str,
                        is_available=is_available,
                    )
                )
            return slots

    def list_appointments(self, identity: Identity) -> list[AppointmentResponse]:
        with self._state_lock:
            if identity.role in {Role.ADMIN, Role.DOCTOR}:
                return list(self.appointments.values())
            return [app for app in self.appointments.values() if app.patient_id == identity.user_id]

    def _validate_appointment_rules(
        self,
        doctor_id: int,
        target_date: str,
        target_slot_id: str,
        exclude_appointment_id: int | None = None,
    ) -> tuple[str, str, str]:
        """Validate destination date, closure, slot existence, conflict, and quota.

        Returns slot_info tuple (slot_id, start_clock, end_clock).
        """
        doctor = self.get_doctor(doctor_id)

        # Check calendar date validity
        try:
            dt = datetime.strptime(target_date, "%Y-%m-%d")
        except ValueError as err:
            raise api_error(
                status.HTTP_422_UNPROCESSABLE_ENTITY,
                "INVALID_DATE",
                f"Date '{target_date}' is not a valid calendar date",
            ) from err

        # Check closed day/holiday (BUG-025)
        is_sunday = dt.weekday() == 6
        is_holiday = target_date in HOLIDAYS
        if (is_sunday or is_holiday) and not is_bug_active("bug_025"):
            raise api_error(
                status.HTTP_409_CONFLICT,
                "CLINIC_CLOSED",
                "Clinic is closed on Sundays and public holidays",
            )

        # Check slot validity
        slot_info = next((s for s in STANDARD_SLOTS if s[0] == target_slot_id), None)
        if not slot_info:
            raise api_error(
                status.HTTP_422_UNPROCESSABLE_ENTITY,
                "INVALID_SLOT",
                f"Slot {target_slot_id} does not exist",
            )

        # Check existing booking (BUG-024, BUG-026)
        is_slot_booked = any(
            app.id != exclude_appointment_id
            and app.doctor_id == doctor_id
            and app.appointment_date == target_date
            and app.slot_id == target_slot_id
            and app.status in {AppointmentStatus.BOOKED, AppointmentStatus.RESCHEDULED}
            for app in self.appointments.values()
        )

        if is_slot_booked:
            if is_bug_active("bug_026"):
                raise api_error(
                    status.HTTP_500_INTERNAL_SERVER_ERROR,
                    "INTERNAL_SERVER_ERROR",
                    "Slot conflict triggered internal error",
                )
            if not is_bug_active("bug_024"):
                raise api_error(
                    status.HTTP_409_CONFLICT,
                    "SLOT_ALREADY_BOOKED",
                    "Selected time slot is already booked for this doctor",
                )

        # Check doctor daily quota (BUG-028)
        current_day_appointments = sum(
            1
            for app in self.appointments.values()
            if app.id != exclude_appointment_id
            and app.doctor_id == doctor_id
            and app.appointment_date == target_date
            and app.status in {AppointmentStatus.BOOKED, AppointmentStatus.RESCHEDULED}
        )

        if current_day_appointments >= doctor.max_daily_quota and not is_bug_active("bug_028"):
            raise api_error(
                status.HTTP_409_CONFLICT,
                "DOCTOR_QUOTA_EXCEEDED",
                f"Doctor has reached maximum quota of {doctor.max_daily_quota} appointments",
            )

        return slot_info

    def create_appointment(
        self, identity: Identity, payload: AppointmentCreate
    ) -> AppointmentResponse:
        with self._state_lock:
            doctor = self.get_doctor(payload.doctor_id)
            slot_info = self._validate_appointment_rules(
                doctor_id=doctor.id,
                target_date=payload.appointment_date,
                target_slot_id=payload.slot_id,
                exclude_appointment_id=None,
            )

            appointment_id = self.next_appointment_id
            self.next_appointment_id += 1

            app = AppointmentResponse(
                id=appointment_id,
                patient_id=identity.user_id,
                doctor_id=doctor.id,
                department_id=doctor.department_id,
                appointment_date=payload.appointment_date,
                slot_id=payload.slot_id,
                start_time=f"{payload.appointment_date}T{slot_info[1]}Z",
                end_time=f"{payload.appointment_date}T{slot_info[2]}Z",
                patient_name=identity.name,
                patient_phone=payload.patient_phone,
                notes=payload.notes,
                status=AppointmentStatus.BOOKED,
            )
            self.appointments[appointment_id] = app
            return app

    def reschedule_appointment(
        self, identity: Identity, appointment_id: int, payload: AppointmentReschedule
    ) -> AppointmentResponse:
        with self._state_lock:
            app = self.appointments.get(appointment_id)
            if not app:
                raise api_error(
                    status.HTTP_404_NOT_FOUND,
                    "APPOINTMENT_NOT_FOUND",
                    f"Appointment {appointment_id} not found",
                )

            # Check ownership unless doctor/admin
            if identity.role == Role.PATIENT and app.patient_id != identity.user_id:
                raise api_error(
                    status.HTTP_403_FORBIDDEN,
                    "FORBIDDEN",
                    "Appointment belongs to another patient",
                )

            # Check 24-hour reschedule window (BUG-027)
            # Compare current appointment start time against simulated baseline now
            try:
                app_start = datetime.strptime(
                    f"{app.appointment_date} {app.slot_id[5:7]}:{app.slot_id[7:9]}:00",
                    "%Y-%m-%d %H:%M:%S",
                )
            except ValueError:
                app_start = SIMULATED_NOW

            hours_diff = (app_start - SIMULATED_NOW).total_seconds() / 3600.0

            if hours_diff < 24.0 and not is_bug_active("bug_027"):
                raise api_error(
                    status.HTTP_409_CONFLICT,
                    "RESCHEDULE_WINDOW_EXPIRED",
                    "Appointments can only be rescheduled at least 24 hours in advance",
                )

            slot_info = self._validate_appointment_rules(
                doctor_id=app.doctor_id,
                target_date=payload.new_date,
                target_slot_id=payload.new_slot_id,
                exclude_appointment_id=appointment_id,
            )

            # Update appointment
            updated = app.model_copy(
                update={
                    "appointment_date": payload.new_date,
                    "slot_id": payload.new_slot_id,
                    "start_time": f"{payload.new_date}T{slot_info[1]}Z",
                    "end_time": f"{payload.new_date}T{slot_info[2]}Z",
                    "notes": payload.notes if payload.notes is not None else app.notes,
                    "status": AppointmentStatus.RESCHEDULED,
                }
            )
            self.appointments[appointment_id] = updated
            return updated

    def cancel_appointment(self, identity: Identity, appointment_id: int) -> None:
        with self._state_lock:
            app = self.appointments.get(appointment_id)
            if not app:
                raise api_error(
                    status.HTTP_404_NOT_FOUND,
                    "APPOINTMENT_NOT_FOUND",
                    f"Appointment {appointment_id} not found",
                )

            # Auth / IDOR check (BUG-030)
            if (
                identity.role == Role.PATIENT
                and app.patient_id != identity.user_id
                and not is_bug_active("bug_030")
            ):
                raise api_error(
                    status.HTTP_403_FORBIDDEN,
                    "FORBIDDEN",
                    "Appointment belongs to another patient",
                )

            del self.appointments[appointment_id]


store = BookingStore()
