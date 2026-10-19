"""Tests confirming seeded bug toggles in appointment-booking benchmark (Task S8-03).

Proves that when each bug toggle (BUG-023 through BUG-032) is activated,
the API exhibits the specific faulty behavior contrasting with the clean baseline.
"""

import json
import os
import re
from pathlib import Path
from typing import Any

import jsonschema
import pytest
import yaml
from fastapi.testclient import TestClient

from app.config import Settings, get_settings, is_bug_active, settings
from app.store import store

DSL_SCHEMA_RELATIVE = Path("docs") / "contracts" / "test-dsl.schema.json"


def find_dsl_schema(start: Path | None = None) -> Path:
    """Locate the shared DSL schema by walking up from this file.

    Regression (S10-01 audit r2): indexing a fixed ancestor of this file raised IndexError
    when the benchmark is mounted at /app in Docker (`make test-bench`). Compose mounts
    ../docs at /docs, which the walk reaches through the filesystem root.
    """
    origin = (start or Path(__file__)).resolve()
    for directory in origin.parents:
        candidate = directory / DSL_SCHEMA_RELATIVE
        if candidate.is_file():
            return candidate
    pytest.fail(f"{DSL_SCHEMA_RELATIVE} not found above {origin}; mount ../docs at /docs")


def test_find_dsl_schema_handles_shallow_docker_layout(tmp_path: Path) -> None:
    """A test file at <root>/app/tests/x.py (like /app/tests in Docker) must not IndexError."""
    test_file = tmp_path / "app" / "tests" / "test_x.py"
    test_file.parent.mkdir(parents=True)
    test_file.write_text("", encoding="utf-8")
    schema = tmp_path / DSL_SCHEMA_RELATIVE
    schema.parent.mkdir(parents=True)
    schema.write_text("{}", encoding="utf-8")

    assert find_dsl_schema(test_file) == schema


ALICE_HEADERS = {"Authorization": "Bearer patient-alice-token"}
BOB_HEADERS = {"Authorization": "Bearer patient-bob-token"}


def test_ground_truth_file_validity() -> None:
    """Verify ground-truth.yaml exists and contains all 10 bug definitions."""
    gt_path = Path(__file__).resolve().parents[1] / "ground-truth.yaml"
    assert gt_path.exists(), f"ground-truth.yaml missing at {gt_path}"

    with gt_path.open("r", encoding="utf-8") as f:
        data: dict[str, Any] = yaml.safe_load(f)

    assert data.get("benchmark") == "appointment-booking"
    assert data.get("total_bugs") == 10

    bugs = data.get("bugs", [])
    assert len(bugs) == 10

    expected_codes = [f"BUG-{i:03d}" for i in range(23, 33)]
    actual_codes = [b.get("code") for b in bugs]
    assert actual_codes == expected_codes

    valid_categories = {
        "VALIDATION_MISSING",
        "STATUS_CODE_WRONG",
        "SCHEMA_MISMATCH",
        "BOUNDARY_ERROR",
        "BUSINESS_RULE_VIOLATION",
        "SEQUENCE_ERROR",
        "AUTH_ERROR",
    }
    for b in bugs:
        category = b.get("category")
        assert category in valid_categories, f"Bug {b.get('code')} has invalid category: {category}"

    # Category alignment with docs/architecture/experiment-design.md
    assert bugs[0]["category"] == "BOUNDARY_ERROR"  # BUG-023
    assert bugs[1]["category"] == "BUSINESS_RULE_VIOLATION"  # BUG-024
    assert bugs[2]["category"] == "BUSINESS_RULE_VIOLATION"  # BUG-025
    assert bugs[3]["category"] == "STATUS_CODE_WRONG"  # BUG-026
    assert bugs[4]["category"] == "BUSINESS_RULE_VIOLATION"  # BUG-027
    assert bugs[5]["category"] == "BUSINESS_RULE_VIOLATION"  # BUG-028
    assert bugs[6]["category"] == "SCHEMA_MISMATCH"  # BUG-029
    assert bugs[7]["category"] == "AUTH_ERROR"  # BUG-030
    assert bugs[8]["category"] == "STATUS_CODE_WRONG"  # BUG-031
    assert bugs[9]["category"] == "VALIDATION_MISSING"  # BUG-032


