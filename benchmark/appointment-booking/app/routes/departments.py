"""Department endpoints for appointment-booking benchmark."""

from typing import Annotated

from fastapi import APIRouter, Path, Response, status

from app.config import is_bug_active
from app.schemas import DepartmentResponse
from app.store import store

router = APIRouter(prefix="/departments", tags=["Departments"])


@router.get("", response_model=list[DepartmentResponse])
def list_departments() -> list[DepartmentResponse]:
    """List all hospital departments."""
    return store.list_departments()


@router.get("/{department_id}", response_model=DepartmentResponse)
def get_department(department_id: Annotated[int, Path(ge=1)]) -> DepartmentResponse | Response:
    """Get department details by ID.

    BUG-031: When bug is active, requesting an invalid department returns HTTP 400
    with Content-Type text/html instead of standard JSON error.
    """
    if department_id not in store.departments and is_bug_active("bug_031"):
        return Response(
            content="<html><body><h1>400 Bad Request: Invalid department ID</h1></body></html>",
            status_code=status.HTTP_400_BAD_REQUEST,
            media_type="text/html",
        )
    return store.get_department(department_id)
