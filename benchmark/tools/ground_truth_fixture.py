"""Checks the ADR-0006 experiment fixture of ``benchmark/<slug>/ground-truth.yaml``.

ADR-0006 adds two optional top-level keys that experiments snapshot into ``experiments.config``:

- ``auth``: synthetic sandbox credentials (``scheme``, ``default_role``, ``roles`` with ``token``
  and ``description``, optional per-operation ``operations`` with ``role``/``forbidden_roles``);
- ``business_rules``: rules a tester can read in the spec/README (``code``, ``description``,
  optional ``method``/``path``), never bug answers.

The web side (``GroundTruthLoader``) enforces the same shape; this module also cross-checks the
fixture against the benchmark itself: every declared operation exists in ``openapi.yaml``, every
token is one the benchmark app accepts (``app/auth.py``), and no business rule names a seeded bug.

Business rules are also checked for fairness (S11-01, ADR-0006 "Hệ quả"):

- they use only public knowledge: every path a rule mentions is a path of ``openapi.yaml``, every
  HTTP status it mentions is declared by its operation (or by an operation of a path it mentions),
  and every error code (``UPPER_SNAKE_CASE``) appears in ``openapi.yaml`` or the benchmark README
  outside its seeded-bug sections (bug tables and toggles are answers, not public knowledge);
- they are balanced: every business operation (all but ``/health``) has at least one rule and none
  has more than ``MAX_RULES_PER_OPERATION``, so operations with and without seeded bugs are covered
  alike instead of rules clustering on the bugged ones.

Usage: ``python ground_truth_fixture.py`` prints the problems of every benchmark (exit 1 if any).
"""

from __future__ import annotations

import re
import sys
from pathlib import Path
from typing import Any

import yaml

BENCHMARK_DIR = Path(__file__).resolve().parent.parent

ROLE_PATTERN = re.compile(r"^[a-z][a-z0-9_]{0,30}$")
CODE_PATTERN = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{0,49}$")
METHODS = {"GET", "POST", "PUT", "PATCH", "DELETE", "HEAD", "OPTIONS"}
SUPPORTED_SCHEMES = {"bearer"}
# A business rule must describe expected behaviour, not point at a seeded bug or its toggle.
BUG_REFERENCE = re.compile(r"\bBUG[-_]?\d{3}\b", re.IGNORECASE)
# Public-knowledge checks of a rule description (S11-01).
RULE_PATH = re.compile(r"(?<![\w.])/[A-Za-z0-9_{}/-]*[A-Za-z0-9_}]")
RULE_STATUS = re.compile(r"(?<![\w.-])([2-5]\d\d)(?![\w-]|\.\d)")
RULE_ERROR_CODE = re.compile(r"\b[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+\b")
UNTESTED_PATHS = {"/health"}
MAX_RULES_PER_OPERATION = 2


def load(slug: str, root: Path = BENCHMARK_DIR) -> dict[str, Any]:
    with (root / slug / "ground-truth.yaml").open(encoding="utf-8") as file:
        data = yaml.safe_load(file)
    if not isinstance(data, dict):
        raise ValueError(f"{slug}: ground-truth.yaml must be a mapping")
    return data


def spec_operations(slug: str, root: Path = BENCHMARK_DIR) -> dict[tuple[str, str], bool]:
    """(METHOD, path) -> whether the operation requires a credential (effective security)."""
    with (root / slug / "openapi.yaml").open(encoding="utf-8") as file:
        spec = yaml.safe_load(file)
    global_security = spec.get("security") or []
    operations: dict[tuple[str, str], bool] = {}
    for path, item in (spec.get("paths") or {}).items():
        for method, operation in (item or {}).items():
            if method.upper() not in METHODS or not isinstance(operation, dict):
                continue
            security = operation.get("security", global_security) or []
            secured = bool(security) and all(bool(req) for req in security)
            operations[(method.upper(), path)] = secured
    return operations


def spec_statuses(slug: str, root: Path = BENCHMARK_DIR) -> dict[tuple[str, str], set[str]]:
    """(METHOD, path) -> HTTP status codes declared in the operation's responses."""
    with (root / slug / "openapi.yaml").open(encoding="utf-8") as file:
        spec = yaml.safe_load(file)
    statuses: dict[tuple[str, str], set[str]] = {}
    for path, item in (spec.get("paths") or {}).items():
        for method, operation in (item or {}).items():
            if method.upper() in METHODS and isinstance(operation, dict):
                responses = operation.get("responses") or {}
                statuses[(method.upper(), path)] = {str(code) for code in responses}
    return statuses


SEEDED_BUG_HEADING = re.compile(r"seeded[ _-]?bug", re.IGNORECASE)
MARKDOWN_HEADING = re.compile(r"^(#{1,6})\s")


