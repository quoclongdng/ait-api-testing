"""Tests for appointment booking, rescheduling, and cancellation workflow."""

from fastapi.testclient import TestClient

ALICE_HEADERS = {"Authorization": "Bearer patient-alice-token"}
BOB_HEADERS = {"Authorization": "Bearer patient-bob-token"}


def test_appointment_booking_and_cancellation_workflow(client: TestClient) -> None:
    """Verify booking a new appointment, listing it, and cancelling it."""
    # 1. Book an appointment for Alice on 2026-10-16 at 10:00
    create_payload = {
        "doctor_id": 101,
        "appointment_date": "2026-10-16",
        "slot_id": "SLOT-1000",
        "patient_phone": "+1234567890",
        "notes": "General checkup",
    }
    create_resp = client.post("/appointments", json=create_payload, headers=ALICE_HEADERS)
    assert create_resp.status_code == 201
    created = create_resp.json()
    assert created["doctor_id"] == 101
    assert created["slot_id"] == "SLOT-1000"
    assert created["status"] == "BOOKED"
    assert "Location" in create_resp.headers

    app_id = created["id"]

    # 2. List appointments: Alice sees her new appointment
    list_resp = client.get("/appointments", headers=ALICE_HEADERS)
    assert list_resp.status_code == 200
    assert any(a["id"] == app_id for a in list_resp.json())

    # 3. Reschedule the appointment to 2026-10-17 at 14:00 (allowed > 24h)
    resched_payload = {
        "new_date": "2026-10-17",
        "new_slot_id": "SLOT-1400",
        "notes": "Postponed by patient",
    }
    resched_resp = client.patch(
        f"/appointments/{app_id}", json=resched_payload, headers=ALICE_HEADERS
    )
    assert resched_resp.status_code == 200
    assert resched_resp.json()["status"] == "RESCHEDULED"
    assert resched_resp.json()["appointment_date"] == "2026-10-17"
    assert resched_resp.json()["slot_id"] == "SLOT-1400"

    # 4. Cancel the appointment
    cancel_resp = client.delete(f"/appointments/{app_id}", headers=ALICE_HEADERS)
    assert cancel_resp.status_code == 204

    # 5. After cancellation, slot is freed
    slots_resp = client.get("/doctors/101/slots?date=2026-10-17")
    assert slots_resp.status_code == 200
    slot_1400 = next(s for s in slots_resp.json() if s["slot_id"] == "SLOT-1400")
    assert slot_1400["is_available"] is True


def test_conflict_on_already_booked_slot(client: TestClient) -> None:
    """Verify booking an already occupied slot returns 409 Conflict."""
    # Slot SLOT-0900 on 2026-10-15 was pre-booked for Alice
    conflict_payload = {
        "doctor_id": 101,
        "appointment_date": "2026-10-15",
        "slot_id": "SLOT-0900",
        "patient_phone": "+1987654321",
    }
    resp = client.post("/appointments", json=conflict_payload, headers=BOB_HEADERS)
    assert resp.status_code == 409
    assert resp.json()["detail"]["code"] == "SLOT_ALREADY_BOOKED"


def test_booking_on_closed_clinic_day(client: TestClient) -> None:
    """Verify booking on Sundays returns 409 Conflict."""
    # 2026-10-11 is a Sunday
    sunday_payload = {
        "doctor_id": 101,
        "appointment_date": "2026-10-11",
        "slot_id": "SLOT-0900",
        "patient_phone": "+1234567890",
    }
    resp = client.post("/appointments", json=sunday_payload, headers=ALICE_HEADERS)
    assert resp.status_code == 409
    assert resp.json()["detail"]["code"] == "CLINIC_CLOSED"