def test_ground_truth_oracle_dsl_schema_and_openapi_methods() -> None:
    """Validate all oracle assertions against test-dsl JSON Schema and check OpenAPI methods."""
    base_dir = Path(__file__).resolve().parents[1]
    gt_path = base_dir / "ground-truth.yaml"
    openapi_path = base_dir / "openapi.yaml"
    dsl_schema_path = find_dsl_schema()

    with gt_path.open("r", encoding="utf-8") as f:
        gt_data: dict[str, Any] = yaml.safe_load(f)

    with openapi_path.open("r", encoding="utf-8") as f:
        spec: dict[str, Any] = yaml.safe_load(f)

    with dsl_schema_path.open("r", encoding="utf-8") as f:
        dsl_schema: dict[str, Any] = json.load(f)

    assertion_schema = {
        "$schema": dsl_schema.get("$schema", "https://json-schema.org/draft/2020-12/schema"),
        "$defs": dsl_schema.get("$defs", {}),
        "$ref": "#/$defs/assertion",
    }
    dsl_assertion_validator = jsonschema.Draft202012Validator(assertion_schema)
    spec_paths = spec.get("paths", {})

    for bug in gt_data.get("bugs", []):
        code = bug["code"]
        method = bug["method"].lower()
        path = bug["path"]

        # 1. Path must exist in OpenAPI spec
        assert path in spec_paths, f"Bug {code}: Path '{path}' not found in openapi.yaml"

        # 2. HTTP Method must exist under that path in OpenAPI spec
        assert method in spec_paths[path], (
            f"Bug {code}: Method '{method.upper()}' not found under '{path}' in openapi.yaml"
        )

        # 3. Oracle assertion must validate strictly against canonical test-dsl schema
        oracle = bug.get("oracle_assertion", {})
        try:
            dsl_assertion_validator.validate(oracle)
        except jsonschema.ValidationError as err:
            pytest.fail(
                f"Bug {code}: Oracle assertion violated canonical DSL schema: {err.message}"
            )


def _evaluate_oracle(oracle: dict[str, Any], resp: Any, openapi_spec: dict[str, Any]) -> bool:
    """Evaluate whether an HTTP response passes a given oracle assertion."""
    o_type = oracle.get("type")
    if o_type == "status":
        return resp.status_code == oracle.get("equals")

    if o_type == "json_path":
        path = oracle.get("path")
        op = oracle.get("op")
        val = oracle.get("value")
        try:
            body = resp.json()
        except Exception:
            return False
        if path == "$[0].start_time" and op == "matches":
            if isinstance(body, list) and len(body) > 0 and isinstance(body[0], dict):
                pattern = str(val)
                return bool(re.search(pattern, str(body[0].get("start_time", ""))))
            return False
        return False

    if o_type == "json_schema":
        ref = oracle.get("schema_ref")
        try:
            body = resp.json()
        except Exception:
            return False
        if ref and ref.startswith("#/components/schemas/"):
            schema_name = ref.split("/")[-1]
            schemas = openapi_spec.get("components", {}).get("schemas", {})
            target_schema = schemas.get(schema_name)
            if target_schema:
                schema_str = json.dumps(schemas).replace("#/components/schemas/", "#/$defs/")
                defs = json.loads(schema_str)
                schema_doc = {
                    "$schema": "https://json-schema.org/draft/2020-12/schema",
                    "$defs": defs,
                    **defs[schema_name],
                }
                try:
                    jsonschema.Draft202012Validator(schema_doc).validate(body)
                    return True
                except jsonschema.ValidationError:
                    return False
        return False

    return False


