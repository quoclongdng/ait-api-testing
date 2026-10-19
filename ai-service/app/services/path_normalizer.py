"""Deterministic clean-up of request paths written by an LLM (S11-02).

Small local models often write a concrete or templated value straight into the path
(``"/products/{{product_id}}"`` or ``"/products/1"``) instead of the declared template plus
``path_params``. The case is otherwise fine, so the path is rewritten to the declared
template and the value moved to ``path_params`` before validation. Nothing is invented:
a path is only rewritten when exactly one declared template of the same method matches it
segment by segment, and an existing ``path_params`` entry is never overwritten.
"""

import re
from typing import Any

_TEMPLATE_SEGMENT = re.compile(r"^\{([^{}]+)\}$")
_INTEGER = re.compile(r"^-?[0-9]{1,15}$")


def _segments(path: str) -> list[str]:
    return path.strip("/").split("/") if path.strip("/") else []


def _match(path: str, template: str) -> dict[str, str] | None:
    """Return the template parameter values of ``path`` or None when it does not match."""
    actual, declared = _segments(path), _segments(template)
    if len(actual) != len(declared):
        return None
    values: dict[str, str] = {}
    for value, segment in zip(actual, declared, strict=True):
        param = _TEMPLATE_SEGMENT.match(segment)
        if param is None:
            if value != segment:
                return None
            continue
        if not value:
            return None
        values[param.group(1)] = value
    return values


_NUMERIC_TYPES = frozenset({"integer", "number"})


def _declared_type(schema: Any) -> str | None:
    """``numeric`` / ``other`` for a declared parameter schema, None when it is unknown."""
    if not isinstance(schema, dict) or "type" not in schema:
        return None
    declared = schema["type"]
    types = declared if isinstance(declared, list) else [declared]
    types = [t for t in types if t != "null"]
    if types and all(t in _NUMERIC_TYPES for t in types):
        return "numeric"
    return "other"


def _param_value(raw: str, declared: str | None) -> str | int:
    """Path parameter value written by the model.

    ``{{var}}`` placeholders and every value of a string-typed parameter stay strings
    (``"00042"`` is not ``42``). Digits become an int only when the parameter is declared
    numeric, or its type is unknown, and the text is the canonical form of that int (no
    leading zeros, no ``-0``), so the request sent is exactly the one the model wrote.
    """
    if declared == "other" or not _INTEGER.match(raw):
        return raw
    value = int(raw)
    return value if str(value) == raw else raw


def _path_param_types(ep: dict[str, Any]) -> dict[str, str | None]:
    types: dict[str, str | None] = {}
    parameters = ep.get("parameters")
    if isinstance(parameters, list):
        for param in parameters:
            if isinstance(param, dict) and param.get("in") == "path":
                name = param.get("name")
                if isinstance(name, str):
                    types[name] = _declared_type(param.get("schema"))
    return types


def _templates(spec_endpoints: list[dict[str, Any]]) -> dict[str, list[str]]:
    by_method: dict[str, list[str]] = {}
    for ep in spec_endpoints:
        method, path = ep.get("method"), ep.get("path")
        if isinstance(method, str) and isinstance(path, str):
            by_method.setdefault(method.upper(), []).append(path)
    return by_method


def _param_types(spec_endpoints: list[dict[str, Any]]) -> dict[tuple[str, str], dict[str, Any]]:
    by_endpoint: dict[tuple[str, str], dict[str, Any]] = {}
    for ep in spec_endpoints:
        method, path = ep.get("method"), ep.get("path")
        if isinstance(method, str) and isinstance(path, str):
            by_endpoint[(method.upper(), path)] = _path_param_types(ep)
    return by_endpoint


def _resolve(
    method: Any, path: Any, templates: dict[str, list[str]]
) -> tuple[str, dict[str, str]] | None:
    """Declared template and extracted values for an undeclared path, if unambiguous."""
    if not isinstance(method, str) or not isinstance(path, str):
        return None
    candidates = templates.get(method.upper(), [])
    if path in candidates:
        return None
    matches = [(t, v) for t in candidates if (v := _match(path, t)) is not None]
    if len(matches) != 1:
        return None
    return matches[0]


def normalize_request_paths(dsl: Any, spec_endpoints: list[dict[str, Any]]) -> Any:
    """Rewrite undeclared concrete paths of ``dsl`` to declared templates (in place)."""
    if not isinstance(dsl, dict):
        return dsl
    templates = _templates(spec_endpoints)
    param_types = _param_types(spec_endpoints)

    endpoint = dsl.get("endpoint")
    if isinstance(endpoint, dict):
        resolved = _resolve(endpoint.get("method"), endpoint.get("path"), templates)
        if resolved is not None:
            endpoint["path"] = resolved[0]

    steps = dsl.get("steps")
    if not isinstance(steps, list):
        return dsl
    for step in steps:
        request = step.get("request") if isinstance(step, dict) else None
        if not isinstance(request, dict):
            continue
        resolved = _resolve(request.get("method"), request.get("path"), templates)
        if resolved is None:
            continue
        template, values = resolved
        params = request.get("path_params")
        if params is None:
            params = {}
        if not isinstance(params, dict):
            continue
        for name, raw in values.items():
            # A "{other}" segment is the template with another parameter name: its value,
            # if given, is renamed. Without one nothing is moved (the validator reports the
            # missing path parameter, which the repair loop can fix).
            renamed = _TEMPLATE_SEGMENT.match(raw)
            if renamed is not None:
                other = renamed.group(1)
                if other != name and other in params and name not in params:
                    params[name] = params.pop(other)
                continue
            declared = param_types.get((str(request["method"]).upper(), template), {})
            params.setdefault(name, _param_value(raw, declared.get(name)))
        request["path"] = template
        if params:
            request["path_params"] = params
    return dsl
