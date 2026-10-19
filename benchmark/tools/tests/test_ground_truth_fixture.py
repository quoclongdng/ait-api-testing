"""Tests for the ADR-0006 ground-truth fixture (auth roles + business rules) [S11-02]."""

import copy
from typing import Any

import pytest

import ground_truth_fixture as fx

SLUGS = ["appointment-booking", "ecommerce-order", "simple-store"]


@pytest.mark.parametrize("slug", SLUGS)
def test_every_shipped_ground_truth_fixture_is_valid(slug: str) -> None:
    assert fx.validate(fx.load(slug), slug) == []


@pytest.mark.parametrize("slug", SLUGS)
def test_every_benchmark_declares_business_rules(slug: str) -> None:
    rules = fx.load(slug)["business_rules"]
    assert len(rules) >= 3
    assert all(rule["code"].startswith("BR-") for rule in rules)


def test_secured_benchmarks_declare_tokens_their_app_accepts() -> None:
    for slug in ["ecommerce-order", "appointment-booking"]:
        auth = fx.load(slug)["auth"]
        tokens = {role["token"] for role in auth["roles"].values()}
        assert tokens == fx.accepted_tokens(slug), slug
        assert auth["scheme"] == "bearer"


def test_public_benchmark_has_no_auth_fixture() -> None:
    assert "auth" not in fx.load("simple-store")
    assert not any(fx.spec_operations("simple-store").values())


def test_admin_listing_is_admin_only_for_role_based_auth_cases() -> None:
    ops = {(o["method"], o["path"]): o for o in fx.load("ecommerce-order")["auth"]["operations"]}
    admin = ops[("GET", "/admin/orders")]
    assert admin["role"] == "admin"
    assert admin["forbidden_roles"] == ["customer"]


def _ecommerce() -> dict[str, Any]:
    return copy.deepcopy(fx.load("ecommerce-order"))


@pytest.mark.parametrize(
    ("mutate", "expected"),
    [
        (lambda d: d["auth"]["roles"]["customer"].update({"token": "forged"}), "not a token"),
        (lambda d: d["auth"].update(default_role="ghost"), "default_role"),
        (lambda d: d["auth"].update(scheme="basic"), "auth.scheme"),
        (lambda d: d["auth"]["roles"].update({"Bad-Role": {"token": "x"}}), "role name"),
        (
            lambda d: d["auth"]["operations"].append({"method": "GET", "path": "/nope"}),
            "is not an operation",
        ),
        (
            lambda d: d["auth"]["operations"].append({"method": "GET", "path": "/products"}),
            "is public",
        ),
        (
            lambda d: d["auth"]["operations"][0].update(forbidden_roles=["admin"]),
            "contains the role used",
        ),
        (lambda d: d["business_rules"][0].update(description="See BUG-011"), "seeded bug"),
        (lambda d: d["business_rules"].append(dict(d["business_rules"][0])), "duplicate code"),
        (lambda d: d["business_rules"][0].update(path="/missing"), "is not an operation"),
        (lambda d: d.pop("auth"), "auth: missing"),
        # S11-01: rules use only what a tester can read in openapi.yaml/README.
        (
            lambda d: d["business_rules"][3].update(
                description="Too much stock returns 409 INSUFFICIENT_STOCK."
            ),
            "error code INSUFFICIENT_STOCK is not documented",
        ),
        (
            lambda d: d["business_rules"][3].update(description="See GET /cart/summary."),
            "/cart/summary, which is not a path",
        ),
        (
            lambda d: d["business_rules"][0].update(description="A bad request returns 400."),
            "mentions HTTP 400",
        ),
        # S11-01: rules cover every business operation, at most two per operation.
        (lambda d: d["business_rules"].pop(0), "no rule covers GET /products"),
        (
            lambda d: d["business_rules"].extend(
                {**d["business_rules"][3], "code": f"BR-EO-9{i}"} for i in range(2)
            ),
            "3 rules on POST /cart/items",
        ),
    ],
)
def test_invalid_fixtures_are_reported(mutate: Any, expected: str) -> None:
    data = _ecommerce()
    mutate(data)
    errors = fx.validate(data, "ecommerce-order")
    assert any(expected in error for error in errors), errors