def _run_witness_request(client: TestClient, bug_code: str) -> Any:
    """Execute the canonical witness HTTP request for a given bug."""
    if bug_code == "BUG-023":
        # Date in the past (2025-01-01 < 2026-10-10)
        return client.post(
            "/appointments",
            json={
                "doctor_id": 101,
                "appointment_date": "2025-01-01",
                "slot_id": "SLOT-0900",
                "patient_phone": "+1234567890",
            },
            headers=ALICE_HEADERS,
        )

    if bug_code == "BUG-024":
        # Double booking on already occupied slot (Alice already has 501 on 2026-10-15 SLOT-0900)
        return client.post(
            "/appointments",
            json={
                "doctor_id": 101,
                "appointment_date": "2026-10-15",
                "slot_id": "SLOT-0900",
                "patient_phone": "+1987654321",
            },
            headers=BOB_HEADERS,
        )

    if bug_code == "BUG-025":
        # Booking on a closed Sunday (2026-10-11)
        return client.post(
            "/appointments",
            json={
                "doctor_id": 101,
                "appointment_date": "2026-10-11",
                "slot_id": "SLOT-0900",
                "patient_phone": "+1234567890",
            },
            headers=ALICE_HEADERS,
        )

    if bug_code == "BUG-026":
        # Slot conflict returning 500 instead of 409
        return client.post(
            "/appointments",
            json={
                "doctor_id": 101,
                "appointment_date": "2026-10-15",
                "slot_id": "SLOT-0900",
                "patient_phone": "+1987654321",
            },
            headers=BOB_HEADERS,
        )

    if bug_code == "BUG-027":
        # Reschedule appointment 502 (< 24h away) to a valid working day (2026-10-19)
        return client.patch(
            "/appointments/502",
            json={"new_date": "2026-10-19", "new_slot_id": "SLOT-1000"},
            headers=BOB_HEADERS,
        )

    if bug_code == "BUG-028":
        # Doctor quota exceeded: fill 20 standard slots on 2026-10-26 via public API
        slots = [
            "SLOT-0800",
            "SLOT-0830",
            "SLOT-0900",
            "SLOT-0930",
            "SLOT-1000",
            "SLOT-1030",
            "SLOT-1100",
            "SLOT-1130",
            "SLOT-1300",
            "SLOT-1330",
            "SLOT-1400",
            "SLOT-1430",
            "SLOT-1500",
            "SLOT-1530",
            "SLOT-1600",
            "SLOT-1630",
            "SLOT-1700",
            "SLOT-1730",
            "SLOT-1800",
            "SLOT-1830",
        ]
        for s in slots:
            client.post(
                "/appointments",
                json={
                    "doctor_id": 101,
                    "appointment_date": "2026-10-26",
                    "slot_id": s,
                    "patient_phone": "+1234567890",
                },
                headers=ALICE_HEADERS,
            )
        # 21st attempt: SLOT-1900 is unbooked, valid slot
        return client.post(
            "/appointments",
            json={
                "doctor_id": 101,
                "appointment_date": "2026-10-26",
                "slot_id": "SLOT-1900",
                "patient_phone": "+1234567890",
            },
            headers=ALICE_HEADERS,
        )

    if bug_code == "BUG-029":
        # Slot start_time schema format
        return client.get("/doctors/101/slots?date=2026-10-15")

    if bug_code == "BUG-030":
        # Alice tries to cancel Bob's appointment 502
        return client.delete("/appointments/502", headers=ALICE_HEADERS)

    if bug_code == "BUG-031":
        # Invalid department 999
        return client.get("/departments/999")

    if bug_code == "BUG-032":
        # Invalid phone format
        return client.post(
            "/appointments",
            json={
                "doctor_id": 101,
                "appointment_date": "2026-10-16",
                "slot_id": "SLOT-1000",
                "patient_phone": "invalid-phone",
            },
            headers=ALICE_HEADERS,
        )

    raise ValueError(f"Unknown bug code: {bug_code}")