def strip_seeded_bug_sections(markdown: str) -> str:
    """The README without its seeded-bug sections (bug tables, toggles).

    Those sections are answers, not public knowledge: a section whose heading mentions
    "seeded bug" is dropped together with its subsections.
    """
    kept: list[str] = []
    skip_level: int | None = None
    in_fence = False
    for line in markdown.splitlines():
        if line.lstrip().startswith("```"):
            in_fence = not in_fence
        heading = None if in_fence else MARKDOWN_HEADING.match(line)
        if heading is not None:
            level = len(heading.group(1))
            if skip_level is not None and level <= skip_level:
                skip_level = None
            if skip_level is None and SEEDED_BUG_HEADING.search(line):
                skip_level = level
        if skip_level is None:
            kept.append(line)
    return "\n".join(kept)


def public_text(slug: str, root: Path = BENCHMARK_DIR) -> str:
    """What a tester can read about the benchmark: its ``openapi.yaml`` and ``README.md`` without
    the seeded-bug sections of the README."""
    parts = []
    for name in ("openapi.yaml", "README.md"):
        path = root / slug / name
        if path.is_file():
            text = path.read_text(encoding="utf-8")
            parts.append(strip_seeded_bug_sections(text) if name == "README.md" else text)
    return "\n".join(parts)


def accepted_tokens(slug: str, root: Path = BENCHMARK_DIR) -> set[str]:
    """Quoted dictionary keys of the benchmark's token table in app/auth.py."""
    path = root / slug / "app" / "auth.py"
    if not path.is_file():
        return set()
    return set(re.findall(r'^\s*"([A-Za-z0-9._-]+)":\s*Identity\(', path.read_text(), re.M))


def validate(data: dict[str, Any], slug: str, root: Path = BENCHMARK_DIR) -> list[str]:
    """Problems of the ADR-0006 fixture of one benchmark (empty list when it is valid)."""
    errors: list[str] = []
    operations = spec_operations(slug, root)

    def check_operation(where: str, method: Any, path: Any) -> None:
        if not isinstance(method, str) or method.upper() not in METHODS:
            errors.append(f"{where}: method must be an HTTP method")
            return
        if not isinstance(path, str) or not path.startswith("/"):
            errors.append(f"{where}: path must start with /")
            return
        if (method.upper(), path) not in operations:
            errors.append(f"{where}: {method.upper()} {path} is not an operation of openapi.yaml")

    auth = data.get("auth")
    if auth is not None:
        errors.extend(_validate_auth(auth, slug, root, operations, check_operation))
    elif any(operations.values()):
        errors.append("auth: missing although openapi.yaml declares secured operations")

    rules = data.get("business_rules", [])
    if not isinstance(rules, list):
        return [*errors, "business_rules: must be a list"]
    codes: set[str] = set()
    for index, rule in enumerate(rules):
        where = f"business_rules[{index}]"
        if not isinstance(rule, dict):
            errors.append(f"{where}: must be a mapping")
            continue
        unknown = set(rule) - {"code", "description", "method", "path"}
        if unknown:
            errors.append(f"{where}: unknown keys {sorted(unknown)}")
        code = rule.get("code")
        if not isinstance(code, str) or not CODE_PATTERN.match(code):
            errors.append(f"{where}: code must match {CODE_PATTERN.pattern}")
        elif code in codes:
            errors.append(f"{where}: duplicate code {code}")
        else:
            codes.add(code)
        description = rule.get("description")
        if not isinstance(description, str) or not 1 <= len(description) <= 5000:
            errors.append(f"{where}: description must be a 1..5000 character string")
        elif BUG_REFERENCE.search(description):
            errors.append(f"{where}: description must not reference a seeded bug")
        if "method" in rule or "path" in rule:
            check_operation(where, rule.get("method"), rule.get("path"))
        if isinstance(description, str):
            errors.extend(_public_knowledge_problems(where, rule, description, slug, root))
    errors.extend(_coverage_problems(rules, operations))
    return errors


def _public_knowledge_problems(
    where: str, rule: dict[str, Any], description: str, slug: str, root: Path
) -> list[str]:
    """Paths, statuses and error codes of a rule that a tester could not read in spec/README."""
    errors: list[str] = []
    statuses = spec_statuses(slug, root)
    spec_paths = {path for _, path in statuses}
    mentioned = RULE_PATH.findall(description)
    own = (str(rule.get("method", "")).upper(), str(rule.get("path", "")))
    allowed = set(statuses.get(own, set()))
    for path in mentioned:
        if path not in spec_paths:
            errors.append(f"{where}: mentions {path}, which is not a path of openapi.yaml")
            continue
        for (_, spec_path), codes in statuses.items():
            if spec_path == path:
                allowed |= codes
    for status in RULE_STATUS.findall(description):
        if status not in allowed:
            errors.append(
                f"{where}: mentions HTTP {status}, which its operation does not declare "
                "in openapi.yaml"
            )
    text = public_text(slug, root)
    for code in sorted(set(RULE_ERROR_CODE.findall(description))):
        if code not in text:
            errors.append(
                f"{where}: error code {code} is not documented in openapi.yaml or README.md"
            )
    return errors


