"""Deterministic Test DSL v1.0 Executor for Benchmark Runner.

Executes test case steps against FastAPI TestClient or HTTP target:
- Template variable interpolation ({{var_name}})
- Step request sending (method, path, headers, query, body, path_params)
- Dynamic variable extraction (from body/header/status)
- Assertions evaluation (status, status_in, header, json_path, response_time, json_schema)
- Full compatibility with AIT Test DSL v1.0 specification (docs/DESIGN.md D03, D05)
"""

from __future__ import annotations

import json
import re
import time
from typing import Any

import jsonschema
from benchmark.runner.models import StepResult, TestCaseResult, VerdictType


def _resolve_template(val: Any, variables: dict[str, Any]) -> Any:
    """Recursively replace {{var_name}} in strings, dicts, lists with variable values."""
    if isinstance(val, str):
        exact_match = re.fullmatch(r"\{\{([A-Za-z_][A-Za-z0-9_]*)\}\}", val.strip())
        if exact_match:
            var_name = exact_match.group(1)
            if var_name in variables:
                return variables[var_name]

        def replace_fn(match: re.Match[str]) -> str:
            name = match.group(1)
            return str(variables.get(name, match.group(0)))

        return re.sub(r"\{\{([A-Za-z_][A-Za-z0-9_]*)\}\}", replace_fn, val)

    if isinstance(val, dict):
        return {k: _resolve_template(v, variables) for k, v in val.items()}
    if isinstance(val, list):
        return [_resolve_template(item, variables) for item in val]
    return val


def _unescape_jsonpath_string(raw: str, quote: str) -> str | None:
    """Unescape a quoted string token according to RFC 9535 Section 2.3.1.1 rules."""
    res: list[str] = []
    i = 0
    n = len(raw)
    while i < n:
        ch = raw[i]
        if ch == "\\":
            i += 1
            if i >= n:
                return None
            esc = raw[i]
            if esc in (quote, "'", '"'):
                res.append(esc)
            elif esc == "\\":
                res.append("\\")
            elif esc == "/":
                res.append("/")
            elif esc == "b":
                res.append("\b")
            elif esc == "f":
                res.append("\f")
            elif esc == "n":
                res.append("\n")
            elif esc == "r":
                res.append("\r")
            elif esc == "t":
                res.append("\t")
            elif esc == "u":
                if i + 4 >= n:
                    return None
                hex_str = raw[i + 1 : i + 5]
                try:
                    code_point = int(hex_str, 16)
                    i += 4
                except ValueError:
                    return None
                if 0xD800 <= code_point <= 0xDBFF:
                    if i + 6 < n and raw[i + 1] == "\\" and raw[i + 2] == "u":
                        low_hex = raw[i + 3 : i + 7]
                        try:
                            low_code = int(low_hex, 16)
                            if 0xDC00 <= low_code <= 0xDFFF:
                                scalar = (
                                    0x10000 + ((code_point - 0xD800) << 10) + (low_code - 0xDC00)
                                )
                                res.append(chr(scalar))
                                i += 6
                            else:
                                return None
                        except ValueError:
                            return None
                    else:
                        return None
                elif 0xDC00 <= code_point <= 0xDFFF:
                    return None
                else:
                    res.append(chr(code_point))
            else:
                return None
        else:
            res.append(ch)
        i += 1
    return "".join(res)


def _parse_json_path_tokens(path: str) -> list[tuple[str, Any]] | None:
    """Parse a subset of JSONPath into a list of tokens.

    Supports:
    - Root object: $, empty string
    - Bracket notation with single or double quotes for arbitrary keys:
      $['a.b'], $["a.b"], $["a\u002eb"], $['a\'b']
    - Bracket notation with integers: $[0], $[1]
    - Dot-separated property identifiers: $.prop, prop, $.a.b
    """
    trimmed = path.strip()
    if trimmed in ("", "$"):
        return []

    if trimmed.startswith("$"):
        trimmed = trimmed[1:]

    tokens: list[tuple[str, Any]] = []
    i = 0
    n = len(trimmed)
    while i < n:
        if trimmed[i] == ".":
            i += 1
            if i >= n:
                return None
            start = i
            while i < n and trimmed[i] not in (".", "["):
                i += 1
            prop = trimmed[start:i]
            if not prop:
                return None
            tokens.append(("prop", prop))
        elif trimmed[i] == "[":
            i += 1
            if i >= n:
                return None
            if trimmed[i] in ("'", '"'):
                quote = trimmed[i]
                i += 1
                start = i
                while i < n and trimmed[i] != quote:
                    if trimmed[i] == "\\" and i + 1 < n:
                        i += 2
                    else:
                        i += 1
                if i >= n or trimmed[i] != quote:
                    return None
                raw_prop = trimmed[start:i]
                i += 1  # skip quote
                if i >= n or trimmed[i] != "]":
                    return None
                i += 1  # skip ']'
                unescaped = _unescape_jsonpath_string(raw_prop, quote)
                if unescaped is None:
                    return None
                tokens.append(("prop", unescaped))
            else:
                start = i
                while i < n and trimmed[i] != "]":
                    i += 1
                if i >= n or trimmed[i] != "]":
                    return None
                content = trimmed[start:i].strip()
                i += 1  # skip ']'
                if content.isdigit():
                    tokens.append(("idx", int(content)))
                else:
                    tokens.append(("prop", content))
        else:
            start = i
            while i < n and trimmed[i] not in (".", "["):
                i += 1
            prop = trimmed[start:i]
            if not prop:
                return None
            tokens.append(("prop", prop))

    return tokens


