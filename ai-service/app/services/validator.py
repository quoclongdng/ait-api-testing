"""Test DSL Validator service for ai-service.

Validates Test DSL documents against the canonical JSON Schema (test-dsl.schema.json)
and against OpenAPI 3.x specification semantics (endpoints, fields, status codes, variables).
Implements docs/DESIGN.md §2 D04, D05, D06 and Task S5-02.
"""

from __future__ import annotations

import json
import os
import re
from collections.abc import Iterable
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from jsonschema.exceptions import SchemaError
from jsonschema.validators import validator_for

from app.services.schema_errors import format_schema_errors

# JSONPath subset the web AssertionEngine/executor evaluates (web JsonPathSubset): `$`, member
# names, quoted keys and non-negative indexes. No wildcard, recursive descent, filter or slice.
_JSONPATH_QUOTED = r"""(?:"(?:[^"\\]|\\.)*"|'(?:[^'\\]|\\.)*')"""
# re.ASCII and the PHP trim() set: PCRE (no /u) only treats ASCII whitespace as \s, so a
# non-breaking space is part of a member name on the web, as it is here.
_JSONPATH_BRACKET = re.compile(r"\[\s*(" + _JSONPATH_QUOTED + r"|0|[1-9][0-9]*)\s*\]", re.ASCII)
_JSONPATH_MEMBER = re.compile(r"(" + _JSONPATH_QUOTED + r"|[^\s.\[\]'\"$*?():,@]+)", re.ASCII)
_PHP_TRIM_CHARS = " \t\n\r\0\x0b"
JSONPATH_SUPPORTED_SYNTAX = "$.a.b, $[0].id, $['x-y']"


def _quoted_key_is_valid(token: str) -> bool:
    """Whether a quoted member decodes like web JsonPathSubset (JSON escapes, \\' in '...')."""
    if token.startswith("'"):
        encoded = '"'
        index = 1
        while index < len(token) - 1:
            char = token[index]
            if char == "\\":
                index += 1
                following = token[index]
                encoded += "'" if following == "'" else "\\" + following
            else:
                encoded += '\\"' if char == '"' else char
            index += 1
        token = encoded + '"'
    try:
        key = json.loads(token)
        # PHP json_decode rejects a lone UTF-16 surrogate escape; Python accepts it.
        str(key).encode("utf-8")
    except (ValueError, UnicodeEncodeError):
        return False
    return isinstance(key, str)


def is_supported_json_path(path: str) -> bool:
    """Whether the web engine can evaluate ``path`` (same grammar as web JsonPathSubset)."""
    text = path.strip(_PHP_TRIM_CHARS)
    if text in ("", "$"):
        return True
    if text.startswith("$"):
        text = text[1:]
    offset = 0
    length = len(text)
    while offset < length:
        has_dot = text[offset] == "."
        if has_dot:
            offset += 1
        if offset >= length:
            return False
        if text[offset] == "[" and not has_dot:
            match = _JSONPATH_BRACKET.match(text, offset)
        else:
            match = _JSONPATH_MEMBER.match(text, offset)
        if match is None:
            return False
        token = match.group(1)
        if token[0] in "'\"" and not _quoted_key_is_valid(token):
            return False
        offset = match.end()
        if offset < length and text[offset] not in ".[":
            return False
    return True


def unsupported_json_path_error(path: str) -> str | None:
    """Vietnamese error for a JSONPath the engine cannot evaluate (``{{var}}`` paths skipped)."""
    if "{{" in path or is_supported_json_path(path):
        return None
    return (
        f"JSONPath {json.dumps(path, ensure_ascii=False)} không được hỗ trợ (chỉ hỗ trợ dạng "
        f"{JSONPATH_SUPPORTED_SYNTAX}; không dùng [*], .., [?()] hay lát cắt mảng)."
    )


# JSON type names accepted by json_path op=type (web AssertionEngine::evaluateType).
JSON_PATH_TYPE_NAMES = frozenset(
    {
        "string",
        "integer",
        "int",
        "number",
        "float",
        "numeric",
        "boolean",
        "bool",
        "array",
        "object",
        "null",
    }
)


def _is_valid_dsl_pattern(value: Any) -> bool:
    """Whether a ``matches`` pattern compiles (the web compiles it as a PCRE Unicode regex)."""
    if not isinstance(value, str):
        return False
    try:
        re.compile(value)
    except (re.error, RecursionError, OverflowError):
        return False
    return True


