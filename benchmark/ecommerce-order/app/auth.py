"""Deterministic bearer-token authentication for the benchmark sandbox."""

from dataclasses import dataclass
from enum import StrEnum
from typing import Annotated

from fastapi import Depends, HTTPException, status
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer


class Role(StrEnum):
    CUSTOMER = "CUSTOMER"
    ADMIN = "ADMIN"


@dataclass(frozen=True)
class Identity:
    user_id: int
    username: str
    role: Role


# Public synthetic fixture tokens. They are stable so generated tests can exercise
# authentication and RBAC without an external identity provider.
TOKEN_IDENTITIES = {
    "customer-alice-token": Identity(1, "alice", Role.CUSTOMER),
    "customer-bob-token": Identity(2, "bob", Role.CUSTOMER),
    "admin-token": Identity(99, "admin", Role.ADMIN),
}

bearer = HTTPBearer(auto_error=False)


def api_error(status_code: int, code: str, message: str) -> HTTPException:
    return HTTPException(status_code=status_code, detail={"code": code, "message": message})


def require_identity(
    credentials: Annotated[HTTPAuthorizationCredentials | None, Depends(bearer)],
) -> Identity:
    if credentials is None or credentials.scheme.lower() != "bearer":
        raise api_error(status.HTTP_401_UNAUTHORIZED, "UNAUTHENTICATED", "Bearer token required")

    identity = TOKEN_IDENTITIES.get(credentials.credentials)
    if identity is None:
        raise api_error(status.HTTP_401_UNAUTHORIZED, "UNAUTHENTICATED", "Invalid bearer token")
    return identity


def require_admin(identity: Annotated[Identity, Depends(require_identity)]) -> Identity:
    from app.config import is_bug_active

    if identity.role is not Role.ADMIN and not is_bug_active("bug_017"):
        raise api_error(status.HTTP_403_FORBIDDEN, "FORBIDDEN", "Administrator role required")
    return identity


CurrentIdentity = Annotated[Identity, Depends(require_identity)]
AdminIdentity = Annotated[Identity, Depends(require_admin)]
