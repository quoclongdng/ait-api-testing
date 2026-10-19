"""Live QA round 5 (S14-06): schema-level DSL errors name a step like the semantic errors."""

from __future__ import annotations

import copy
from typing import Any

from app.services.validator import DslValidator
from tests.test_generate import VALID_DSL_CASE


def _two_step_case() -> dict[str, Any]:
    case: dict[str, Any] = copy.deepcopy(VALID_DSL_CASE)
    second = copy.deepcopy(case["steps"][0])
    second["name"] = "read item"
    case["steps"].append(second)
    return case


def test_schema_errors_use_one_based_step_numbers_like_semantic_errors() -> None:
    case = _two_step_case()
    del case["steps"][1]["assertions"]
    errors = DslValidator().validate(case).errors

    assert errors == ["Bước 2 ('read item'): thiếu trường bắt buộc assertions"]
    assert not any("steps[" in error for error in errors)


def test_schema_errors_number_assertions_and_extracts_from_one() -> None:
    case = _two_step_case()
    case["steps"][1]["assertions"].append({"type": "status"})
    case["steps"][1]["extract"] = [{"var": "id", "from": "body", "path": "$.id"}, {"var": "x"}]
    errors = DslValidator().validate(case).errors

    assert "Bước 2 ('read item'), assertion #2: thiếu trường bắt buộc equals" in errors
    assert "Bước 2 ('read item'), extract #2: thiếu trường bắt buộc from" in errors


def test_schema_errors_keep_the_rest_of_the_path_and_handle_unnamed_steps() -> None:
    case = _two_step_case()
    case["steps"][0]["name"] = ""
    case["steps"][0]["request"]["headers"] = []
    errors = DslValidator().validate(case).errors

    assert "Bước 1, request.headers: sai kiểu dữ liệu: cần đối tượng, nhận mảng" in errors


def test_locations_outside_steps_are_unchanged() -> None:
    case = _two_step_case()
    case["dsl_version"] = 1
    del case["title"]
    errors = DslValidator().validate(case).errors

    assert any(error.startswith("dsl_version: ") for error in errors)
    assert "Gốc DSL: thiếu trường bắt buộc title" in errors