@pytest.mark.parametrize("bug_code", [f"BUG-{i:03d}" for i in range(23, 33)])
def test_oracle_witness_clean_pass_and_mutant_fail(
    client: TestClient, clean_baseline: None, bug_code: str
) -> None:
    """Verify each oracle PASSES on clean baseline and FAILS when bug is activated (20 cases)."""
    base_dir = Path(__file__).resolve().parents[1]
    with (base_dir / "ground-truth.yaml").open("r", encoding="utf-8") as f:
        gt_data: dict[str, Any] = yaml.safe_load(f)
    with (base_dir / "openapi.yaml").open("r", encoding="utf-8") as f:
        spec: dict[str, Any] = yaml.safe_load(f)

    bug_meta = next(b for b in gt_data["bugs"] if b["code"] == bug_code)
    oracle = bug_meta["oracle_assertion"]
    toggle_attr = bug_meta["toggle_env"].lower()

    # 1. Clean run: Oracle MUST PASS
    store.seed_defaults()
    setattr(settings, toggle_attr, False)
    clean_resp = _run_witness_request(client, bug_code)
    assert _evaluate_oracle(oracle, clean_resp, spec) is True, (
        f"Bug {bug_code}: Oracle expected to PASS on clean baseline but failed."
    )

    # 2. Mutant run: Oracle MUST FAIL
    store.seed_defaults()
    setattr(settings, toggle_attr, True)
    mutant_resp = _run_witness_request(client, bug_code)
    assert _evaluate_oracle(oracle, mutant_resp, spec) is False, (
        f"Bug {bug_code}: Oracle expected to FAIL on seeded mutant but passed."
    )


def test_toggle_switching_in_same_process(client: TestClient, clean_baseline: None) -> None:
    """Verify switching a toggle from '1' to '0' dynamically turns off the bug in-process."""
    payload = {
        "doctor_id": 101,
        "appointment_date": "2025-01-01",
        "slot_id": "SLOT-0900",
        "patient_phone": "+1234567890",
    }

    # 1. Turn on via os.environ:
    os.environ["BUG_023"] = "1"
    assert is_bug_active("BUG_023") is True
    resp_on = client.post("/appointments", json=payload, headers=ALICE_HEADERS)
    assert resp_on.status_code == 201

    # 2. Switch to '0' in the same process:
    os.environ["BUG_023"] = "0"
    assert is_bug_active("BUG_023") is False
    resp_off = client.post("/appointments", json=payload, headers=ALICE_HEADERS)
    assert resp_off.status_code == 422

    # 3. Switch back to '1':
    store.seed_defaults()
    os.environ["BUG_023"] = "1"
    assert is_bug_active("BUG_023") is True
    resp_on2 = client.post("/appointments", json=payload, headers=ALICE_HEADERS)
    assert resp_on2.status_code == 201

    # 4. Remove from os.environ:
    del os.environ["BUG_023"]
    assert is_bug_active("BUG_023") is False
    resp_off2 = client.post("/appointments", json=payload, headers=ALICE_HEADERS)
    assert resp_off2.status_code == 422


def test_environment_debug_variable_immunity(monkeypatch: pytest.MonkeyPatch) -> None:
    """Ensure non-boolean local DEBUG env (e.g. DEBUG=release) does not crash config."""
    monkeypatch.setenv("DEBUG", "release")
    test_settings = Settings()
    assert test_settings.app_name == "Appointment Booking Benchmark API"
    snapshot = get_settings()
    assert snapshot.bug_023 is False


def test_bug_023_booking_in_the_past(client: TestClient, clean_baseline: None) -> None:
    """BUG-023: Allows booking appointment in the past (< 2026-10-10)."""
    payload = {
        "doctor_id": 101,
        "appointment_date": "2025-01-01",
        "slot_id": "SLOT-0900",
        "patient_phone": "+1234567890",
    }

    # 1. Clean behavior: 422 Unprocessable Entity
    settings.bug_023 = False
    clean_resp = client.post("/appointments", json=payload, headers=ALICE_HEADERS)
    assert clean_resp.status_code == 422

    # 2. Bug behavior: 201 Created accepting past date
    settings.bug_023 = True
    bug_resp = client.post("/appointments", json=payload, headers=ALICE_HEADERS)
    assert bug_resp.status_code == 201


