"""Tests for departments, doctors, and slot availability catalog."""

from fastapi.testclient import TestClient


def test_health_check(client: TestClient) -> None:
    """Verify health endpoint returns status ok."""
    resp = client.get("/health")
    assert resp.status_code == 200
    assert resp.json()["status"] == "ok"
    assert resp.json()["service"] == "appointment-booking"


def test_list_and_get_departments(client: TestClient) -> None:
    """Verify listing departments and retrieving department details."""
    resp = client.get("/departments")
    assert resp.status_code == 200
    depts = resp.json()
    assert len(depts) >= 3
    assert any(d["code"] == "CARDIO" for d in depts)

    cardio = client.get("/departments/1")
    assert cardio.status_code == 200
    assert cardio.json()["name"] == "Cardiology"

    not_found = client.get("/departments/999")
    assert not_found.status_code == 404
    assert not_found.json()["detail"]["code"] == "DEPARTMENT_NOT_FOUND"


def test_list_and_get_doctors(client: TestClient) -> None:
    """Verify listing doctors with optional department filter."""
    resp = client.get("/doctors")
    assert resp.status_code == 200
    docs = resp.json()
    assert len(docs) >= 2

    # Filter by department
    filtered = client.get("/doctors?department_id=1")
    assert filtered.status_code == 200
    assert all(d["department_id"] == 1 for d in filtered.json())

    # Get specific doctor
    doc = client.get("/doctors/101")
    assert doc.status_code == 200
    assert doc.json()["name"] == "Dr. Carol Danvers"

    doc_404 = client.get("/doctors/999")
    assert doc_404.status_code == 404
    assert doc_404.json()["detail"]["code"] == "DOCTOR_NOT_FOUND"


def test_doctor_time_slots(client: TestClient) -> None:
    """Verify time slot inspection for doctors."""
    resp = client.get("/doctors/101/slots?date=2026-10-15")
    assert resp.status_code == 200
    slots = resp.json()
    assert len(slots) >= 6

    # Slot SLOT-0900 on 2026-10-15 was pre-booked for Alice
    booked_slot = next(s for s in slots if s["slot_id"] == "SLOT-0900")
    assert booked_slot["is_available"] is False

    # Other slots should be available
    free_slot = next(s for s in slots if s["slot_id"] == "SLOT-1000")
    assert free_slot["is_available"] is True
