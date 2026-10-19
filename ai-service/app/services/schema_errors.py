"""Vietnamese messages for jsonschema errors (parity with web DslSchemaErrorFormatter, S14-06).

The raw jsonschema text ("'assertions' is a required property", "... is not valid under any of
the given schemas") ends up in a case's validation_errors on M10/M11, where UI text is
Vietnamese (docs/DESIGN.md §8). Each message names the location in the document, e.g.
``steps[0].assertions[2]: thiếu trường bắt buộc op``.

For oneOf/anyOf only one branch is reported: the branch whose ``type`` const equals the data's
``type`` (an assertion ``{"type": "json_path"}`` is checked as a json_path assertion), otherwise
the branch with the fewest problems.
"""

from __future__ import annotations

import json
import re
from collections.abc import Iterable
from typing import Any

from jsonschema.exceptions import SchemaError, ValidationError

_SchemaIssue = ValidationError | SchemaError

_QUOTED = re.compile(r"'((?:[^'\\]|\\.)*)'")

_TYPE_NAMES = {
    "object": "đối tượng",
    "array": "mảng",
    "string": "chuỗi",
    "integer": "số nguyên",
    "number": "số",
    "boolean": "giá trị đúng/sai",
    "null": "null",
}


def format_schema_errors(
    errors: Iterable[_SchemaIssue],
    root_label: str = "Gốc DSL",
    prefix: str = "",
    dsl_document: Any = None,
) -> list[str]:
    """Vietnamese, de-duplicated messages for the given (top-level) validation errors.

    With ``dsl_document`` (the whole Test DSL being validated) a location inside a step names the
    step like the semantic errors, 1-based (S14-06): ``Bước 1 ('list items'), assertion #3``
    instead of the 0-based JSON path ``steps[0].assertions[2]``.
    """
    messages: list[str] = []
    for error in errors:
        for leaf in _leaves(error):
            location = _step_location(leaf, dsl_document) if dsl_document is not None else None
            if location is None:
                location = _location(leaf, root_label, prefix)
            message = f"{location}: {_describe(leaf)}"
            if message not in messages:
                messages.append(message)
    return messages


def _leaves(error: _SchemaIssue) -> list[_SchemaIssue]:
    children = list(error.context or [])
    if not children:
        return [error]
    if error.validator not in ("oneOf", "anyOf"):
        result: list[_SchemaIssue] = []
        for child in children:
            result.extend(_leaves(child))
        return result

    branches: dict[int, list[_SchemaIssue]] = {}
    for child in children:
        index = child.schema_path[0] if child.schema_path else 0
        branches.setdefault(index if isinstance(index, int) else 0, []).append(child)

    discriminator = _discriminator(error)
    if discriminator is not None:
        for index, branch_errors in branches.items():
            if _branch_type(error, index) == discriminator:
                result = []
                for branch_error in branch_errors:
                    result.extend(_leaves(branch_error))
                return result
        if _allowed_types(error):
            return [error]

    best: list[_SchemaIssue] = []
    best_score: tuple[int, int, int] | None = None
    for branch_errors in branches.values():
        leaves: list[_SchemaIssue] = []
        for branch_error in branch_errors:
            leaves.extend(_leaves(branch_error))
        depth = max((len(leaf.absolute_path) for leaf in leaves), default=0)
        rejected_on_const = any(leaf.validator == "const" for leaf in leaves)
        score = (0 if rejected_on_const else 1, -len(leaves), depth)
        if best_score is None or score > best_score:
            best, best_score = leaves, score
    return best or [error]


def _discriminator(error: _SchemaIssue) -> str | None:
    instance = error.instance
    if isinstance(instance, dict) and isinstance(instance.get("type"), str):
        return str(instance["type"])
    return None


def _branch_type(error: _SchemaIssue, index: int) -> str | None:
    branches = error.validator_value
    if not isinstance(branches, list) or not 0 <= index < len(branches):
        return None
    branch = branches[index]
    if not isinstance(branch, dict):
        return None
    properties = branch.get("properties")
    type_schema = properties.get("type") if isinstance(properties, dict) else None
    const = type_schema.get("const") if isinstance(type_schema, dict) else None
    return const if isinstance(const, str) else None


def _allowed_types(error: _SchemaIssue) -> list[str]:
    branches = error.validator_value
    if not isinstance(branches, list):
        return []
    types: list[str] = []
    for index in range(len(branches)):
        branch_type = _branch_type(error, index)
        if branch_type is not None and branch_type not in types:
            types.append(branch_type)
    return types


def _location(error: _SchemaIssue, root_label: str, prefix: str) -> str:
    location = prefix
    for segment in error.absolute_path:
        if isinstance(segment, int):
            location += f"[{segment}]"
        else:
            location += ("" if location == "" else ".") + str(segment)
    return root_label if location == prefix else location


_ITEM_LABELS = {"assertions": "assertion", "extract": "extract"}


