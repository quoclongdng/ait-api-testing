"""Configuration settings for appointment-booking benchmark API."""

import os

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    app_name: str = "Appointment Booking Benchmark API"
    app_version: str = "1.0.0"

    # Seeded bug toggles (disabled by default in clean baseline, activated for experiments)
    bug_023: bool = False  # Boundary error: allow booking appointment in the past
    bug_024: bool = False  # Business rule violation: allow double booking for doctor/slot
    bug_025: bool = False  # Business rule violation: allow booking on closed day/holiday
    bug_026: bool = False  # Wrong status code: return 500 instead of 409 on slot conflict
    bug_027: bool = False  # Business rule violation: allow rescheduling within 24 hours
    bug_028: bool = False  # Business rule violation: allow doctor appointments exceeding quota (20)
    bug_029: bool = False  # Schema mismatch: return invalid ISO 8601 datetime format for slots
    bug_030: bool = False  # Auth error (IDOR): patient cancels another patient's appointment
    bug_031: bool = False  # Wrong status code: return 400 with text/html on invalid department id
    bug_032: bool = False  # Missing validation: allow invalid phone number format

    model_config = SettingsConfigDict(
        extra="ignore",
        case_sensitive=False,
    )


settings = Settings()


def is_bug_active(bug_name: str) -> bool:
    """Check if a bug toggle is currently active via environment variable or settings.

    If the environment variable is present in os.environ (e.g. 'BUG_023'), its value
    directly dictates active status (allowing runtime changes between '1' and '0' in
    the same process). If absent from os.environ, falls back to the settings object.
    """
    env_key = bug_name.upper()
    if env_key in os.environ:
        return os.environ[env_key].strip().lower() in ("1", "true", "yes", "on")
    return bool(getattr(settings, bug_name.lower(), False))


def get_settings() -> Settings:
    """Return a Settings snapshot reflecting currently active bug flags."""
    return Settings(
        bug_023=is_bug_active("BUG_023"),
        bug_024=is_bug_active("BUG_024"),
        bug_025=is_bug_active("BUG_025"),
        bug_026=is_bug_active("BUG_026"),
        bug_027=is_bug_active("BUG_027"),
        bug_028=is_bug_active("BUG_028"),
        bug_029=is_bug_active("BUG_029"),
        bug_030=is_bug_active("BUG_030"),
        bug_031=is_bug_active("BUG_031"),
        bug_032=is_bug_active("BUG_032"),
    )
