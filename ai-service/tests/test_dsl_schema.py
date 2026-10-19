import json
from pathlib import Path
from typing import Any

import jsonschema
import pytest
from jsonschema.validators import validator_for

from tests.repo_files import REPO_ROOT

SCHEMA_FILE = REPO_ROOT / "docs" / "contracts" / "test-dsl.schema.json"
EXAMPLES_DIR = REPO_ROOT / "docs" / "contracts" / "examples"


@pytest.fixture(scope="session")
def dsl_schema() -> dict[str, Any]:
    assert SCHEMA_FILE.exists(), f"Schema file not found at {SCHEMA_FILE}"
    with open(SCHEMA_FILE, encoding="utf-8") as f:
        data = json.load(f)
        assert isinstance(data, dict)
        return data


@pytest.fixture(scope="session")
def validator(dsl_schema: dict[str, Any]) -> Any:
    cls = validator_for(dsl_schema)
    cls.check_schema(dsl_schema)
    return cls(dsl_schema)


def test_schema_itself_is_valid(dsl_schema: dict[str, Any]) -> None:
    """Verifies that test-dsl.schema.json is a valid JSON Schema."""
    cls = validator_for(dsl_schema)
    cls.check_schema(dsl_schema)


valid_example_paths = sorted(EXAMPLES_DIR.glob("valid-*.json"))
invalid_example_paths = sorted(EXAMPLES_DIR.glob("invalid-*.json"))


@pytest.mark.parametrize("example_path", valid_example_paths, ids=lambda p: p.name)
def test_valid_examples_pass_schema(validator: Any, example_path: Path) -> None:
    """All valid-*.json fixtures must successfully validate against the DSL schema."""
    with open(example_path, encoding="utf-8") as f:
        payload = json.load(f)
    validator.validate(payload)


@pytest.mark.parametrize("example_path", invalid_example_paths, ids=lambda p: p.name)
def test_invalid_examples_fail_schema(validator: Any, example_path: Path) -> None:
    """All invalid-*.json fixtures must trigger a ValidationError."""
    with open(example_path, encoding="utf-8") as f:
        payload = json.load(f)
    with pytest.raises(jsonschema.ValidationError):
        validator.validate(payload)
