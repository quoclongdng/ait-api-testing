"""S11-01: the `examples` of openapi.yaml match the seeded data of the clean sandbox.

RULE builds its valid requests from these examples (the default role is patient alice), so an
example that points at nothing would turn a valid case into a false positive on the clean variant.
"""

from pathlib import Path
from typing import Any

import yaml
from fastapi.testclient import TestClient

SPEC = yaml.safe_load((Path(__file__).resolve().parents[1] / "openapi.yaml").read_text())
SCHEMAS = SPEC["components"]["schemas"]
ALICE = {"Authorization": "Bearer patient-alice-token"}


def _param(path: str, method: str, name: str) -> Any:
    for param in SPEC["paths"][path][method]["parameters"]:
        if param["name"] == name:
            return param["schema"]["examples"][0]
    raise KeyError(name)


def _body(schema_name: str) -> dict[str, Any]:
    body: dict[str, Any] = {}
    for name, prop in SCHEMAS[schema_name]["properties"].items():
        if "examples" in prop:
            body[name] = prop["examples"][0]
        elif "enum" in prop:
            body[name] = prop["enum"][0]
    return body


def test_every_path_parameter_has_an_example() -> None:
    for path, item in SPEC["paths"].items():
        for method, operation in item.items():
            for param in operation.get("parameters", []):
                if param["in"] == "path":
                    assert param["schema"].get("examples"), f"{method} {path} {param['name']}"


def test_catalog_examples_name_seeded_resources(client: TestClient) -> None:
    department_id = _param("/departments/{department_id}", "get", "department_id")
    assert client.get(f"/departments/{department_id}").status_code == 200
    doctor_id = _param("/doctors/{doctor_id}", "get", "doctor_id")
    assert client.get(f"/doctors/{doctor_id}").status_code == 200
    filtered = client.get(
        "/doctors", params={"department_id": _param("/doctors", "get", "department_id")}
    )
    assert filtered.status_code == 200
    assert filtered.json() != []
    slots_doctor = _param("/doctors/{doctor_id}/slots", "get", "doctor_id")
    date = _param("/doctors/{doctor_id}/slots", "get", "date")
    assert client.get(f"/doctors/{slots_doctor}/slots", params={"date": date}).status_code == 200


def test_booking_examples_are_accepted_by_the_clean_sandbox(client: TestClient) -> None:
    created = client.post("/appointments", json=_body("AppointmentCreate"), headers=ALICE)
    assert created.status_code == 201, created.text


def test_appointment_example_is_an_appointment_of_the_default_patient(
    client: TestClient,
) -> None:
    patch_id = _param("/appointments/{appointment_id}", "patch", "appointment_id")
    rescheduled = client.patch(
        f"/appointments/{patch_id}", json=_body("AppointmentReschedule"), headers=ALICE
    )
    assert rescheduled.status_code == 200, rescheduled.text
    delete_id = _param("/appointments/{appointment_id}", "delete", "appointment_id")
    assert client.delete(f"/appointments/{delete_id}", headers=ALICE).status_code == 204