def test_bug_024_double_booking(client: TestClient, clean_baseline: None) -> None:
    """BUG-024: Allows double booking for same doctor and same time slot."""
    payload = {
        "doctor_id": 101,
        "appointment_date": "2026-10-15",
        "slot_id": "SLOT-0900",
        "patient_phone": "+1987654321",
    }

    # 1. Clean behavior: 409 Conflict
    settings.bug_024 = False
    clean_resp = client.post("/appointments", json=payload, headers=BOB_HEADERS)
    assert clean_resp.status_code == 409

    # 2. Bug behavior: 201 Created accepting double booking
    settings.bug_024 = True
    bug_resp = client.post("/appointments", json=payload, headers=BOB_HEADERS)
    assert bug_resp.status_code == 201


def test_bug_025_booking_on_closed_day(client: TestClient, clean_baseline: None) -> None:
    """BUG-025: Allows booking on clinic closed days (Sunday)."""
    payload = {
        "doctor_id": 101,
        "appointment_date": "2026-10-11",
        "slot_id": "SLOT-0900",
        "patient_phone": "+1234567890",
    }

    # 1. Clean behavior: 409 Conflict (CLINIC_CLOSED)
    settings.bug_025 = False
    clean_resp = client.post("/appointments", json=payload, headers=ALICE_HEADERS)
    assert clean_resp.status_code == 409

    # 2. Bug behavior: 201 Created allowing Sunday booking
    settings.bug_025 = True
    bug_resp = client.post("/appointments", json=payload, headers=ALICE_HEADERS)
    assert bug_resp.status_code == 201


def test_bug_026_slot_conflict_status_code(client: TestClient, clean_baseline: None) -> None:
    """BUG-026: Slot conflict returns 500 instead of 409."""
    payload = {
        "doctor_id": 101,
        "appointment_date": "2026-10-15",
        "slot_id": "SLOT-0900",
        "patient_phone": "+1987654321",
    }

    # 1. Clean behavior: 409 Conflict
    settings.bug_026 = False
    clean_resp = client.post("/appointments", json=payload, headers=BOB_HEADERS)
    assert clean_resp.status_code == 409

    # 2. Bug behavior: 500 Internal Server Error
    settings.bug_026 = True
    bug_resp = client.post("/appointments", json=payload, headers=BOB_HEADERS)
    assert bug_resp.status_code == 500


def test_bug_027_reschedule_within_24_hours(client: TestClient, clean_baseline: None) -> None:
    """BUG-027: Allows rescheduling when remaining time is < 24 hours."""
    payload = {"new_date": "2026-10-19", "new_slot_id": "SLOT-1000"}

    # 1. Clean behavior: 409 Conflict (RESCHEDULE_WINDOW_EXPIRED)
    settings.bug_027 = False
    clean_resp = client.patch("/appointments/502", json=payload, headers=BOB_HEADERS)
    assert clean_resp.status_code == 409

    # 2. Bug behavior: 200 OK allowing reschedule
    settings.bug_027 = True
    bug_resp = client.patch("/appointments/502", json=payload, headers=BOB_HEADERS)
    assert bug_resp.status_code == 200


def test_bug_028_doctor_quota_exceeded(client: TestClient, clean_baseline: None) -> None:
    """BUG-028: Allows doctor appointments to exceed quota of 20."""
    slots = [
        "SLOT-0800",
        "SLOT-0830",
        "SLOT-0900",
        "SLOT-0930",
        "SLOT-1000",
        "SLOT-1030",
        "SLOT-1100",
        "SLOT-1130",
        "SLOT-1300",
        "SLOT-1330",
        "SLOT-1400",
        "SLOT-1430",
        "SLOT-1500",
        "SLOT-1530",
        "SLOT-1600",
        "SLOT-1630",
        "SLOT-1700",
        "SLOT-1730",
        "SLOT-1800",
        "SLOT-1830",
    ]
    for s in slots:
        r = client.post(
            "/appointments",
            json={
                "doctor_id": 101,
                "appointment_date": "2026-10-26",
                "slot_id": s,
                "patient_phone": "+1234567890",
            },
            headers=ALICE_HEADERS,
        )
        assert r.status_code == 201

    payload = {
        "doctor_id": 101,
        "appointment_date": "2026-10-26",
        "slot_id": "SLOT-1900",
        "patient_phone": "+1234567890",
    }

    # 1. Clean behavior: 409 Conflict (DOCTOR_QUOTA_EXCEEDED)
    settings.bug_028 = False
    clean_resp = client.post("/appointments", json=payload, headers=ALICE_HEADERS)
    assert clean_resp.status_code == 409

    # 2. Bug behavior: 201 Created allowing quota exceed
    settings.bug_028 = True
    bug_resp = client.post("/appointments", json=payload, headers=ALICE_HEADERS)
    assert bug_resp.status_code == 201