def _resolve_tokens(data: Any, tokens: list[tuple[str, Any]]) -> tuple[bool, Any]:
    """Traverse tokens against data, distinguishing present-null from missing."""
    curr = data
    for kind, val in tokens:
        if kind == "prop":
            if isinstance(curr, dict) and val in curr:
                curr = curr[val]
            else:
                return False, None
        elif kind == "idx":
            if isinstance(curr, list) and 0 <= val < len(curr):
                curr = curr[val]
            else:
                return False, None
        else:
            return False, None
    return True, curr


def _resolve_json_path(data: Any, path: str) -> tuple[bool, Any]:
    """Resolve JSONPath expression into (found, value).

    Correctly distinguishes present-null (found=True, val=None) from missing (found=False).
    Supports literal quoted keys (e.g. $['a.b']), numeric indices ($[0]), and nested paths.
    """
    tokens = _parse_json_path_tokens(path)
    if tokens is None:
        return False, None
    return _resolve_tokens(data, tokens)


def _extract_json_path(data: Any, path: str) -> Any:
    """Extract a value from data using JSONPath syntax, returning None if missing."""
    found, val = _resolve_json_path(data, path)
    return val if found else None
    return val if found else None


def _are_values_equal(a: Any, b: Any) -> bool:
    """Strict JSON equality preventing Python True == 1 and loose string/number equality."""
    if isinstance(a, bool) or isinstance(b, bool):
        return isinstance(a, bool) and isinstance(b, bool) and a == b
    if isinstance(a, (int, float)) and isinstance(b, (int, float)):
        return a == b
    if type(a) is not type(b):
        return False
    if isinstance(a, dict):
        if set(a.keys()) != set(b.keys()):
            return False
        return all(_are_values_equal(a[k], b[k]) for k in a)
    if isinstance(a, list):
        if len(a) != len(b):
            return False
        return all(_are_values_equal(x, y) for x, y in zip(a, b, strict=True))
    return a == b