def _step_location(error: _SchemaIssue, document: Any) -> str | None:
    """``Bước 2 ('create order'), assertion #1, schema`` for steps[1].assertions[0].schema.

    The step and the assertion/extract numbers are 1-based like the semantic errors, the rest of
    the path is kept as is (web DslSchemaErrorFormatter parity). None outside a step.
    """
    path = list(error.absolute_path)
    if len(path) < 2 or path[0] != "steps" or not isinstance(path[1], int):
        return None
    index = path[1]
    steps = document.get("steps") if isinstance(document, dict) else None
    step = steps[index] if isinstance(steps, list) and 0 <= index < len(steps) else None
    name = step.get("name") if isinstance(step, dict) else None

    parts = [f"Bước {index + 1}" + (f" ('{name}')" if isinstance(name, str) and name else "")]
    rest = path[2:]
    if len(rest) >= 2 and rest[0] in _ITEM_LABELS and isinstance(rest[1], int):
        parts.append(f"{_ITEM_LABELS[str(rest[0])]} #{rest[1] + 1}")
        rest = rest[2:]

    tail = ""
    for segment in rest:
        if isinstance(segment, int):
            tail += f"[{segment}]"
        else:
            tail += ("" if tail == "" else ".") + str(segment)
    if tail:
        parts.append(tail)
    return ", ".join(parts)


def _scalar(value: Any) -> str:
    if isinstance(value, bool):
        return "true" if value else "false"
    if isinstance(value, str | int | float):
        return str(value)
    return json.dumps(value, ensure_ascii=False)


def _type_name(value: Any) -> str:
    types = value if isinstance(value, list) else [value]
    return " hoặc ".join(
        _TYPE_NAMES.get(item, item) if isinstance(item, str) else "?" for item in types
    )


def _json_type(value: Any) -> str:
    if value is None:
        return "null"
    if isinstance(value, bool):
        return "boolean"
    if isinstance(value, int):
        return "integer"
    if isinstance(value, float):
        return "number"
    if isinstance(value, str):
        return "string"
    if isinstance(value, list):
        return "array"
    return "object"


def _quoted_names(message: str) -> str:
    names = _QUOTED.findall(message)
    return ", ".join(names) if names else "?"


def _describe(error: _SchemaIssue) -> str:
    keyword = error.validator
    value = error.validator_value
    if keyword == "required":
        return "thiếu trường bắt buộc " + _quoted_names(error.message)
    if keyword in ("additionalProperties", "unevaluatedProperties"):
        return "có trường không được phép: " + _quoted_names(error.message)
    if keyword == "type":
        return (
            f"sai kiểu dữ liệu: cần {_type_name(value)}, "
            f"nhận {_type_name(_json_type(error.instance))}"
        )
    if keyword == "oneOf" or keyword == "anyOf":
        discriminator = _discriminator(error)
        allowed = _allowed_types(error)
        if discriminator is not None and allowed:
            return f'loại "{discriminator}" không hợp lệ, chỉ chấp nhận: {", ".join(allowed)}'
        return "không khớp với dạng nào được phép"
    if keyword == "enum" and isinstance(error.instance, str | int | float | bool):
        received = json.dumps(error.instance, ensure_ascii=False)
        return f"giá trị {received} không thuộc danh sách cho phép"
    simple = {
        "enum": "giá trị không thuộc danh sách cho phép",
        "const": f"giá trị phải là {_scalar(value)}",
        "minLength": f"chuỗi phải có ít nhất {_scalar(value)} ký tự",
        "maxLength": f"chuỗi không được dài quá {_scalar(value)} ký tự",
        "minimum": f"giá trị phải lớn hơn hoặc bằng {_scalar(value)}",
        "maximum": f"giá trị phải nhỏ hơn hoặc bằng {_scalar(value)}",
        "exclusiveMinimum": f"giá trị phải lớn hơn {_scalar(value)}",
        "exclusiveMaximum": f"giá trị phải nhỏ hơn {_scalar(value)}",
        "minItems": f"mảng phải có ít nhất {_scalar(value)} phần tử",
        "maxItems": f"mảng không được có quá {_scalar(value)} phần tử",
        "uniqueItems": "các phần tử của mảng phải khác nhau",
        "minProperties": f"đối tượng phải có ít nhất {_scalar(value)} trường",
        "maxProperties": f"đối tượng không được có quá {_scalar(value)} trường",
        "pattern": "chuỗi không đúng định dạng yêu cầu",
        "format": f"giá trị không đúng định dạng {_scalar(value)}",
        "propertyNames": "tên trường không hợp lệ",
        "not": "giá trị không được phép",
        "false": "dữ liệu không được phép",
    }
    if keyword in simple:
        return simple[keyword]
    if keyword is None or keyword == "":
        return "dữ liệu không được phép"
    return f"không hợp lệ theo quy tắc {keyword}"
