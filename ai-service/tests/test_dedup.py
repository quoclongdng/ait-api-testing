"""Tests for DslDeduplicationService."""

import pytest

from app.services.dedup import DslDeduplicationService
from app.services.fingerprint import DslFingerprintService


@pytest.fixture
def dedup_service() -> DslDeduplicationService:
    return DslDeduplicationService()


def test_batch_dedup_identifies_duplicates_within_batch(
    dedup_service: DslDeduplicationService,
) -> None:
    case_1 = {
        "title": "First case",
        "dsl_version": "1.0",
        "endpoint": {"method": "GET", "path": "/users"},
        "steps": [{"name": "step1", "request": {"method": "GET", "path": "/users"}}],
    }
    case_2_dup = {
        "title": "Second case with different title but identical execution",
        "description": "Some extra desc",
        "dsl_version": "1.0",
        "endpoint": {"method": "GET", "path": "/users"},
        "steps": [{"name": "step1", "request": {"method": "GET", "path": "/users"}}],
    }
    case_3_unique = {
        "title": "Third unique case",
        "dsl_version": "1.0",
        "endpoint": {"method": "POST", "path": "/users"},
        "steps": [{"name": "step1", "request": {"method": "POST", "path": "/users"}}],
    }

    result = dedup_service.deduplicate_batch([case_1, case_2_dup, case_3_unique])

    assert result.total == 3
    assert result.unique_count == 2
    assert result.duplicate_count == 1
    assert result.redundancy_rate == round(1 / 3, 4)

    assert len(result.uniques) == 2
    assert len(result.duplicates) == 1

    assert result.processed[0]["status"] == "VALID"
    assert result.processed[1]["status"] == "DUPLICATE"
    assert result.processed[2]["status"] == "VALID"

    # Verify fingerprints are populated
    for item in result.processed:
        assert DslFingerprintService.is_valid_fingerprint(item.get("fingerprint"))


def test_batch_dedup_against_known_fingerprints(dedup_service: DslDeduplicationService) -> None:
    case = {
        "title": "Existing case in database",
        "dsl_version": "1.0",
        "endpoint": {"method": "GET", "path": "/items"},
        "steps": [{"name": "s", "request": {"method": "GET", "path": "/items"}}],
    }
    fp = dedup_service.get_fingerprint(case)

    # When known_fingerprints contains fp, the first occurrence is already DUPLICATE
    result = dedup_service.deduplicate_batch([case], known_fingerprints={fp})

    assert result.total == 1
    assert result.unique_count == 0
    assert result.duplicate_count == 1
    assert result.redundancy_rate == 1.0
    assert result.processed[0]["status"] == "DUPLICATE"


def test_is_duplicate(dedup_service: DslDeduplicationService) -> None:
    dsl = {
        "dsl_version": "1.0",
        "endpoint": {"method": "GET", "path": "/ping"},
        "steps": [{"name": "ping", "request": {"method": "GET", "path": "/ping"}}],
    }
    fp = dedup_service.get_fingerprint(dsl)

    assert dedup_service.is_duplicate(dsl, known_fingerprints=[fp]) is True
    assert dedup_service.is_duplicate(dsl, known_fingerprints={"other_hash"}) is False


def test_wrapped_dsl_dedup(dedup_service: DslDeduplicationService) -> None:
    item_wrapped = {
        "status": "VALID",
        "dsl": {
            "title": "Wrapped case",
            "dsl_version": "1.0",
            "endpoint": {"method": "GET", "path": "/health"},
            "steps": [{"name": "h", "request": {"method": "GET", "path": "/health"}}],
        },
    }
    item_dup = {
        "status": "VALID",
        "dsl": {
            "title": "Another wrapped case with same body",
            "dsl_version": "1.0",
            "endpoint": {"method": "GET", "path": "/health"},
            "steps": [{"name": "h", "request": {"method": "GET", "path": "/health"}}],
        },
    }

    result = dedup_service.deduplicate_batch([item_wrapped, item_dup])
    assert result.total == 2
    assert result.unique_count == 1
    assert result.duplicate_count == 1
    assert result.processed[1]["status"] == "DUPLICATE"


def test_batch_dedup_handles_invalid_case_with_empty_dsl_without_crashing(
    dedup_service: DslDeduplicationService,
) -> None:
    """A batch containing INVALID items with empty DSL must not crash or halt the batch."""
    case_invalid_empty = {
        "status": "INVALID",
        "dsl": {},
        "validation_errors": ["Schema validation failed: empty object"],
    }
    case_invalid_corrupted = {
        "status": "INVALID",
        "dsl": {"some": "corrupted"},
        "validation_errors": ["Missing required fields: endpoint, steps"],
    }
    case_valid = {
        "status": "VALID",
        "dsl": {
            "dsl_version": "1.0",
            "endpoint": {"method": "GET", "path": "/users"},
            "steps": [{"name": "step1", "request": {"method": "GET", "path": "/users"}}],
        },
    }

    result = dedup_service.deduplicate_batch(
        [case_invalid_empty, case_invalid_corrupted, case_valid]
    )

    assert result.total == 3
    assert result.unique_count == 1
    assert result.duplicate_count == 0

    assert result.processed[0]["status"] == "INVALID"
    assert result.processed[0]["fingerprint"] is None
    assert result.processed[0]["validation_errors"] == ["Schema validation failed: empty object"]

    assert result.processed[1]["status"] == "INVALID"
    assert result.processed[1]["validation_errors"] == ["Missing required fields: endpoint, steps"]

    assert result.processed[2]["status"] == "VALID"
    assert DslFingerprintService.is_valid_fingerprint(result.processed[2]["fingerprint"])