def test_bug_029_slots_datetime_format(client: TestClient, clean_baseline: None) -> None:
    """BUG-029: Time slots return invalid non-ISO 8601 datetime format."""
    # 1. Clean behavior: start_time contains ISO 8601 'T' separator
    settings.bug_029 = False
    clean_resp = client.get("/doctors/101/slots?date=2026-10-15")
    assert clean_resp.status_code == 200
    assert "T" in clean_resp.json()[0]["start_time"]

    # 2. Bug behavior: start_time formatted as '09:00 AM 2026/10/15'
    settings.bug_029 = True
    bug_resp = client.get("/doctors/101/slots?date=2026-10-15")
    assert bug_resp.status_code == 200
    assert " AM " in bug_resp.json()[0]["start_time"]


def test_bug_030_idor_cancel_appointment(client: TestClient, clean_baseline: None) -> None:
    """BUG-030: IDOR allows Patient Alice to cancel Patient Bob's appointment."""
    # 1. Clean behavior: 403 Forbidden
    settings.bug_030 = False
    clean_resp = client.delete("/appointments/502", headers=ALICE_HEADERS)
    assert clean_resp.status_code == 403

    # 2. Bug behavior: 204 No Content allowing unauthorized cancellation
    settings.bug_030 = True
    bug_resp = client.delete("/appointments/502", headers=ALICE_HEADERS)
    assert bug_resp.status_code == 204


def test_bug_031_department_html_status_code(client: TestClient, clean_baseline: None) -> None:
    """BUG-031: Non-existent department ID returns 400 with text/html."""
    # 1. Clean behavior: 404 Not Found with JSON
    settings.bug_031 = False
    clean_resp = client.get("/departments/999")
    assert clean_resp.status_code == 404
    assert "application/json" in clean_resp.headers["content-type"]

    # 2. Bug behavior: 400 Bad Request with text/html
    settings.bug_031 = True
    bug_resp = client.get("/departments/999")
    assert bug_resp.status_code == 400
    assert "text/html" in bug_resp.headers["content-type"]


def test_bug_032_phone_number_validation_missing(client: TestClient, clean_baseline: None) -> None:
    """BUG-032: Fails to validate phone number format."""
    payload = {
        "doctor_id": 101,
        "appointment_date": "2026-10-16",
        "slot_id": "SLOT-1000",
        "patient_phone": "invalid-phone",
    }

    # 1. Clean behavior: 422 Unprocessable Entity
    settings.bug_032 = False
    clean_resp = client.post("/appointments", json=payload, headers=ALICE_HEADERS)
    assert clean_resp.status_code == 422

    # 2. Bug behavior: 201 Created accepting arbitrary phone
    settings.bug_032 = True
    bug_resp = client.post("/appointments", json=payload, headers=ALICE_HEADERS)
    assert bug_resp.status_code == 201


# ==============================================================================
# Regressions for Baseline Reschedule, Calendar Validation & PHP Oracle (PR #89 review)
# ==============================================================================


