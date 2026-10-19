"""Categories API routes for simple-store benchmark API."""

from fastapi import APIRouter, HTTPException, Path, Query, status

from app.config import is_bug_active
from app.schemas import CategoryCreate, CategoryResponse, ErrorResponse
from app.store import store

router = APIRouter(prefix="/categories", tags=["Categories"])


@router.post(
    "",
    response_model=CategoryResponse,
    status_code=status.HTTP_201_CREATED,
    summary="Create category",
    description="Create a new product category with a unique slug.",
    responses={
        409: {"model": ErrorResponse, "description": "Duplicate category slug"},
        422: {"description": "Validation error"},
    },
)
def create_category(payload: CategoryCreate) -> CategoryResponse:
    # Business rule: unique slug (unless BUG_008 is enabled)
    existing = store.get_category_by_slug(payload.slug)
    if existing and not is_bug_active("bug_008"):
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail=f"Category with slug '{payload.slug}' already exists.",
        )

    cat = store.create_category(
        name=payload.name,
        slug=payload.slug,
        description=payload.description,
    )
    return CategoryResponse(**cat)


@router.get(
    "",
    response_model=list[CategoryResponse],
    summary="List categories",
    description="Retrieve a paginated list of product categories.",
)
def list_categories(
    limit: int = Query(default=50, ge=1, le=100, description="Maximum categories to return"),
    offset: int = Query(default=0, ge=0, description="Offset for pagination"),
) -> list[CategoryResponse]:
    items = store.list_categories(limit=limit, offset=offset)
    return [CategoryResponse(**item) for item in items]


@router.get(
    "/{id}",
    response_model=CategoryResponse,
    summary="Get category by ID",
    description="Retrieve a single category by its unique identifier.",
    responses={
        404: {"model": ErrorResponse, "description": "Category not found"},
    },
)
def get_category(
    id: int = Path(..., ge=1, description="Category identifier"),
) -> CategoryResponse:
    cat = store.get_category(id)
    if not cat:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail=f"Category with ID {id} not found.",
        )
    return CategoryResponse(**cat)
