"""S10-01 audit regressions: the clean variant must conform to openapi.yaml.

A spec-derived assertion (status/status_in/json_schema) that FAILs on the clean variant is a
false positive (D14), so every response the clean API returns has to match the documented
response schema of its operation.
"""

from functools import lru_cache
from pathlib import Path
from typing import Any

import pytest
import yaml
from fastapi.testclient import TestClient
from jsonschema import Draft202012Validator

from app.store import STANDARD_SLOTS

ALICE_HEADERS = {"Authorization": "Bearer patient-alice-token"}
SPEC_PATH = Path(__file__).resolve().parents[1] / "openapi.yaml"


@lru_cache
def _spec() -> dict[str, Any]:
    with SPEC_PATH.open(encoding="utf-8") as f:
        data: dict[str, Any] = yaml.safe_load(f)
    return data


def _resolve(node: dict[str, Any]) -> dict[str, Any]:
    while "$ref" in node:
        target: Any = _spec()
        for part in node["$ref"].removeprefix("#/").split("/"):
            target = target[part]
        node = target
    return node


def _schema_errors(path: str, method: str, status: int, body: Any) -> list[str]:
    responses = _spec()["paths"][path][method]["responses"]
    assert str(status) in responses, f"{method.upper()} {path} does not document {status}"
    response = _resolve(responses[str(status)])
    schema = response["content"]["application/json"]["schema"]
    root = {**_spec(), **schema}
    return [e.message for e in Draft202012Validator(root).iter_errors(body)]


def _create(client: TestClient, **overrides: Any) -> Any:
    payload = {
        "doctor_id": 101,
        "appointment_date": "2026-10-16",
        "slot_id": "SLOT-1000",
        "patient_phone": "+1234567890",
        **overrides,
    }
    return client.post("/appointments", json=payload, headers=ALICE_HEADERS)


def test_appointment_without_notes_matches_documented_response_schema(
    client: TestClient,
) -> None:
    resp = _create(client)
    assert resp.status_code == 201
    assert resp.json()["notes"] is None
    assert _schema_errors("/appointments", "post", 201, resp.json()) == []

    listed = client.get("/appointments", headers=ALICE_HEADERS)
    assert _schema_errors("/appointments", "get", 200, listed.json()) == []

    patched = client.patch(
        f"/appointments/{resp.json()['id']}",
        json={"new_date": "2026-10-17", "new_slot_id": "SLOT-1400"},
        headers=ALICE_HEADERS,
    )
    assert patched.status_code == 200
    assert _schema_errors("/appointments/{appointment_id}", "patch", 200, patched.json()) == []


def test_business_422_errors_match_documented_schema(client: TestClient) -> None:
    bad_slot = _create(client, slot_id="SLOT-9999")
    assert bad_slot.status_code == 422
    assert bad_slot.json()["detail"]["code"] == "INVALID_SLOT"
    assert _schema_errors("/appointments", "post", 422, bad_slot.json()) == []

    bad_date = client.get("/doctors/101/slots", params={"date": "2026-02-30"})
    assert bad_date.status_code == 422
    assert _schema_errors("/doctors/{doctor_id}/slots", "get", 422, bad_date.json()) == []

    pydantic_422 = _create(client, doctor_id="x")
    assert pydantic_422.status_code == 422
    assert _schema_errors("/appointments", "post", 422, pydantic_422.json()) == []


def test_slot_ids_are_enumerated_in_spec_and_each_is_bookable(client: TestClient) -> None:
    schemas = _spec()["components"]["schemas"]
    expected = [slot_id for slot_id, _, _ in STANDARD_SLOTS]
    assert schemas["AppointmentCreate"]["properties"]["slot_id"]["enum"] == expected
    assert schemas["AppointmentReschedule"]["properties"]["new_slot_id"]["enum"] == expected

    # A spec-conforming generator picking any enum member gets 201 on clean.
    resp = _create(client, slot_id=expected[0], appointment_date="2026-10-19")
    assert resp.status_code == 201


def test_get_department_does_not_document_bug_031_status() -> None:
    responses = _spec()["paths"]["/departments/{department_id}"]["get"]["responses"]
    assert "400" not in responses


@pytest.mark.parametrize(
    ("method", "url", "spec_path"),
    [
        ("get", "/departments/0", "/departments/{department_id}"),
        ("get", "/departments/abc", "/departments/{department_id}"),
        ("get", "/doctors/0", "/doctors/{doctor_id}"),
        ("get", "/doctors/abc", "/doctors/{doctor_id}"),
        ("get", "/doctors/0/slots?date=2026-10-16", "/doctors/{doctor_id}/slots"),
        ("delete", "/appointments/0", "/appointments/{appointment_id}"),
        ("delete", "/appointments/abc", "/appointments/{appointment_id}"),
        ("patch", "/appointments/0", "/appointments/{appointment_id}"),
    ],
)
def test_path_ids_below_minimum_are_documented_validation_errors(
    client: TestClient, method: str, url: str, spec_path: str
) -> None:
    kwargs: dict[str, Any] = {"headers": ALICE_HEADERS}
    if method == "patch":
        kwargs["json"] = {"new_date": "2026-10-17", "new_slot_id": "SLOT-1400"}
    resp = client.request(method.upper(), url, **kwargs)
    assert resp.status_code == 422
    assert _schema_errors(spec_path, method, 422, resp.json()) == []


@pytest.mark.parametrize("phone", ["123456789\n", "+1234567890\n"])
def test_phone_with_trailing_newline_is_rejected_on_clean(client: TestClient, phone: str) -> None:
    resp = _create(client, patient_phone=phone)
    assert resp.status_code == 422


def test_runtime_openapi_is_the_authoritative_yaml(client: TestClient) -> None:
    resp = client.get("/openapi.json")
    assert resp.status_code == 200
    assert resp.json() == _spec()