def test_baseline_reschedule_conflict_rejected(client: TestClient, clean_baseline: None) -> None:
    """Finding 1: Reschedule rejects target slot if already booked by another appointment."""
    # Alice books doctor 101, 2026-10-16, SLOT-1000
    create_resp = client.post(
        "/appointments",
        json={
            "doctor_id": 101,
            "appointment_date": "2026-10-16",
            "slot_id": "SLOT-1000",
            "patient_phone": "+1234567890",
        },
        headers=ALICE_HEADERS,
    )
    assert create_resp.status_code == 201

    # Bob has existing appointment (let's create one for Bob on 2026-10-19 SLOT-1000)
    bob_create = client.post(
        "/appointments",
        json={
            "doctor_id": 101,
            "appointment_date": "2026-10-19",
            "slot_id": "SLOT-1000",
            "patient_phone": "+1987654321",
        },
        headers=BOB_HEADERS,
    )
    assert bob_create.status_code == 201
    bob_app_id = bob_create.json()["id"]

    # Bob tries to reschedule to 2026-10-16 SLOT-1000 (already booked by Alice)
    resched_resp = client.patch(
        f"/appointments/{bob_app_id}",
        json={"new_date": "2026-10-16", "new_slot_id": "SLOT-1000"},
        headers=BOB_HEADERS,
    )
    assert resched_resp.status_code == 409
    assert resched_resp.json()["detail"]["code"] == "SLOT_ALREADY_BOOKED"

    # Verify Bob's appointment state is preserved
    bob_check = client.get("/appointments", headers=BOB_HEADERS)
    matching = [a for a in bob_check.json() if a["id"] == bob_app_id]
    assert len(matching) == 1
    assert matching[0]["appointment_date"] == "2026-10-19"
    assert matching[0]["slot_id"] == "SLOT-1000"


def test_baseline_reschedule_closed_days_rejected(client: TestClient, clean_baseline: None) -> None:
    """Finding 1: Reschedule rejects Sundays and public holidays (CLINIC_CLOSED)."""
    # 501: Alice on 2026-10-15 SLOT-0900
    # Try Sunday 2026-10-11
    resp_sunday = client.patch(
        "/appointments/501",
        json={"new_date": "2026-10-11", "new_slot_id": "SLOT-1000"},
        headers=ALICE_HEADERS,
    )
    assert resp_sunday.status_code == 409
    assert resp_sunday.json()["detail"]["code"] == "CLINIC_CLOSED"

    # Try holiday 2026-12-25
    resp_holiday = client.patch(
        "/appointments/501",
        json={"new_date": "2026-12-25", "new_slot_id": "SLOT-1000"},
        headers=ALICE_HEADERS,
    )
    assert resp_holiday.status_code == 409
    assert resp_holiday.json()["detail"]["code"] == "CLINIC_CLOSED"

    # Verify state of 501 preserved
    check = client.get("/appointments", headers=ALICE_HEADERS)
    app_501 = next(a for a in check.json() if a["id"] == 501)
    assert app_501["appointment_date"] == "2026-10-15"
    assert app_501["slot_id"] == "SLOT-0900"


def test_baseline_reschedule_doctor_quota_enforced(
    client: TestClient, clean_baseline: None
) -> None:
    """Finding 1: Reschedule respects doctor daily quota of 20 on destination date."""
    # Fill 20 slots on 2026-10-26 (Monday) for doctor 101
    slots = [
        "SLOT-0800",
        "SLOT-0830",
        "SLOT-0900",
        "SLOT-0930",
        "SLOT-1000",
        "SLOT-1030",
        "SLOT-1100",
        "SLOT-1130",
        "SLOT-1300",
        "SLOT-1330",
        "SLOT-1400",
        "SLOT-1430",
        "SLOT-1500",
        "SLOT-1530",
        "SLOT-1600",
        "SLOT-1630",
        "SLOT-1700",
        "SLOT-1730",
        "SLOT-1800",
        "SLOT-1830",
    ]
    for s in slots:
        r = client.post(
            "/appointments",
            json={
                "doctor_id": 101,
                "appointment_date": "2026-10-26",
                "slot_id": s,
                "patient_phone": "+1234567890",
            },
            headers=ALICE_HEADERS,
        )
        assert r.status_code == 201

    # Now attempt to reschedule appointment 501 (which is on 2026-10-15) to 2026-10-26 SLOT-1900
    resched_resp = client.patch(
        "/appointments/501",
        json={"new_date": "2026-10-26", "new_slot_id": "SLOT-1900"},
        headers=ALICE_HEADERS,
    )
    assert resched_resp.status_code == 409
    assert resched_resp.json()["detail"]["code"] == "DOCTOR_QUOTA_EXCEEDED"

    # State preserved
    check = client.get("/appointments", headers=ALICE_HEADERS)
    app_501 = next(a for a in check.json() if a["id"] == 501)
    assert app_501["appointment_date"] == "2026-10-15"


