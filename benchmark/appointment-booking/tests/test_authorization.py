"""Tests for authentication and role-based access control."""

from fastapi.testclient import TestClient


def test_missing_and_invalid_tokens(client: TestClient) -> None:
    """Verify unauthorized access when bearer token is missing or invalid."""
    no_auth = client.get("/appointments")
    assert no_auth.status_code == 401
    assert no_auth.json()["detail"]["code"] == "UNAUTHORIZED"

    bad_auth = client.get("/appointments", headers={"Authorization": "Bearer bad-token"})
    assert bad_auth.status_code == 401
    assert bad_auth.json()["detail"]["code"] == "UNAUTHORIZED"

    malformed = client.get("/appointments", headers={"Authorization": "NotBearer 12345"})
    assert malformed.status_code == 401


def test_idor_cross_patient_cancellation_forbidden(client: TestClient) -> None:
    """Verify Patient Alice cannot cancel Patient Bob's appointment (IDOR protection)."""
    alice_headers = {"Authorization": "Bearer patient-alice-token"}
    # Appointment 502 belongs to Bob
    resp = client.delete("/appointments/502", headers=alice_headers)
    assert resp.status_code == 403
    assert resp.json()["detail"]["code"] == "FORBIDDEN"


def test_admin_can_view_all_appointments(client: TestClient) -> None:
    """Verify Admin can see appointments for all patients."""
    admin_headers = {"Authorization": "Bearer admin-token"}
    resp = client.get("/appointments", headers=admin_headers)
    assert resp.status_code == 200
    apps = resp.json()
    assert any(a["patient_id"] == 1 for a in apps)
    assert any(a["patient_id"] == 2 for a in apps)
