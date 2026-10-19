"""Tests for BenchmarkAdapter across simple-store, ecommerce-order, and appointment-booking."""

import pytest
from benchmark.runner.adapter import BenchmarkAdapter


@pytest.mark.parametrize(
    ("slug", "expected_bugs"),
    [
        ("simple-store", 10),
        ("ecommerce-order", 12),
        ("appointment-booking", 10),
    ],
)
def test_adapter_loads_all_benchmarks_and_bug_definitions(slug: str, expected_bugs: int) -> None:
    adapter = BenchmarkAdapter(slug)
    assert adapter.slug == slug
    assert len(adapter.bugs) == expected_bugs
    assert adapter.total_bugs == expected_bugs
    assert adapter.app is not None
    assert adapter.client is not None
    assert adapter.openapi_spec is not None
    assert "paths" in adapter.openapi_spec


def test_adapter_enforces_single_fault_isolation() -> None:
    adapter = BenchmarkAdapter("simple-store")

    # Clean state
    adapter.set_clean_state()
    for bug in adapter.bugs:
        attr = bug.toggle_env.lower()
        assert getattr(adapter.settings, attr) is False

    # Activate BUG-002
    adapter.set_bug_state("BUG-002")
    assert adapter.settings.bug_002 is True
    # All other bugs MUST be False
    for bug in adapter.bugs:
        if bug.code != "BUG-002":
            attr = bug.toggle_env.lower()
            assert getattr(adapter.settings, attr) is False

    # Isolated context manager
    with adapter.isolated_bug("BUG-005"):
        assert adapter.settings.bug_005 is True
        assert adapter.settings.bug_002 is False

    # Automatically clean after context exit
    assert adapter.settings.bug_005 is False
    assert adapter.settings.bug_002 is False


def test_adapter_rejects_unknown_benchmark() -> None:
    with pytest.raises(ValueError, match="Unknown benchmark"):
        BenchmarkAdapter("unknown-benchmark")
