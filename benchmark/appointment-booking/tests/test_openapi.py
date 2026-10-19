"""Tests verifying openapi.yaml specification validity and completeness."""

from pathlib import Path
from typing import Any

import yaml
from fastapi.testclient import TestClient


def test_openapi_file_and_fastapi_schema_match(client: TestClient) -> None:
    """Verify openapi.yaml exists and defines expected paths."""
    spec_path = Path(__file__).resolve().parents[1] / "openapi.yaml"
    assert spec_path.exists(), f"openapi.yaml missing at {spec_path}"

    with spec_path.open("r", encoding="utf-8") as f:
        spec: dict[str, Any] = yaml.safe_load(f)

    assert spec.get("openapi") == "3.1.0"
    paths = set(spec.get("paths", {}).keys())

    expected_paths = {
        "/health",
        "/departments",
        "/departments/{department_id}",
        "/doctors",
        "/doctors/{doctor_id}",
        "/doctors/{doctor_id}/slots",
        "/appointments",
        "/appointments/{appointment_id}",
    }
    for expected in expected_paths:
        assert expected in paths, f"Expected path {expected} missing in openapi.yaml"

    # Runtime app openapi
    runtime_spec = client.app.openapi()
    runtime_paths = set(runtime_spec.get("paths", {}).keys())
    assert expected_paths.issubset(runtime_paths)
