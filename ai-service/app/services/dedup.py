"""Test DSL Deduplication Service for AI Service.

Unified with PHP TestDeduplicationService (S3-02) for deterministic deduplication
and redundancy rate computation.
"""

from __future__ import annotations

import copy
from dataclasses import dataclass, field
from typing import Any

from app.services.fingerprint import DslFingerprintService


@dataclass
class DeduplicationResult:
    """Outcome of deduplication evaluation on a batch of test cases."""

    total: int
    unique_count: int
    duplicate_count: int
    redundancy_rate: float
    processed: list[dict[str, Any]] = field(default_factory=list)
    uniques: list[dict[str, Any]] = field(default_factory=list)
    duplicates: list[dict[str, Any]] = field(default_factory=list)


class DslDeduplicationService:
    """Identifies duplicate test cases using canonical SHA-256 fingerprints."""

    def __init__(self, fingerprint_service: DslFingerprintService | None = None) -> None:
        self.fingerprint_service = fingerprint_service or DslFingerprintService()

    def deduplicate_batch(
        self,
        items: list[dict[str, Any]],
        known_fingerprints: set[str] | list[str] | None = None,
    ) -> DeduplicationResult:
        """Deduplicate a batch of test cases.

        Evaluates uniqueness against both external/known fingerprints (e.g. from DB)
        and within the current batch.
        """
        known_set: set[str] = set(known_fingerprints or ())
        batch_seen: set[str] = set()

        processed: list[dict[str, Any]] = []
        uniques: list[dict[str, Any]] = []
        duplicates: list[dict[str, Any]] = []

        for item in items:
            record = copy.deepcopy(item)
            dsl_payload = record.get("dsl", record)

            fingerprint: str | None = None
            if dsl_payload and isinstance(dsl_payload, (dict, list)):
                try:
                    fingerprint = self.fingerprint_service.calculate(dsl_payload)
                except Exception:
                    fingerprint = None

            record["fingerprint"] = fingerprint

            # If item is already marked INVALID (e.g. from validator), keep it and its errors
            if record.get("status") == "INVALID":
                processed.append(record)
                continue

            # If canonicalization failed for a non-INVALID item, mark it INVALID
            if fingerprint is None:
                record["status"] = "INVALID"
                errs = record.setdefault("validation_errors", [])
                if not errs:
                    errs.append("Failed to compute canonical fingerprint for test case payload")
                processed.append(record)
                continue

            is_dup = (fingerprint in known_set) or (fingerprint in batch_seen)

            if is_dup:
                record["status"] = "DUPLICATE"
                duplicates.append(record)
            else:
                batch_seen.add(fingerprint)
                record["status"] = "VALID"
                uniques.append(record)

            processed.append(record)

        total = len(items)
        duplicate_count = len(duplicates)
        unique_count = len(uniques)
        redundancy_rate = round(duplicate_count / total, 4) if total > 0 else 0.0

        return DeduplicationResult(
            total=total,
            unique_count=unique_count,
            duplicate_count=duplicate_count,
            redundancy_rate=redundancy_rate,
            processed=processed,
            uniques=uniques,
            duplicates=duplicates,
        )

    def is_duplicate(
        self,
        dsl: dict[str, Any] | str,
        known_fingerprints: set[str] | list[str],
    ) -> bool:
        """Check whether a single Test DSL collides with any known fingerprint."""
        fingerprint = self.fingerprint_service.calculate(dsl)
        return fingerprint in (
            set(known_fingerprints)
            if not isinstance(known_fingerprints, set)
            else known_fingerprints
        )

    def get_fingerprint(self, dsl: dict[str, Any] | str) -> str:
        """Compute the fingerprint for a DSL payload."""
        return self.fingerprint_service.calculate(dsl)


# Compatibility aliases matching PHP naming convention
TestDeduplicationService = DslDeduplicationService