def _coverage_problems(rules: list[Any], operations: dict[tuple[str, str], bool]) -> list[str]:
    """Every business operation has a rule, and no operation collects more than its share."""
    if not rules:
        return []
    counts: dict[tuple[str, str], int] = {}
    for rule in rules:
        if isinstance(rule, dict) and isinstance(rule.get("method"), str):
            key = (rule["method"].upper(), str(rule.get("path", "")))
            counts[key] = counts.get(key, 0) + 1
    errors: list[str] = []
    for key in sorted(operations):
        if key[1] in UNTESTED_PATHS:
            continue
        if counts.get(key, 0) == 0:
            errors.append(f"business_rules: no rule covers {key[0]} {key[1]}")
        elif counts[key] > MAX_RULES_PER_OPERATION:
            errors.append(
                f"business_rules: {counts[key]} rules on {key[0]} {key[1]} "
                f"(at most {MAX_RULES_PER_OPERATION} per operation)"
            )
    return errors


def _validate_auth(
    auth: Any,
    slug: str,
    root: Path,
    operations: dict[tuple[str, str], bool],
    check_operation: Any,
) -> list[str]:
    errors: list[str] = []
    if not isinstance(auth, dict):
        return ["auth: must be a mapping"]
    unknown = set(auth) - {"scheme", "default_role", "roles", "operations"}
    if unknown:
        errors.append(f"auth: unknown keys {sorted(unknown)}")
    if auth.get("scheme") not in SUPPORTED_SCHEMES:
        errors.append(f"auth.scheme: must be one of {sorted(SUPPORTED_SCHEMES)}")
    roles = auth.get("roles")
    if not isinstance(roles, dict) or not roles:
        return [*errors, "auth.roles: must be a non-empty mapping"]
    tokens = accepted_tokens(slug, root)
    seen_tokens: set[str] = set()
    for name, role in roles.items():
        where = f"auth.roles.{name}"
        if not isinstance(name, str) or not ROLE_PATTERN.match(name):
            errors.append(f"{where}: role name must match {ROLE_PATTERN.pattern}")
        if not isinstance(role, dict) or set(role) - {"token", "description"}:
            errors.append(f"{where}: must be a mapping with token and optional description")
            continue
        token = role.get("token")
        if not isinstance(token, str) or not token:
            errors.append(f"{where}.token: must be a non-empty string")
        elif token not in tokens:
            errors.append(f"{where}.token: not a token accepted by {slug}/app/auth.py")
        elif token in seen_tokens:
            errors.append(f"{where}.token: token used by two roles")
        else:
            seen_tokens.add(token)
        if "description" in role and not isinstance(role["description"], str):
            errors.append(f"{where}.description: must be a string")
    if auth.get("default_role") not in roles:
        errors.append("auth.default_role: must be one of auth.roles")

    entries = auth.get("operations", [])
    if not isinstance(entries, list):
        return [*errors, "auth.operations: must be a list"]
    seen_ops: set[tuple[str, str]] = set()
    for index, entry in enumerate(entries):
        where = f"auth.operations[{index}]"
        if not isinstance(entry, dict):
            errors.append(f"{where}: must be a mapping")
            continue
        extra = set(entry) - {"method", "path", "role", "forbidden_roles"}
        if extra:
            errors.append(f"{where}: unknown keys {sorted(extra)}")
        check_operation(where, entry.get("method"), entry.get("path"))
        key = (str(entry.get("method", "")).upper(), str(entry.get("path", "")))
        if key in seen_ops:
            errors.append(f"{where}: operation declared twice")
        seen_ops.add(key)
        if operations.get(key) is False:
            errors.append(f"{where}: {key[0]} {key[1]} is public in openapi.yaml")
        role = entry.get("role")
        if role is not None and role not in roles:
            errors.append(f"{where}.role: unknown role {role}")
        forbidden = entry.get("forbidden_roles", [])
        if not isinstance(forbidden, list) or any(r not in roles for r in forbidden):
            errors.append(f"{where}.forbidden_roles: must list declared roles")
        elif (role or auth.get("default_role")) in forbidden:
            errors.append(f"{where}.forbidden_roles: contains the role used for the operation")
    return errors


def main() -> int:
    failed = False
    for gt in sorted(BENCHMARK_DIR.glob("*/ground-truth.yaml")):
        slug = gt.parent.name
        for problem in validate(load(slug), slug):
            failed = True
            print(f"{slug}: {problem}")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
