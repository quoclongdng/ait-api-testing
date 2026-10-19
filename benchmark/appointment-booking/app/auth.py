"""Authentication and authorization for appointment-booking benchmark."""

from dataclasses import dataclass
from enum import StrEnum
from typing import Annotated

from fastapi import Depends, Header, HTTPException, status


class Role(StrEnum):
    PATIENT = "PATIENT"
    DOCTOR = "DOCTOR"
    ADMIN = "ADMIN"


@dataclass(frozen=True)
class Identity:
    user_id: int
    name: str
    role: Role


SYNTHETIC_TOKENS: dict[str, Identity] = {
    "patient-alice-token": Identity(user_id=1, name="Alice Smith", role=Role.PATIENT),
    "patient-bob-token": Identity(user_id=2, name="Bob Jones", role=Role.PATIENT),
    "doctor-carol-token": Identity(user_id=101, name="Dr. Carol Danvers", role=Role.DOCTOR),
    "admin-token": Identity(user_id=999, name="Clinic Administrator", role=Role.ADMIN),
}


def api_error(status_code: int, code: str, message: str) -> HTTPException:
    """Standardized API exception matching project contracts."""
    return HTTPException(status_code=status_code, detail={"code": code, "message": message})


def get_current_user(
    authorization: Annotated[str | None, Header()] = None,
) -> Identity:
    """Extract and validate bearer token from Authorization header."""
    if not authorization:
        raise api_error(
            status.HTTP_401_UNAUTHORIZED,
            "UNAUTHORIZED",
            "Missing Authorization header",
        )

    parts = authorization.split()
    if len(parts) != 2 or parts[0].lower() != "bearer":
        raise api_error(
            status.HTTP_401_UNAUTHORIZED,
            "UNAUTHORIZED",
            "Authorization header must be Bearer token",
        )

    token = parts[1]
    identity = SYNTHETIC_TOKENS.get(token)
    if identity is None:
        raise api_error(
            status.HTTP_401_UNAUTHORIZED,
            "UNAUTHORIZED",
            "Invalid bearer token",
        )

    return identity


def require_role(*allowed_roles: Role):
    """Dependency enforcing one of the allowed roles."""

    def _checker(identity: Annotated[Identity, Depends(get_current_user)]) -> Identity:
        if identity.role not in allowed_roles:
            raise api_error(
                status.HTTP_403_FORBIDDEN,
                "FORBIDDEN",
                f"Role {identity.role.value} is not permitted to perform this action",
            )
        return identity

    return _checker