class DslExecutor:
    """Executes AIT Test DSL v1.0 test cases against a benchmark client."""

    def __init__(self, openapi_spec: dict[str, Any] | None = None) -> None:
        self.openapi_spec = openapi_spec or {}

    def execute_test_case(
        self,
        case: dict[str, Any],
        client: Any,
        base_url: str | None = None,
    ) -> TestCaseResult:
        """Execute all steps in a test case in order and return the overall verdict."""
        case_id = str(case.get("id", case.get("title", "test-case")))
        title = str(case.get("title", "Untitled test case"))
        technique = str(case.get("technique", "UNKNOWN"))

        variables: dict[str, Any] = {}
        if isinstance(case.get("variables"), dict):
            variables.update(case["variables"])

        steps = case.get("steps", [])
        step_results: list[StepResult] = []
        overall_verdict: VerdictType = "PASS"
        overall_error: str | None = None
        total_latency = 0

        for step in steps:
            step_res = self._execute_step(step, client, variables, base_url)
            step_results.append(step_res)
            total_latency += step_res.latency_ms

            if not step_res.passed:
                overall_verdict = "FAIL"
                if overall_error is None:
                    overall_error = f"Step '{step_res.name}' failed: {step_res.error}"

            if step_res.error and (
                step_res.error.startswith("Request error:")
                or step_res.error.startswith("Extraction error:")
            ):
                overall_verdict = "ERROR"
                overall_error = f"Step '{step_res.name}' error: {step_res.error}"
                break

        return TestCaseResult(
            case_id=case_id,
            title=title,
            technique=technique,
            verdict=overall_verdict,
            steps=step_results,
            error=overall_error,
            total_latency_ms=total_latency,
        )

    def _execute_step(
        self,
        step: dict[str, Any],
        client: Any,
        variables: dict[str, Any],
        base_url: str | None = None,
    ) -> StepResult:
        name = str(step.get("name", "step"))
        raw_req = step.get("request", {})
        req = _resolve_template(raw_req, variables)

        method = str(req.get("method", "GET")).upper()
        path = str(req.get("path", "/"))

        # Interpolate path_params into path template (e.g. /products/{id})
        path_params = req.get("path_params") or {}
        if isinstance(path_params, dict):
            for pk, pv in path_params.items():
                path = path.replace(f"{{{pk}}}", str(pv))

        # Also interpolate any remaining {var} from runtime variables
        for vk, vv in variables.items():
            path = path.replace(f"{{{vk}}}", str(vv))

        headers = dict(req.get("headers", {}))
        query = req.get("query") or req.get("params") or {}
        body = req.get("body")

        url = path
        if base_url and not path.startswith("http"):
            url = f"{base_url.rstrip('/')}{path}"

        start_time = time.perf_counter()
        resp: Any = None
        try:
            kwargs: dict[str, Any] = {"headers": headers, "params": query}
            if "body" in req:
                body = req["body"]
                kwargs["content"] = json.dumps(body).encode("utf-8")
                if not any(k.lower() == "content-type" for k in headers):
                    headers["Content-Type"] = "application/json"

            resp = client.request(method=method, url=url, **kwargs)
        except Exception as exc:
            latency = int((time.perf_counter() - start_time) * 1000)
            return StepResult(
                name=name,
                method=method,
                path=path,
                status_code=None,
                passed=False,
                error=f"Request error: {exc}",
                latency_ms=latency,
            )

        latency = int((time.perf_counter() - start_time) * 1000)
        status_code = getattr(resp, "status_code", None)

        # 1. Process extractions
        extracts = step.get("extract", [])
        if isinstance(extracts, list):
            for ext in extracts:
                if not isinstance(ext, dict):
                    continue
                var_name = ext.get("var")
                ext_from = ext.get("from", "body")
                ext_path = ext.get("path") or ext.get("name") or ext.get("header") or "$"
                if not var_name:
                    continue

                if ext_from == "status":
                    variables[var_name] = status_code
                elif ext_from == "header":
                    hdr_key = ext_path.lower()
                    found_header = False
                    header_val = None
                    for hk, hv in getattr(resp, "headers", {}).items():
                        if hk.lower() == hdr_key:
                            found_header = True
                            header_val = hv
                            break
                    if not found_header:
                        return StepResult(
                            name=name,
                            method=method,
                            path=path,
                            status_code=status_code,
                            passed=False,
                            error=f"Extraction error: Header '{ext_path}' not found",
                            latency_ms=latency,
                        )
                    variables[var_name] = header_val
                elif ext_from == "body":
                    try:
                        body_json = resp.json()
                    except Exception as exc:
                        return StepResult(
                            name=name,
                            method=method,
                            path=path,
                            status_code=status_code,
                            passed=False,
                            error=f"Extraction error: Response body is not valid JSON: {exc}",
                            latency_ms=latency,
                        )
                    tokens = _parse_json_path_tokens(ext_path)
                    if tokens is None:
                        return StepResult(
                            name=name,
                            method=method,
                            path=path,
                            status_code=status_code,
                            passed=False,
                            error=(
                                f"Extraction error: JSONPath '{ext_path}' is invalid "
                                "or outside supported syntax"
                            ),
                            latency_ms=latency,
                        )
                    found, val = _resolve_tokens(body_json, tokens)
                    if not found:
                        return StepResult(
                            name=name,
                            method=method,
                            path=path,
                            status_code=status_code,
                            passed=False,
                            error=f"Extraction error: JSONPath '{ext_path}' not found",
                            latency_ms=latency,
                        )
                    # found=True with val=None represents valid present null; extraction succeeds
                    variables[var_name] = val

        # 2. Evaluate assertions
        assertions = step.get("assertions", [])
        passed_assertions = 0
        failed_assertions: list[dict[str, Any]] = []

        for assertion in assertions:
            if not isinstance(assertion, dict):
                continue
            resolved_assertion = _resolve_template(assertion, variables)
            passed, err_msg = self._check_assertion(resolved_assertion, resp, latency)
            if passed:
                passed_assertions += 1
            else:
                failed_assertions.append({"assertion": assertion, "error": err_msg})

        step_passed = len(failed_assertions) == 0
        error_summary = (
            "; ".join(f["error"] for f in failed_assertions) if failed_assertions else None
        )

        return StepResult(
            name=name,
            method=method,
            path=path,
            status_code=status_code,
            passed=step_passed,
            error=error_summary,
            assertions_count=len(assertions),
            passed_assertions=passed_assertions,
            failed_assertions=failed_assertions,
            latency_ms=latency,
        )

    def _check_assertion(
        self,
        assertion: dict[str, Any],
        resp: Any,
        latency_ms: int,
    ) -> tuple[bool, str]:
        a_type = assertion.get("type")

        if a_type == "status":
            raw_exp = assertion.get("equals")
            try:
                expected = int(raw_exp)
            except (ValueError, TypeError):
                expected = raw_exp
            actual = resp.status_code
            if actual == expected:
                return True, ""
            return False, f"Expected status {expected}, got {actual}"

        if a_type == "status_in":
            raw_vals = assertion.get("values", [])
            values = []
            for v in raw_vals:
                try:
                    values.append(int(v))
                except (ValueError, TypeError):
                    values.append(v)
            actual = resp.status_code
            if actual in values:
                return True, ""
            return False, f"Expected status in {values}, got {actual}"

        if a_type == "header":
            raw_h = assertion.get("name") or assertion.get("header") or ""
            header_name = str(raw_h).strip().lower()
            expected = assertion.get("value")
            op = str(assertion.get("op", "eq")).lower()
            header_keys = [k.lower() for k in resp.headers]
            actual = resp.headers.get(header_name)

            if op in ("exists",):
                if header_name in header_keys:
                    return True, ""
                return False, f"Header '{header_name}' expected to exist, but was absent"

            if op in ("not_exists",):
                if header_name not in header_keys:
                    return True, ""
                return False, f"Header '{header_name}' expected not to exist, but found '{actual}'"

            if op in ("eq", "equals"):
                if actual is not None and str(actual) == str(expected):
                    return True, ""
                return False, f"Header '{header_name}' expected '{expected}', got '{actual}'"

            if op in ("ne", "not_equals"):
                if actual is None or str(actual) != str(expected):
                    return True, ""
                return False, f"Header '{header_name}' expected not '{expected}', got '{actual}'"

            if op == "contains":
                if actual is not None and str(expected) in str(actual):
                    return True, ""
                return (
                    False,
                    f"Header '{header_name}' expected to contain '{expected}', got '{actual}'",
                )

            if op == "matches":
                # DSL contract: bare pattern, no PHP-style delimiter stripping.
                pattern = str(expected)
                if actual is not None and bool(re.search(pattern, str(actual))):
                    return True, ""
                return (
                    False,
                    f"Header '{header_name}' expected regex match '{pattern}', got '{actual}'",
                )

            return False, f"Unsupported header assertion op '{op}'"

        if a_type == "json_path":
            path = str(assertion.get("path", "$"))
            op = str(assertion.get("op", "eq")).lower()
            expected = assertion.get("value")

            try:
                body = resp.json()
            except Exception:
                return False, "Response body is not valid JSON"

            tokens = _parse_json_path_tokens(path)
            if tokens is None:
                return False, f"JSONPath '{path}' is invalid or outside supported syntax"

            found, actual = _resolve_tokens(body, tokens)

            if op in ("exists",):
                if found:
                    return True, ""
                return False, f"JSONPath '{path}' expected to exist, but was absent"

            if op in ("not_exists",):
                if not found:
                    return True, ""
                return False, f"JSONPath '{path}' expected not to exist, but got {actual}"

            if not found:
                return False, f"JSONPath '{path}' not found in response JSON"

            if op in ("eq", "equals"):
                if _are_values_equal(actual, expected):
                    return True, ""
                return False, f"JSONPath '{path}' expected {expected}, got {actual}"

            if op in ("ne", "not_equals"):
                if not _are_values_equal(actual, expected):
                    return True, ""
                return False, f"JSONPath '{path}' expected != {expected}, got {actual}"

            if op == "gt":
                if (
                    isinstance(actual, (int, float))
                    and not isinstance(actual, bool)
                    and isinstance(expected, (int, float))
                    and not isinstance(expected, bool)
                ):
                    if actual > expected:
                        return True, ""
                    return False, f"JSONPath '{path}' expected > {expected}, got {actual}"
                return False, f"JSONPath '{path}' numeric comparison failed on non-numeric values"

            if op == "gte":
                if (
                    isinstance(actual, (int, float))
                    and not isinstance(actual, bool)
                    and isinstance(expected, (int, float))
                    and not isinstance(expected, bool)
                ):
                    if actual >= expected:
                        return True, ""
                    return False, f"JSONPath '{path}' expected >= {expected}, got {actual}"
                return False, f"JSONPath '{path}' numeric comparison failed on non-numeric values"

            if op == "lt":
                if (
                    isinstance(actual, (int, float))
                    and not isinstance(actual, bool)
                    and isinstance(expected, (int, float))
                    and not isinstance(expected, bool)
                ):
                    if actual < expected:
                        return True, ""
                    return False, f"JSONPath '{path}' expected < {expected}, got {actual}"
                return False, f"JSONPath '{path}' numeric comparison failed on non-numeric values"

            if op == "lte":
                if (
                    isinstance(actual, (int, float))
                    and not isinstance(actual, bool)
                    and isinstance(expected, (int, float))
                    and not isinstance(expected, bool)
                ):
                    if actual <= expected:
                        return True, ""
                    return False, f"JSONPath '{path}' expected <= {expected}, got {actual}"
                return False, f"JSONPath '{path}' numeric comparison failed on non-numeric values"

            if op == "contains":
                if isinstance(actual, str):
                    if isinstance(expected, (str, int, float)) and str(expected) in actual:
                        return True, ""
                    return (
                        False,
                        f"JSONPath '{path}' expected to contain '{expected}', got '{actual}'",
                    )
                if isinstance(actual, list):
                    if any(_are_values_equal(item, expected) for item in actual):
                        return True, ""
                    return False, f"JSONPath '{path}' expected to contain {expected}, got {actual}"
                if isinstance(actual, dict):
                    if str(expected) in actual:
                        return True, ""
                    return False, f"JSONPath '{path}' expected key '{expected}', got {actual}"
                actual_type = type(actual).__name__
                return False, f"JSONPath '{path}' cannot evaluate contains on type {actual_type}"

            if op == "matches":
                # DSL contract: bare pattern, no PHP-style delimiter stripping.
                pattern = str(expected)
                if actual is not None and bool(re.search(pattern, str(actual))):
                    return True, ""
                return False, f"JSONPath '{path}' expected regex match '{pattern}', got '{actual}'"

            if op == "type":
                exp_t = str(expected).lower()
                matched = False
                if exp_t in ("integer", "int"):
                    matched = isinstance(actual, int) and not isinstance(actual, bool)
                elif exp_t == "number":
                    matched = isinstance(actual, (int, float)) and not isinstance(actual, bool)
                elif exp_t in ("boolean", "bool"):
                    matched = isinstance(actual, bool)
                elif exp_t in ("string", "str"):
                    matched = isinstance(actual, str)
                elif exp_t in ("array", "list"):
                    matched = isinstance(actual, list)
                elif exp_t in ("object", "dict"):
                    matched = isinstance(actual, dict)
                elif exp_t == "null":
                    matched = actual is None

                if matched:
                    return True, ""
                actual_type_name = "null" if actual is None else type(actual).__name__
                return (
                    False,
                    f"JSONPath '{path}' expected type '{expected}', got '{actual_type_name}'",
                )

            return False, f"Unsupported json_path op '{op}'"

        if a_type == "response_time":
            lte_ms = int(assertion.get("lte_ms", assertion.get("max_ms", 1000)))
            if latency_ms <= lte_ms:
                return True, ""
            return False, f"Response time {latency_ms}ms exceeded max {lte_ms}ms"

        if a_type == "json_schema":
            try:
                body = resp.json()
            except Exception:
                return False, "Response body is not valid JSON for json_schema"

            schema = assertion.get("schema")
            schema_ref = assertion.get("schema_ref")

            if schema_ref and self.openapi_spec and schema_ref.startswith("#/components/schemas/"):
                sname = schema_ref.split("/")[-1]
                schemas = self.openapi_spec.get("components", {}).get("schemas", {})
                if sname in schemas:
                    defs = json.loads(
                        json.dumps(schemas).replace("#/components/schemas/", "#/$defs/")
                    )
                    schema = {
                        "$schema": "https://json-schema.org/draft/2020-12/schema",
                        "$defs": defs,
                        **defs[sname],
                    }

            if isinstance(schema, dict):
                try:
                    jsonschema.Draft202012Validator(schema).validate(body)
                    return True, ""
                except jsonschema.ValidationError as v_err:
                    return False, f"JSON schema validation failed: {v_err.message}"

        return False, f"Unsupported assertion type '{a_type}'"
