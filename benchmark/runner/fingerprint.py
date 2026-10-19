"""RFC 8785 JSON Canonicalization Scheme (JCS) and DSL Fingerprint calculation.

Deterministic SHA-256 fingerprints across Test DSL execution semantics.
Conforms to Decision D04, docs/DESIGN.md §10.5, and RFC 8785.
"""

from __future__ import annotations

import hashlib
import json
import math
from typing import Any

IGNORED_ROOT_KEYS: frozenset[str] = frozenset(
    {
        "title",
        "description",
        "tags",
        "fingerprint",
        "id",
        "case_id",
        "test_suite_id",
        "endpoint_id",
        "generation_job_id",
        "created_at",
        "updated_at",
        "status",
        "validation_errors",
        "repair_attempts",
    }
)

PHP_INT_MIN: int = -9223372036854775808
PHP_INT_MAX: int = 9223372036854775807


class DslFingerprintService:
    """Calculates canonical JSON and deterministic SHA-256 fingerprints according to RFC 8785."""

    def __init__(self, ignored_root_keys: frozenset[str] | set[str] = IGNORED_ROOT_KEYS) -> None:
        self.ignored_root_keys = frozenset(ignored_root_keys)

    def calculate(self, dsl: dict[str, Any] | str | list[Any]) -> str:
        """Calculate the canonical SHA-256 fingerprint for a Test DSL payload."""
        canonical_json = self.to_canonical_json(dsl)
        return hashlib.sha256(canonical_json.encode("utf-8")).hexdigest()

    def to_canonical_json(self, dsl: dict[str, Any] | str | list[Any]) -> str:
        """Convert Test DSL into a deterministic, compact canonical JSON string."""
        payload = dsl

        if isinstance(payload, str):
            try:
                decoded = json.loads(payload)
            except Exception as e:
                raise ValueError(
                    f"Malformed JSON provided for Test DSL fingerprint calculation: {e}"
                ) from e

            if not isinstance(decoded, (dict, list)):
                raise ValueError("Test DSL string must decode to a JSON object or array.")
            payload = decoded

        # Unwrap if wrapped inside a record with 'dsl' key
        if isinstance(payload, dict) and "dsl" in payload:
            inner = payload["dsl"]
            if isinstance(inner, str):
                try:
                    payload = json.loads(inner)
                except Exception as e:
                    raise ValueError(f"Malformed JSON in wrapped dsl field: {e}") from e
            elif isinstance(inner, (dict, list)):
                payload = inner

        if not payload and isinstance(payload, (dict, list)):
            raise ValueError("Test DSL payload cannot be empty.")

        return self.serialize(payload, is_root=True)

    def serialize(self, data: Any, is_root: bool = False) -> str:
        """Recursively serialize any data value according to RFC 8785 rules."""
        if data is None:
            return "null"

        if isinstance(data, bool):
            return "true" if data else "false"

        if isinstance(data, int):
            if data < PHP_INT_MIN or data > PHP_INT_MAX:
                try:
                    f_val = float(data)
                except OverflowError:
                    raise ValueError(
                        f"Integer exceeds 64-bit float representation limits: {data}"
                    ) from None
                return self.format_number(f_val)
            return str(data)

        if isinstance(data, float):
            return self.format_number(data)

        if isinstance(data, str):
            return self.serialize_string(data)

        if isinstance(data, list):
            elements = [self.serialize(item, is_root=False) for item in data]
            return "[" + ",".join(elements) + "]"

        if isinstance(data, dict):
            properties: dict[str, Any] = dict(data)
            if is_root:
                for key in self.ignored_root_keys:
                    properties.pop(key, None)

            if not properties:
                return "{}"

            sorted_keys = sorted(properties.keys(), key=lambda k: str(k).encode("utf-16-be"))

            pairs = [
                f"{self.serialize_string(str(k))}:{self.serialize(properties[k], is_root=False)}"
                for k in sorted_keys
            ]
            return "{" + ",".join(pairs) + "}"

        raise ValueError(f"Unsupported data type for canonicalization: {type(data).__name__}")

    def serialize_string(self, value: str) -> str:
        """Serialize a string according to RFC 8785 Section 3.2.2.2."""
        result: list[str] = ['"']
        for ch in value:
            code = ord(ch)
            if code == 0x22:  # "
                result.append('\\"')
            elif code == 0x5C:  # \
                result.append("\\\\")
            elif code == 0x08:  # \b
                result.append("\\b")
            elif code == 0x09:  # \t
                result.append("\\t")
            elif code == 0x0A:  # \n
                result.append("\\n")
            elif code == 0x0C:  # \f
                result.append("\\f")
            elif code == 0x0D:  # \r
                result.append("\\r")
            elif code < 0x20:
                result.append(f"\\u{code:04x}")
            else:
                result.append(ch)
        result.append('"')
        return "".join(result)

    def format_number(self, value: float) -> str:
        """Format floating point numbers according to RFC 8785 / ECMA-262 §7.1.12.1."""
        if math.isnan(value) or math.isinf(value):
            raise ValueError("Non-finite numbers (NaN/Infinity) are not permitted in JSON.")

        if value == 0.0:
            return "0"

        sign = "-" if math.copysign(1.0, value) < 0 else ""
        abs_val = abs(value)

        s = repr(abs_val)
        if "e" in s:
            mantissa, exp_str = s.split("e")
            exp = int(exp_str)
        else:
            mantissa = s
            exp = 0

        if "." in mantissa:
            int_p, frac_p = mantissa.split(".")
            digits = int_p + frac_p
            n = len(int_p) + exp
        else:
            digits = mantissa
            n = len(digits) + exp

        leading_zeros = len(digits) - len(digits.lstrip("0"))
        digits = digits.lstrip("0")
        if not digits:
            digits = "0"
            n = 0
        else:
            n -= leading_zeros

        digits = digits.rstrip("0")
        k = len(digits)

        if k <= n <= 21:
            return sign + digits + ("0" * (n - k))

        if 0 < n <= 21:
            return sign + digits[:n] + "." + digits[n:]

        if -6 < n <= 0:
            return sign + "0." + ("0" * (-n)) + digits

        e = n - 1
        exp_sign = "+" if e >= 0 else "-"
        exp_formatted = f"e{exp_sign}{abs(e)}"

        if k == 1:
            return sign + digits + exp_formatted

        return sign + digits[0] + "." + digits[1:] + exp_formatted

    @staticmethod
    def is_valid_fingerprint(fingerprint: str | None) -> bool:
        """Check if a given string matches the expected 64-char lowercase hex format."""
        if fingerprint is None or len(fingerprint) != 64:
            return False
        return all(c in "0123456789abcdef" for c in fingerprint)