@dataclass
class DslValidationResult:
    """Represents the outcome of a DSL validation check."""

    is_valid: bool
    errors: list[str] = field(default_factory=list)
    schema_errors: list[str] = field(default_factory=list)
    semantic_errors: list[str] = field(default_factory=list)
    dsl: dict[str, Any] | None = None

    @classmethod
    def valid(cls, dsl: dict[str, Any] | None = None) -> DslValidationResult:
        return cls(is_valid=True, errors=[], schema_errors=[], semantic_errors=[], dsl=dsl)

    @classmethod
    def invalid(
        cls,
        schema_errors: list[str] | None = None,
        semantic_errors: list[str] | None = None,
        dsl: dict[str, Any] | None = None,
    ) -> DslValidationResult:
        s_errs = schema_errors or []
        sem_errs = semantic_errors or []
        return cls(
            is_valid=False,
            errors=s_errs + sem_errs,
            schema_errors=s_errs,
            semantic_errors=sem_errs,
            dsl=dsl,
        )


class DslValidator:
    """Validator for Test DSL v1.0 documents against JSON Schema and OpenAPI semantics."""

    def __init__(self, schema_path: Path | str | None = None) -> None:
        self.schema_path = Path(schema_path) if schema_path else None
        self.schema = self._load_schema()
        validator_cls = validator_for(self.schema)
        validator_cls.check_schema(self.schema)
        self._json_schema_validator = validator_cls(self.schema)

    def _load_schema(self) -> dict[str, Any]:
        """Locate and load the canonical test-dsl.schema.json."""
        if self.schema_path and self.schema_path.is_file():
            with open(self.schema_path, encoding="utf-8") as f:
                data = json.load(f)
                if isinstance(data, dict):
                    return data

        # Fallback candidates based on repository layout and container mounts
        current_file = Path(__file__).resolve()
        env_schema = os.environ.get("TEST_DSL_SCHEMA_PATH")
        candidates: list[Path] = [
            Path(env_schema) if env_schema else Path("/nonexistent"),
            Path("/contracts/test-dsl.schema.json"),
            Path("/app/contracts/test-dsl.schema.json"),
            current_file.parents[3] / "docs" / "contracts" / "test-dsl.schema.json",
            current_file.parents[2] / "docs" / "contracts" / "test-dsl.schema.json",
            Path.cwd() / "docs" / "contracts" / "test-dsl.schema.json",
            Path.cwd().parent / "docs" / "contracts" / "test-dsl.schema.json",
        ]

        for candidate in candidates:
            if candidate.is_file():
                with open(candidate, encoding="utf-8") as f:
                    data = json.load(f)
                    if isinstance(data, dict):
                        return data

        raise FileNotFoundError(
            "Không tìm thấy tệp JSON Schema tại docs/contracts/test-dsl.schema.json"
        )

    def validate(
        self,
        dsl: str | dict[str, Any],
        spec: dict[str, Any] | list[dict[str, Any]] | None = None,
        context_variables: Iterable[str] = (),
    ) -> DslValidationResult:
        """Validate Test DSL against JSON Schema and optional OpenAPI specification.

        Args:
            dsl: Raw JSON string or parsed dictionary of the DSL.
            spec: OpenAPI spec (raw dictionary, list of NormalizedEndpoint, or dict with endpoints).
            context_variables: Variables the run defines before step 1 (ADR-0006:
                ``auth_token_<role>`` of an experiment), usable as ``{{name}}`` (web parity).

        Returns:
            DslValidationResult containing status and error messages.
        """
        # 1. Normalize input
        if isinstance(dsl, str):
            try:
                dsl_dict = json.loads(dsl)
            except json.JSONDecodeError as exc:
                return DslValidationResult.invalid(
                    schema_errors=[f"Lỗi cú pháp JSON trong tài liệu Test DSL: {exc}"]
                )
        elif isinstance(dsl, dict):
            dsl_dict = dsl
        else:
            return DslValidationResult.invalid(
                schema_errors=["Dữ liệu Test DSL phải là chuỗi JSON hoặc đối tượng dictionary."]
            )

        if not isinstance(dsl_dict, dict):
            return DslValidationResult.invalid(
                schema_errors=["Tài liệu Test DSL phải là một JSON object."]
            )

        # 2. Schema validation
        # Vietnamese messages with the location in the DSL (web DslSchemaErrorFormatter parity),
        # not the raw jsonschema English text (S14-06); a step is named "Bước N" (1-based) like
        # the semantic errors below.
        schema_errors = format_schema_errors(
            self._json_schema_validator.iter_errors(dsl_dict), dsl_document=dsl_dict
        )

        if schema_errors:
            return DslValidationResult.invalid(schema_errors=schema_errors, dsl=dsl_dict)

        # 3. Semantic validation
        semantic_errors = self._validate_semantics(dsl_dict, spec, context_variables)
        if semantic_errors:
            return DslValidationResult.invalid(semantic_errors=semantic_errors, dsl=dsl_dict)

        return DslValidationResult.valid(dsl=dsl_dict)

    def _validate_semantics(
        self,
        dsl: dict[str, Any],
        spec: dict[str, Any] | list[dict[str, Any]] | None,
        context_variables: Iterable[str] = (),
    ) -> list[str]:
        """Perform semantic validation on the DSL."""
        errors: list[str] = []

        # Validate variable extraction and usage lifecycle
        errors.extend(self._validate_variables(dsl, context_variables))

        # Validate inline assertion schemas (independent of OpenAPI spec)
        errors.extend(self._validate_inline_schemas(dsl))

        # Validate against OpenAPI specification if provided
        if spec is not None:
            errors.extend(self._validate_against_spec(dsl, spec))

        return list(dict.fromkeys(errors))

    def _validate_inline_schemas(self, dsl: dict[str, Any]) -> list[str]:
        """Validate structure of all inline JSON Schemas in assertions across all steps."""
        errors: list[str] = []
        steps = dsl.get("steps")
        if not isinstance(steps, list):
            return errors

        for idx, step in enumerate(steps):
            if not isinstance(step, dict):
                continue
            step_num = idx + 1
            step_name = (
                step.get("name") if isinstance(step.get("name"), str) else f"Bước {step_num}"
            )

            extract_items = step.get("extract")
            if isinstance(extract_items, list):
                for e_idx, extract in enumerate(extract_items):
                    if (
                        isinstance(extract, dict)
                        and extract.get("from") == "body"
                        and isinstance(extract.get("path"), str)
                    ):
                        path_error = unsupported_json_path_error(extract["path"])
                        if path_error is not None:
                            errors.append(
                                f"Bước {step_num} ('{step_name}'), extract #{e_idx + 1}: "
                                f"{path_error}"
                            )

            assertions = step.get("assertions")
            if not isinstance(assertions, list):
                continue

            for a_idx, assertion in enumerate(assertions):
                if not isinstance(assertion, dict):
                    continue
                operator_error = self._validate_assertion_operator_value(assertion)
                if operator_error is not None:
                    errors.append(
                        f"Bước {step_num} ('{step_name}'), assertion #{a_idx + 1}: {operator_error}"
                    )
                if assertion.get("type") == "json_path" and isinstance(assertion.get("path"), str):
                    path_error = unsupported_json_path_error(assertion["path"])
                    if path_error is not None:
                        errors.append(
                            f"Bước {step_num} ('{step_name}'), assertion #{a_idx + 1}: {path_error}"
                        )
                a_type = assertion.get("type")
                if a_type == "json_schema" and "schema" in assertion:
                    syntax_error = self._validate_json_schema_syntax(assertion["schema"])
                    if syntax_error is not None:
                        errors.append(
                            f"Bước {step_num} ('{step_name}'): Cấu trúc JSON Schema "
                            f"inline không hợp lệ: {syntax_error}"
                        )

        return errors

    @staticmethod
    def _validate_assertion_operator_value(assertion: dict[str, Any]) -> str | None:
        """Mirror of the web DslValidator::validateAssertionOperatorValue (D04 parity).

        A ``matches`` pattern that does not compile or an unknown ``type`` name can never be
        evaluated by the AssertionEngine. Rejecting it here sends the case to repair instead
        of letting the web's final validation mark it INVALID. Values with ``{{var}}``
        placeholders are only known at run time and are skipped.
        """
        a_type = assertion.get("type")
        op = assertion.get("op")
        if a_type not in ("json_path", "header") or "value" not in assertion:
            return None
        value = assertion["value"]
        if isinstance(value, str) and "{{" in value:
            return None
        if op == "matches" and not _is_valid_dsl_pattern(value):
            return (
                'Pattern của op "matches" không phải biểu thức chính quy hợp lệ: '
                f"{json.dumps(value, ensure_ascii=False)}."
            )
        if (
            a_type == "json_path"
            and op == "type"
            and (not isinstance(value, str) or value.lower() not in JSON_PATH_TYPE_NAMES)
        ):
            return (
                'Giá trị của op "type" phải là một kiểu JSON (string, integer, number, '
                f"boolean, array, object, null), nhận {json.dumps(value, ensure_ascii=False)}."
            )
        return None

    def _validate_variables(
        self, dsl: dict[str, Any], context_variables: Iterable[str] = ()
    ) -> list[str]:
        """Ensure variables referenced via {{name}} are defined or extracted before use."""
        errors: list[str] = []
        # Run-time variables of the execution context (ADR-0006), then root variables.
        available_vars: set[str] = set(context_variables)

        # Root variables
        raw_vars = dsl.get("variables")
        if isinstance(raw_vars, dict):
            for k in raw_vars:
                available_vars.add(str(k))

        steps = dsl.get("steps")
        if not isinstance(steps, list):
            return errors

        for idx, step in enumerate(steps):
            if not isinstance(step, dict):
                continue
            step_num = idx + 1
            step_name = step.get("name") or f"Bước {step_num}"

            used_vars: set[str] = set()
            req = step.get("request")
            if isinstance(req, dict):
                used_vars.update(self._extract_placeholders(req))

            assertions = step.get("assertions")
            if isinstance(assertions, list):
                used_vars.update(self._extract_placeholders(assertions))

            # Placeholders inside extract paths must be defined too (web parity).
            extract_items = step.get("extract")
            if isinstance(extract_items, list):
                for ext in extract_items:
                    if isinstance(ext, dict) and "path" in ext:
                        used_vars.update(self._extract_placeholders(ext["path"]))

            for var_name in sorted(used_vars):
                if var_name not in available_vars:
                    errors.append(
                        f"Bước {step_num} ('{step_name}'): Biến '{{{{{var_name}}}}}' được sử dụng "
                        "nhưng chưa được định nghĩa trong variables hoặc trích xuất (extract) "
                        "từ bước trước đó."
                    )

            # Register extracted variables
            extract_list = step.get("extract")
            if isinstance(extract_list, list):
                for ext in extract_list:
                    if isinstance(ext, dict) and isinstance(ext.get("var"), str):
                        available_vars.add(ext["var"])

        return errors

    def _extract_placeholders(self, data: Any) -> list[str]:
        """Find all {{varName}} placeholders in arbitrary nested data."""
        results: list[str] = []
        if isinstance(data, str):
            results.extend(re.findall(r"\{\{([A-Za-z_][A-Za-z0-9_]*)\}\}", data))
        elif isinstance(data, dict):
            for val in data.values():
                results.extend(self._extract_placeholders(val))
        elif isinstance(data, list):
            for item in data:
                results.extend(self._extract_placeholders(item))
        return results

    def _validate_against_spec(
        self,
        dsl: dict[str, Any],
        spec: dict[str, Any] | list[dict[str, Any]],
    ) -> list[str]:
        """Validate DSL against OpenAPI spec (endpoints, parameters, body, assertions)."""
        errors: list[str] = []
        endpoint_map = self._build_endpoint_map(spec)
        components_schemas = self._extract_components_schemas(spec)

        # 1. Validate root endpoint
        root_endpoint = dsl.get("endpoint")
        if isinstance(root_endpoint, dict):
            r_method = str(root_endpoint.get("method", "")).upper()
            r_path = str(root_endpoint.get("path", ""))
            r_key = f"{r_method}:{r_path}"
            if r_key not in endpoint_map:
                errors.append(f"Endpoint '{r_method} {r_path}' không tồn tại trong đặc tả OpenAPI.")

        # 2. Validate steps
        steps = dsl.get("steps")
        if not isinstance(steps, list):
            return errors

        for idx, step in enumerate(steps):
            if not isinstance(step, dict):
                continue
            step_num = idx + 1
            step_name = step.get("name") or f"Bước {step_num}"

            req = step.get("request")
            if not isinstance(req, dict):
                continue

            method = str(req.get("method", "")).upper()
            path = str(req.get("path", ""))
            step_key = f"{method}:{path}"

            if step_key not in endpoint_map:
                errors.append(
                    f"Bước {step_num} ('{step_name}'): Endpoint '{method} {path}' "
                    f"không tồn tại trong đặc tả OpenAPI."
                )
                continue

            endpoint_data = endpoint_map[step_key]

            # A. Path parameters
            expected_path_params = self._extract_path_params(path, endpoint_data)
            provided_path_params = req.get("path_params")
            if not isinstance(provided_path_params, dict):
                provided_path_params = {}

            for expected in expected_path_params:
                if expected not in provided_path_params:
                    errors.append(
                        f"Bước {step_num} ('{step_name}'): Thiếu tham số đường dẫn bắt buộc "
                        f"'{expected}' cho endpoint '{method} {path}'."
                    )

            for provided in provided_path_params:
                if provided not in expected_path_params:
                    errors.append(
                        f"Bước {step_num} ('{step_name}'): Tham số đường dẫn "
                        f"'{provided}' không tồn tại trong endpoint '{method} {path}'."
                    )

            # B. Query parameters
            query_params = req.get("query")
            if isinstance(query_params, dict):
                declared_queries = self._get_declared_parameters(endpoint_data, "query") or []
                for q in query_params:
                    if q not in declared_queries:
                        errors.append(
                            f"Bước {step_num} ('{step_name}'): Tham số truy vấn '{q}' "
                            f"không tồn tại trong endpoint '{method} {path}'."
                        )

            # C. Request body
            if "body" in req and req["body"] is not None:
                has_request_body = bool(
                    endpoint_data.get("request_body") or endpoint_data.get("requestBody")
                )
                if not has_request_body:
                    errors.append(
                        f"Bước {step_num} ('{step_name}'): Endpoint '{method} {path}' "
                        "không khai báo request body nhưng ca kiểm thử lại truyền body."
                    )
                else:
                    body_schema = self._get_request_body_schema(endpoint_data, components_schemas)
                    if body_schema:
                        resolved_body_schema = self._resolve_schema(body_schema, components_schemas)
                        resolved_body_schema = self._flatten_all_of(
                            resolved_body_schema, components_schemas
                        )
                        body_errors = self._validate_body_recursively(
                            req["body"],
                            resolved_body_schema,
                            components_schemas,
                            current_path="",
                            step_num=step_num,
                            step_name=step_name,
                            method=method,
                            path=path,
                        )
                        errors.extend(body_errors)

            # D. Assertions
            assertions = step.get("assertions")
            if isinstance(assertions, list):
                declared_responses = endpoint_data.get("responses")
                declared_statuses: list[str] = []
                if isinstance(declared_responses, dict):
                    declared_statuses = [str(k) for k in declared_responses]

                for assertion in assertions:
                    if not isinstance(assertion, dict):
                        continue
                    a_type = assertion.get("type")

                    if a_type == "status":
                        status = str(assertion.get("equals", ""))
                        if not self._is_status_code_declared(status, declared_statuses):
                            errors.append(
                                f"Bước {step_num} ('{step_name}'): Mã trạng thái HTTP '{status}' "
                                "không được khai báo trong responses của endpoint "
                                f"'{method} {path}'."
                            )
                    elif a_type == "status_in":
                        values = assertion.get("values")
                        if isinstance(values, list):
                            for val in values:
                                status = str(val)
                                if not self._is_status_code_declared(status, declared_statuses):
                                    errors.append(
                                        f"Bước {step_num} ('{step_name}'): "
                                        f"Mã trạng thái HTTP '{status}' trong status_in "
                                        "không được khai báo trong responses của "
                                        f"endpoint '{method} {path}'."
                                    )
                    elif a_type == "json_schema":
                        ref = assertion.get("schema_ref")
                        if isinstance(ref, str):
                            match = re.match(r"^#/components/schemas/([a-zA-Z0-9._-]+)$", ref)
                            if not match:
                                errors.append(
                                    f"Bước {step_num} ('{step_name}'): Schema reference '{ref}' "
                                    f"không đúng định dạng '#/components/schemas/{{name}}'."
                                )
                            else:
                                schema_name = match.group(1)
                                if schema_name not in components_schemas:
                                    errors.append(
                                        f"Bước {step_num} ('{step_name}'): "
                                        f"Schema reference '{ref}' không tồn tại trong "
                                        "components.schemas của đặc tả OpenAPI."
                                    )

        return errors

    def _build_endpoint_map(
        self, spec: dict[str, Any] | list[dict[str, Any]]
    ) -> dict[str, dict[str, Any]]:
        """Normalize spec into endpoint map indexed by METHOD:path."""
        endpoint_map: dict[str, dict[str, Any]] = {}

        if isinstance(spec, list):
            for ep in spec:
                if isinstance(ep, dict):
                    m = str(ep.get("method", "")).upper()
                    p = str(ep.get("path", ""))
                    if m and p:
                        endpoint_map[f"{m}:{p}"] = ep
            return endpoint_map

        if isinstance(spec, dict) and "endpoints" in spec and isinstance(spec["endpoints"], list):
            for ep in spec["endpoints"]:
                if isinstance(ep, dict):
                    m = str(ep.get("method", "")).upper()
                    p = str(ep.get("path", ""))
                    if m and p:
                        endpoint_map[f"{m}:{p}"] = ep
            return endpoint_map

        # Standard OpenAPI dictionary with 'paths'
        paths = spec.get("paths") if isinstance(spec, dict) else None
        if isinstance(paths, dict):
            http_methods = {"get", "post", "put", "patch", "delete", "head", "options"}
            for path_pattern, path_item in paths.items():
                if not isinstance(path_item, dict):
                    continue
                raw_common = path_item.get("parameters")
                common_params: list[Any] = list(raw_common) if isinstance(raw_common, list) else []

                for op_key, op_val in path_item.items():
                    if op_key.lower() in http_methods and isinstance(op_val, dict):
                        raw_op = op_val.get("parameters")
                        op_params: list[Any] = list(raw_op) if isinstance(raw_op, list) else []
                        combined_params = common_params + op_params

                        ep_data: dict[str, Any] = {
                            "method": op_key.upper(),
                            "path": path_pattern,
                            "operation_id": op_val.get("operationId"),
                            "parameters": combined_params,
                            "request_body": op_val.get("requestBody") or op_val.get("request_body"),
                            "responses": op_val.get("responses") or {},
                        }
                        endpoint_map[f"{op_key.upper()}:{path_pattern}"] = ep_data

        return endpoint_map

    def _extract_components_schemas(self, spec: Any) -> dict[str, Any]:
        """Extract components.schemas dictionary from OpenAPI spec."""
        if isinstance(spec, dict):
            components = spec.get("components")
            if isinstance(components, dict):
                schemas = components.get("schemas")
                if isinstance(schemas, dict):
                    return schemas
        return {}

    def _extract_path_params(self, path: str, endpoint_data: dict[str, Any]) -> list[str]:
        """Extract path parameters defined in template {id} and in parameters list."""
        params: list[str] = re.findall(r"\{([^{}]+)\}", path)

        raw_params = endpoint_data.get("parameters")
        if isinstance(raw_params, list):
            for p in raw_params:
                if isinstance(p, dict) and p.get("in") == "path":
                    name = p.get("name")
                    if isinstance(name, str) and name not in params:
                        params.append(name)

        return params

    def _get_declared_parameters(
        self, endpoint_data: dict[str, Any], in_type: str
    ) -> list[str] | None:
        """Get declared parameter names for a given 'in' type, or None if not specified."""
        raw_params = endpoint_data.get("parameters")
        if not isinstance(raw_params, list):
            return None

        declared: list[str] = []
        for p in raw_params:
            if isinstance(p, dict) and p.get("in") == in_type:
                name = p.get("name")
                if isinstance(name, str):
                    declared.append(name)
        return declared

    def _get_request_body_schema(
        self, endpoint_data: dict[str, Any], components_schemas: dict[str, Any]
    ) -> dict[str, Any] | None:
        """Extract JSON schema for requestBody."""
        rb = endpoint_data.get("request_body") or endpoint_data.get("requestBody")
        if not isinstance(rb, dict):
            return None

        resolved_rb = self._resolve_schema(rb, components_schemas)
        content = resolved_rb.get("content")
        if isinstance(content, dict):
            # Prefer application/json
            if "application/json" in content and isinstance(content["application/json"], dict):
                schema = content["application/json"].get("schema")
                if isinstance(schema, dict):
                    return schema
            for media_type in content.values():
                if isinstance(media_type, dict) and "schema" in media_type:
                    schema = media_type.get("schema")
                    if isinstance(schema, dict):
                        return schema

        # Direct schema property if normalized
        schema = resolved_rb.get("schema")
        if isinstance(schema, dict):
            return schema

        return None

    def _resolve_schema(
        self, schema: dict[str, Any], components_schemas: dict[str, Any], depth: int = 0
    ) -> dict[str, Any]:
        """Recursively resolve $ref references."""
        if depth > 10 or not isinstance(schema, dict):
            return schema

        ref = schema.get("$ref")
        if isinstance(ref, str):
            match = re.match(r"^#/components/schemas/([a-zA-Z0-9._-]+)$", ref)
            if match:
                ref_name = match.group(1)
                ref_schema = components_schemas.get(ref_name)
                if isinstance(ref_schema, dict):
                    merged = dict(ref_schema)
                    for k, v in schema.items():
                        if k != "$ref":
                            merged[k] = v
                    return self._resolve_schema(merged, components_schemas, depth + 1)

        return schema

    def _flatten_all_of(
        self, schema: dict[str, Any], components_schemas: dict[str, Any], depth: int = 0
    ) -> dict[str, Any]:
        """Flatten allOf composition by merging properties, items, and additionalProperties."""
        if depth > 10 or not isinstance(schema, dict) or "allOf" not in schema:
            return schema

        all_of_list = schema.get("allOf")
        if not isinstance(all_of_list, list):
            return schema

        result = dict(schema)
        del result["allOf"]

        merged_props: dict[str, Any] = (
            dict(result["properties"]) if isinstance(result.get("properties"), dict) else {}
        )
        merged_required: list[str] = (
            list(result["required"]) if isinstance(result.get("required"), list) else []
        )
        merged_items: Any = result.get("items")
        merged_additional_props: Any = result.get("additionalProperties")
        extra_compositions: list[dict[str, Any]] = []

        for sub in all_of_list:
            if not isinstance(sub, dict):
                continue
            resolved_sub = self._resolve_schema(sub, components_schemas, depth + 1)
            flattened_sub = self._flatten_all_of(resolved_sub, components_schemas, depth + 1)

            if "type" in flattened_sub and "type" not in result:
                result["type"] = flattened_sub["type"]

            if isinstance(flattened_sub.get("properties"), dict):
                for p_name, p_schema in flattened_sub["properties"].items():
                    if p_name in merged_props:
                        existing = merged_props[p_name]
                        if isinstance(existing, dict) and isinstance(p_schema, dict):
                            merged_props[p_name] = self._flatten_all_of(
                                {"allOf": [existing, p_schema]},
                                components_schemas,
                                depth + 1,
                            )
                        else:
                            merged_props[p_name] = p_schema
                    else:
                        merged_props[p_name] = p_schema

            if "items" in flattened_sub:
                if merged_items is None:
                    merged_items = flattened_sub["items"]
                elif isinstance(merged_items, dict) and isinstance(flattened_sub["items"], dict):
                    merged_items = self._flatten_all_of(
                        {
                            "allOf": [merged_items, flattened_sub["items"]],
                        },
                        components_schemas,
                        depth + 1,
                    )

            if "additionalProperties" in flattened_sub:
                sub_add_props = flattened_sub["additionalProperties"]
                if merged_additional_props is None:
                    merged_additional_props = sub_add_props
                elif merged_additional_props is False or sub_add_props is False:
                    merged_additional_props = False
                elif merged_additional_props is True:
                    merged_additional_props = sub_add_props
                elif sub_add_props is True:
                    pass  # Stricter schema wins over True
                elif isinstance(merged_additional_props, dict) and isinstance(sub_add_props, dict):
                    merged_additional_props = self._flatten_all_of(
                        {
                            "allOf": [
                                merged_additional_props,
                                sub_add_props,
                            ],
                        },
                        components_schemas,
                        depth + 1,
                    )

            if isinstance(flattened_sub.get("required"), list):
                merged_required.extend(str(r) for r in flattened_sub["required"])

            for comp in ("anyOf", "oneOf"):
                if comp in flattened_sub:
                    extra_compositions.append({comp: flattened_sub[comp]})

        if merged_props:
            result["properties"] = merged_props
        if merged_required:
            result["required"] = list(dict.fromkeys(merged_required))
        if merged_items is not None:
            result["items"] = merged_items
        if merged_additional_props is not None:
            result["additionalProperties"] = merged_additional_props

        for comp_item in extra_compositions:
            for k, v in comp_item.items():
                if k not in result:
                    result[k] = v

        return result

    def _validate_body_recursively(
        self,
        data: Any,
        schema: dict[str, Any],
        components_schemas: dict[str, Any],
        current_path: str,
        step_num: int,
        step_name: str,
        method: str,
        path: str,
    ) -> list[str]:
        """Recursively validate body data against schema."""
        errors: list[str] = []
        resolved_schema = self._resolve_schema(schema, components_schemas)
        resolved_schema = self._flatten_all_of(resolved_schema, components_schemas)

        # 1. oneOf branching
        if isinstance(resolved_schema.get("oneOf"), list):
            branches = resolved_schema["oneOf"]
            matching_branches = 0
            all_branch_errors: list[list[str]] = []

            for branch in branches:
                if not isinstance(branch, dict):
                    continue
                resolved_branch = self._resolve_schema(branch, components_schemas)
                resolved_branch = self._flatten_all_of(resolved_branch, components_schemas)

                branch_schema = dict(resolved_branch)
                if isinstance(resolved_schema.get("properties"), dict):
                    parent_props = resolved_schema["properties"]
                    branch_props = branch_schema.get("properties", {})
                    combined = dict(parent_props)
                    if isinstance(branch_props, dict):
                        combined.update(branch_props)
                    branch_schema["properties"] = combined

                branch_errs = self._validate_body_recursively(
                    data,
                    branch_schema,
                    components_schemas,
                    current_path,
                    step_num,
                    step_name,
                    method,
                    path,
                )
                all_branch_errors.append(branch_errs)
                if not branch_errs:
                    matching_branches += 1

            if matching_branches == 0 and all_branch_errors:
                best_errs = min(all_branch_errors, key=len)
                errors.extend(best_errs)
            return errors

        # 2. anyOf branching
        if isinstance(resolved_schema.get("anyOf"), list):
            branches = resolved_schema["anyOf"]
            matched = False
            all_branch_errors = []

            for branch in branches:
                if not isinstance(branch, dict):
                    continue
                resolved_branch = self._resolve_schema(branch, components_schemas)
                resolved_branch = self._flatten_all_of(resolved_branch, components_schemas)

                branch_schema = dict(resolved_branch)
                if isinstance(resolved_schema.get("properties"), dict):
                    parent_props = resolved_schema["properties"]
                    branch_props = branch_schema.get("properties", {})
                    combined = dict(parent_props)
                    if isinstance(branch_props, dict):
                        combined.update(branch_props)
                    branch_schema["properties"] = combined

                branch_errs = self._validate_body_recursively(
                    data,
                    branch_schema,
                    components_schemas,
                    current_path,
                    step_num,
                    step_name,
                    method,
                    path,
                )
                all_branch_errors.append(branch_errs)
                if not branch_errs:
                    matched = True
                    break

            if not matched and all_branch_errors:
                best_errs = min(all_branch_errors, key=len)
                errors.extend(best_errs)
            return errors

        # 3. Object validation
        if isinstance(data, dict):
            has_properties = isinstance(resolved_schema.get("properties"), dict)
            has_additional = "additionalProperties" in resolved_schema

            if has_properties or has_additional:
                allowed_props = (
                    set(resolved_schema["properties"].keys()) if has_properties else set()
                )
                additional_schema = resolved_schema.get("additionalProperties")

                for key, val in data.items():
                    prop_key = str(key)
                    field_display = f"{current_path}.{prop_key}" if current_path else prop_key

                    if prop_key in allowed_props:
                        prop_schema = resolved_schema["properties"][prop_key]
                        if isinstance(prop_schema, dict) and isinstance(val, (dict, list)):
                            sub_errs = self._validate_body_recursively(
                                val,
                                prop_schema,
                                components_schemas,
                                field_display,
                                step_num,
                                step_name,
                                method,
                                path,
                            )
                            errors.extend(sub_errs)
                    else:
                        # Key not declared in properties
                        if additional_schema is False:
                            errors.append(
                                f"Bước {step_num} ('{step_name}'): Trường '{field_display}' "
                                "trong request body không tồn tại trong schema của endpoint "
                                f"'{method} {path}'."
                            )
                        elif additional_schema is True:
                            pass  # Explicitly allowed
                        elif isinstance(additional_schema, dict):
                            # Map schema: validate value
                            if isinstance(val, (dict, list)):
                                sub_errs = self._validate_body_recursively(
                                    val,
                                    additional_schema,
                                    components_schemas,
                                    field_display,
                                    step_num,
                                    step_name,
                                    method,
                                    path,
                                )
                                errors.extend(sub_errs)
                        else:
                            # Not defined: reject if properties is defined
                            if has_properties:
                                errors.append(
                                    f"Bước {step_num} ('{step_name}'): Trường '{field_display}' "
                                    "trong request body không tồn tại trong schema của endpoint "
                                    f"'{method} {path}'."
                                )

        # 4. Array validation
        elif isinstance(data, list):
            items_schema = resolved_schema.get("items")
            if isinstance(items_schema, dict):
                for idx, item in enumerate(data):
                    item_display = f"{current_path}[{idx}]"
                    if isinstance(item, (dict, list)):
                        sub_errs = self._validate_body_recursively(
                            item,
                            items_schema,
                            components_schemas,
                            item_display,
                            step_num,
                            step_name,
                            method,
                            path,
                        )
                        errors.extend(sub_errs)

        return errors

    def _is_status_code_declared(self, status: str, declared_statuses: list[str]) -> bool:
        """Check if HTTP status code matches declared responses (exact or 2XX/default)."""
        if not declared_statuses:
            return True
        if status in declared_statuses or "default" in declared_statuses:
            return True
        if len(status) == 3:
            wildcard = f"{status[0]}XX"
            if wildcard in declared_statuses:
                return True
        return False

    def _validate_json_schema_syntax(self, schema: Any, path: str = "$") -> str | None:
        """Validate structure and types of an inline JSON schema using meta-schema."""
        if isinstance(schema, bool):
            return None
        if not isinstance(schema, dict):
            return f"Giá trị schema tại '{path}' phải là một JSON object (hoặc boolean)."

        try:
            validator_cls = validator_for(schema)
            validator_cls.check_schema(schema)
        except SchemaError as e:
            return "; ".join(format_schema_errors([e], root_label="gốc schema", prefix="$"))
        except Exception:
            return "Cấu trúc schema không hợp lệ theo meta-schema."

        return None