def test_baseline_reschedule_same_slot_same_day_no_self_conflict(
    client: TestClient, clean_baseline: None
) -> None:
    """Finding 1: Rescheduling to same slot or same day does not self-conflict."""
    # Appointment 501 is on 2026-10-15 SLOT-0900. Alice updates notes while keeping same slot.
    resp = client.patch(
        "/appointments/501",
        json={
            "new_date": "2026-10-15",
            "new_slot_id": "SLOT-0900",
            "notes": "Updated notes without changing slot",
        },
        headers=ALICE_HEADERS,
    )
    assert resp.status_code == 200
    data = resp.json()
    assert data["appointment_date"] == "2026-10-15"
    assert data["slot_id"] == "SLOT-0900"
    assert data["notes"] == "Updated notes without changing slot"


@pytest.mark.parametrize("invalid_date", ["2026-13-01", "2026-11-31", "2027-02-29"])
def test_calendar_date_validation_rejected_422(
    client: TestClient, clean_baseline: None, invalid_date: str
) -> None:
    """Finding 2: Invalid calendar dates return 422 for POST, PATCH, and GET /slots."""
    # 1. POST /appointments
    post_resp = client.post(
        "/appointments",
        json={
            "doctor_id": 101,
            "appointment_date": invalid_date,
            "slot_id": "SLOT-1000",
            "patient_phone": "+1234567890",
        },
        headers=ALICE_HEADERS,
    )
    assert post_resp.status_code == 422, f"POST with {invalid_date} should return 422"

    # 2. PATCH /appointments/501
    patch_resp = client.patch(
        "/appointments/501",
        json={"new_date": invalid_date, "new_slot_id": "SLOT-1000"},
        headers=ALICE_HEADERS,
    )
    assert patch_resp.status_code == 422, f"PATCH with {invalid_date} should return 422"

    # 3. GET /doctors/101/slots?date=...
    get_resp = client.get(f"/doctors/101/slots?date={invalid_date}")
    assert get_resp.status_code == 422, f"GET slots with {invalid_date} should return 422"


def test_invalid_date_patch_preserves_state_and_allows_subsequent_patch(
    client: TestClient, clean_baseline: None
) -> None:
    """Finding 2: State preservation after invalid date PATCH and valid subsequent PATCH."""
    # Try invalid date PATCH
    fail_patch = client.patch(
        "/appointments/501",
        json={"new_date": "2026-13-01", "new_slot_id": "SLOT-1000"},
        headers=ALICE_HEADERS,
    )
    assert fail_patch.status_code == 422

    # Check 501 still in original state
    app_501 = store.appointments[501]
    assert app_501.appointment_date == "2026-10-15"
    assert app_501.slot_id == "SLOT-0900"

    # Subsequent PATCH with valid date succeeds without 500 error
    succ_patch = client.patch(
        "/appointments/501",
        json={"new_date": "2026-10-19", "new_slot_id": "SLOT-1000"},
        headers=ALICE_HEADERS,
    )
    assert succ_patch.status_code == 200
    assert succ_patch.json()["appointment_date"] == "2026-10-19"


def test_bug_023_only_bypasses_past_date_not_invalid_calendar_date(
    client: TestClient, clean_baseline: None
) -> None:
    """Finding 2: BUG-023 only allows past dates, does not allow non-existent calendar dates."""
    settings.bug_023 = True

    # Past date (valid calendar) -> accepted (201)
    past_resp = client.post(
        "/appointments",
        json={
            "doctor_id": 101,
            "appointment_date": "2025-01-01",
            "slot_id": "SLOT-1000",
            "patient_phone": "+1234567890",
        },
        headers=ALICE_HEADERS,
    )
    assert past_resp.status_code == 201

    # Invalid calendar date -> STILL rejected (422)
    invalid_resp = client.post(
        "/appointments",
        json={
            "doctor_id": 101,
            "appointment_date": "2026-13-01",
            "slot_id": "SLOT-1000",
            "patient_phone": "+1234567890",
        },
        headers=ALICE_HEADERS,
    )
    assert invalid_resp.status_code == 422
