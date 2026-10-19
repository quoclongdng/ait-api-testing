from typing import Annotated

from fastapi import APIRouter, Depends
from pydantic import BaseModel

from app.api.deps import get_generator_service
from app.config import Settings, get_settings
from app.providers.disabled import LLM_DISABLED_MESSAGE
from app.schemas.errors import AppError, ErrorResponse
from app.schemas.generate import GenerateRequest, GenerateResponse
from app.services.generator import GeneratorService

router = APIRouter()


class HealthResponse(BaseModel):
    status: str
    provider: str
    version: str
    # Non-secret system LLM defaults, shown read-only on web M04 (ADR-0004). Never the API key.
    model: str
    temperature: float
    seed: int
    # AI_GENERATE_BUDGET_SECONDS (S11-02): the web refuses to start a generation when its
    # AI_SERVICE_TIMEOUT does not cover this budget plus a 60 s margin, because it would give
    # up while ai-service keeps calling the LLM and those calls never reach llm_call_logs (D09).
    generate_budget_seconds: float


@router.get("/health", response_model=HealthResponse)
def health(settings: Annotated[Settings, Depends(get_settings)]) -> HealthResponse:
    return HealthResponse(
        status="ok",
        provider=settings.llm_provider,
        version=settings.app_version,
        model=settings.llm_model,
        temperature=settings.llm_temperature,
        seed=settings.llm_seed,
        generate_budget_seconds=settings.generate_budget_seconds,
    )


@router.post(
    "/v1/generate",
    response_model=GenerateResponse,
    responses={
        422: {"model": ErrorResponse, "description": "Request hoặc DSL đầu vào không hợp lệ"},
        502: {
            "model": ErrorResponse,
            "description": "Provider lỗi hoặc trả dữ liệu không dùng được",
        },
        500: {"model": ErrorResponse, "description": "Lỗi nội bộ"},
    },
)
def generate_test_cases(
    request: GenerateRequest,
    generator_service: Annotated[GeneratorService, Depends(get_generator_service)],
    settings: Annotated[Settings, Depends(get_settings)],
) -> GenerateResponse:
    if settings.llm_provider == "disabled":
        # ADR-0007 AI_LLM_PROVIDER=disabled: refuse before any prompt is built, so no failed
        # llm_calls are produced for a model that does not exist. The web checks /health first.
        raise AppError(
            "LLM_PROVIDER_ERROR",
            LLM_DISABLED_MESSAGE,
            status_code=502,
            details={"reason": "LLM_DISABLED", "llm_calls": []},
        )
    return generator_service.generate(request)
