"""Configuration settings for simple-store benchmark API."""

import os
from typing import Any

from pydantic import field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    app_name: str = "Simple Store Benchmark API"
    app_version: str = "1.0.0"
    debug: bool = False

    @field_validator("debug", mode="before")
    @classmethod
    def _parse_debug(cls, v: Any) -> bool:
        if isinstance(v, str):
            return v.lower() in ("1", "true", "yes", "on")
        return bool(v)

    # Seeded bug toggles (disabled by default in S1-06 clean baseline, activated in S2-04)
    bug_001: bool = False  # Missing validation: allow product without name
    bug_002: bool = False  # Boundary error: allow negative price
    bug_003: bool = False  # Wrong status code: return 200 instead of 201 on create product
    bug_004: bool = False  # Wrong status code: return 200 with {} on product not found
    bug_005: bool = False  # Schema mismatch: omit required sku in product response
    bug_006: bool = False  # Boundary error: allow quantity > 100,000 on product update
    bug_007: bool = False  # Missing validation: allow limit <= 0 or > 100 on list products
    bug_008: bool = False  # Business rule violation: allow duplicate category slug
    bug_009: bool = False  # Wrong status code: return 200 instead of 204
    bug_010: bool = False  # Missing validation: unhandled string id causing 500 error

    model_config = SettingsConfigDict(
        extra="ignore",
        env_prefix="",
        case_sensitive=False,
    )


def get_settings() -> Settings:
    return Settings(
        bug_001=os.getenv("BUG_001", "0") in ("1", "true", "True"),
        bug_002=os.getenv("BUG_002", "0") in ("1", "true", "True"),
        bug_003=os.getenv("BUG_003", "0") in ("1", "true", "True"),
        bug_004=os.getenv("BUG_004", "0") in ("1", "true", "True"),
        bug_005=os.getenv("BUG_005", "0") in ("1", "true", "True"),
        bug_006=os.getenv("BUG_006", "0") in ("1", "true", "True"),
        bug_007=os.getenv("BUG_007", "0") in ("1", "true", "True"),
        bug_008=os.getenv("BUG_008", "0") in ("1", "true", "True"),
        bug_009=os.getenv("BUG_009", "0") in ("1", "true", "True"),
        bug_010=os.getenv("BUG_010", "0") in ("1", "true", "True"),
    )


def is_bug_active(bug_name: str) -> bool:
    """Check if a bug toggle is currently active via settings or environment variable."""
    env_val = os.getenv(bug_name.upper(), "").strip().lower()
    if env_val in ("1", "true", "yes", "on"):
        return True
    return bool(getattr(settings, bug_name.lower(), False))


settings = get_settings()