@pytest.mark.parametrize("slug", SLUGS)
def test_rules_cover_operations_with_and_without_seeded_bugs(slug: str) -> None:
    data = fx.load(slug)
    covered = {(r["method"], r["path"]) for r in data["business_rules"]}
    bugged = {(b["method"], b["path"]) for b in data["bugs"]}
    business = {op for op in fx.spec_operations(slug) if op[1] not in fx.UNTESTED_PATHS}
    assert covered == business
    # Both kinds of operations are covered, so rules do not cluster on the bugged ones.
    assert covered & bugged
    assert covered - bugged


def test_status_mentioned_for_another_documented_path_is_allowed() -> None:
    data = copy.deepcopy(fx.load("simple-store"))
    rule = next(r for r in data["business_rules"] if r["method"] == "DELETE")
    assert "GET /products/{id}" in rule["description"]
    assert fx.validate(data, "simple-store") == []


def test_error_code_documented_in_readme_is_allowed(tmp_path: Any) -> None:
    import shutil

    shutil.copytree(
        fx.BENCHMARK_DIR / "ecommerce-order",
        tmp_path / "ecommerce-order",
        ignore=shutil.ignore_patterns(".venv", "__pycache__"),
    )
    data = _ecommerce()
    data["business_rules"][3]["description"] = "Too much stock returns 409 INSUFFICIENT_STOCK."
    assert any("INSUFFICIENT_STOCK" in e for e in fx.validate(data, "ecommerce-order", tmp_path))
    readme = tmp_path / "ecommerce-order" / "README.md"
    readme.write_text(readme.read_text() + "\nMã lỗi tồn kho: `INSUFFICIENT_STOCK`.\n")
    assert fx.validate(data, "ecommerce-order", tmp_path) == []


def test_seeded_bug_sections_of_the_readme_are_not_public(tmp_path: Any) -> None:
    import shutil

    shutil.copytree(
        fx.BENCHMARK_DIR / "ecommerce-order",
        tmp_path / "ecommerce-order",
        ignore=shutil.ignore_patterns(".venv", "__pycache__"),
    )
    data = _ecommerce()
    data["business_rules"][3]["description"] = "Too much stock returns 409 SECRET_BUG_ONLY_CODE."
    readme = tmp_path / "ecommerce-order" / "README.md"
    text = readme.read_text()
    assert "## Bảng danh mục 12 Seeded Bugs" in text
    # Only the seeded-bug table mentions the code: still not public knowledge.
    heading = "## Bảng danh mục 12 Seeded Bugs (`ground-truth.yaml`)\n"
    readme.write_text(text.replace(heading, heading + "\n`SECRET_BUG_ONLY_CODE`\n", 1))
    assert "SECRET_BUG_ONLY_CODE" in readme.read_text()
    assert any("SECRET_BUG_ONLY_CODE" in e for e in fx.validate(data, "ecommerce-order", tmp_path))
    # Category codes of the bug table are stripped too.
    assert "BUSINESS_RULE_VIOLATION" not in fx.public_text("ecommerce-order")


def test_strip_seeded_bug_sections_keeps_other_sections() -> None:
    text = (
        "# T\n## A\nkeep\n## 3. Seeded Bug toggles\ndrop\n### sub\ndrop too\n"
        "```\n## not a heading\n```\n## B\nkeep2\n"
    )
    assert fx.strip_seeded_bug_sections(text) == "# T\n## A\nkeep\n## B\nkeep2"


def test_main_reports_no_problem_for_the_repository() -> None:
    assert fx.main() == 0
