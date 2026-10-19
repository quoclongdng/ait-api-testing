"""Configuration settings for ecommerce-order benchmark API."""

import os

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    app_name: str = "E-Commerce Order Benchmark API"
    app_version: str = "1.0.0"

    # Seeded bug toggles (disabled by default in S5-04 clean baseline, activated in S7-04)
    bug_011: bool = False  # Boundary error: allow quantity = 0 on add cart item
    bug_012: bool = False  # Business rule violation: allow quantity exceeding actual product stock
    bug_013: bool = False  # Sequence error: allow checkout of empty cart
    bug_014: bool = False  # Wrong status code: checkout returns 200 without Location header
    bug_015: bool = False  # Sequence error: double payment allowed on same order
    bug_016: bool = False  # Business rule violation: allow cancel order when status is SHIPPED
    bug_017: bool = False  # Auth error: customer role allowed to call admin orders endpoint
    bug_018: bool = False  # Auth error (IDOR): customer A views order of customer B
    bug_019: bool = False  # Schema mismatch: create order returns total_amount as string
    bug_020: bool = False  # Business rule violation: coupon applied when cart subtotal < 100
    bug_021: bool = False  # Wrong status code: remove non-existent item returns 500
    bug_022: bool = False  # Schema mismatch: empty cart returns items as null

    model_config = SettingsConfigDict(
        extra="ignore",
        case_sensitive=False,
    )


settings = Settings()


def is_bug_active(bug_name: str) -> bool:
    """Check if a bug toggle is currently active via environment variable or settings.

    If the environment variable is present in os.environ (e.g. 'BUG_011'), its value
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
        bug_011=is_bug_active("BUG_011"),
        bug_012=is_bug_active("BUG_012"),
        bug_013=is_bug_active("BUG_013"),
        bug_014=is_bug_active("BUG_014"),
        bug_015=is_bug_active("BUG_015"),
        bug_016=is_bug_active("BUG_016"),
        bug_017=is_bug_active("BUG_017"),
        bug_018=is_bug_active("BUG_018"),
        bug_019=is_bug_active("BUG_019"),
        bug_020=is_bug_active("BUG_020"),
        bug_021=is_bug_active("BUG_021"),
        bug_022=is_bug_active("BUG_022"),
    )
