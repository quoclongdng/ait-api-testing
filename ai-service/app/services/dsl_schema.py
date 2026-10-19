import json
import os
from functools import lru_cache
from pathlib import Path
from typing import Any

from jsonschema.protocols import Validator
from jsonschema.validators import validator_for


def get_dsl_schema_path() -> Path:
    """Find the path to test-dsl.schema.json in development, test, or container layout."""
    env_path = os.environ.get("CONTRACTS_DIR")
    if env_path:
        p = Path(env_path) / "test-dsl.schema.json"
        if p.is_file():
            return p

    for container_candidate in [
        Path("/contracts/test-dsl.schema.json"),
        Path("/docs/contracts/test-dsl.schema.json"),
    ]:
        if container_candidate.is_file():
            return container_candidate

    repo_root = Path(__file__).resolve().parents[3]
    repo_root_candidate = repo_root / "docs" / "contracts" / "test-dsl.schema.json"
    if repo_root_candidate.is_file():
        return repo_root_candidate

    ai_service_root = Path(__file__).resolve().parents[2]
    ai_service_candidate = ai_service_root / "docs" / "contracts" / "test-dsl.schema.json"
    if ai_service_candidate.is_file():
        return ai_service_candidate

    raise FileNotFoundError("Could not find test-dsl.schema.json")


@lru_cache
def get_dsl_validator() -> Validator:
    schema_path = get_dsl_schema_path()
    with open(schema_path, encoding="utf-8") as f:
        schema = json.load(f)
    validator_cls = validator_for(schema)
    validator_cls.check_schema(schema)
    return validator_cls(schema)


def validate_dsl_schema(case: dict[str, Any]) -> list[str]:
    """Validate a DSL test case object against test-dsl.schema.json.

    Returns a list of error message strings. Empty list indicates valid.
    """
    validator = get_dsl_validator()
    errors: list[str] = []
    for err in sorted(validator.iter_errors(case), key=lambda e: str(e.path)):
        location = ".".join(str(p) for p in err.path) if err.path else "root"
        errors.append(f"[{location}] {err.message}")
    return errors
