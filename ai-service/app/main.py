import uuid
from typing import Any

from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from starlette.exceptions import HTTPException as StarletteHTTPException

from app.api.routes import router
from app.contracts.llm import LlmProviderError
from app.schemas.errors import AppError
from app.services.generator import _sanitize_surrogates


def get_trace_id(request: Request) -> str:
    return request.headers.get("x-request-id") or uuid.uuid4().hex


# Maps HTTP errors raised by the framework (unknown path, wrong method, ...) to the
# error codes of the shared envelope (web-api.openapi.yaml#/components/schemas/ErrorCode).
_HTTP_ERROR_CODES: dict[int, tuple[str, str]] = {
    400: ("VALIDATION_FAILED", "The given data was invalid."),
    401: ("UNAUTHENTICATED", "Unauthenticated."),
    403: ("FORBIDDEN", "This action is unauthorized."),
    404: ("NOT_FOUND", "Resource not found."),
    405: ("METHOD_NOT_ALLOWED", "Method not allowed."),
    409: ("CONFLICT", "Conflict."),
    413: ("VALIDATION_FAILED", "The given data was invalid."),
    415: ("VALIDATION_FAILED", "The given data was invalid."),
    422: ("VALIDATION_FAILED", "The given data was invalid."),
    429: ("RATE_LIMITED", "Too many requests."),
}


def _http_error_code(status_code: int) -> tuple[str, str]:
    if status_code in _HTTP_ERROR_CODES:
        return _HTTP_ERROR_CODES[status_code]
    if 400 <= status_code < 500:
        return ("VALIDATION_FAILED", "The given data was invalid.")
    return ("INTERNAL_ERROR", "An internal error occurred.")


def create_app() -> FastAPI:
    app = FastAPI(
        title="AIT ai-service",
        version="1.0.0",
        description="Contract: docs/contracts/ai-service.openapi.yaml",
    )

    @app.exception_handler(AppError)
    async def app_error_handler(request: Request, exc: AppError) -> JSONResponse:
        trace_id = get_trace_id(request)
        return JSONResponse(
            status_code=exc.status_code,
            content={
                "error": {
                    "code": exc.code,
                    "message": _sanitize_surrogates(exc.message),
                    "details": _sanitize_surrogates(exc.details),
                },
                "trace_id": trace_id,
            },
        )

    @app.exception_handler(LlmProviderError)
    async def llm_provider_error_handler(request: Request, exc: LlmProviderError) -> JSONResponse:
        trace_id = get_trace_id(request)
        return JSONResponse(
            status_code=502,
            content={
                "error": {
                    "code": "LLM_PROVIDER_ERROR",
                    "message": _sanitize_surrogates(str(exc)),
                    "details": {},
                },
                "trace_id": trace_id,
            },
        )

    @app.exception_handler(StarletteHTTPException)
    async def http_exception_handler(request: Request, exc: StarletteHTTPException) -> JSONResponse:
        # Unknown paths (404) and wrong methods (405) use the standard error envelope
        # like every other error of this service (S13-03).
        trace_id = get_trace_id(request)
        code, message = _http_error_code(exc.status_code)
        return JSONResponse(
            status_code=exc.status_code,
            content={
                "error": {"code": code, "message": message, "details": {}},
                "trace_id": trace_id,
            },
            headers=dict(exc.headers) if exc.headers else None,
        )

    @app.exception_handler(RequestValidationError)
    async def validation_error_handler(
        request: Request, exc: RequestValidationError
    ) -> JSONResponse:
        trace_id = get_trace_id(request)
        details: dict[str, Any] = {}
        for error in exc.errors():
            loc = ".".join(str(p) for p in error.get("loc", []) if p != "body") or "payload"
            msg = error.get("msg", "Invalid value")
            if loc not in details:
                details[loc] = []
            details[loc].append(msg)

        return JSONResponse(
            status_code=422,
            content={
                "error": {
                    "code": "VALIDATION_FAILED",
                    "message": "The given data was invalid.",
                    "details": details,
                },
                "trace_id": trace_id,
            },
        )

    @app.exception_handler(Exception)
    async def generic_exception_handler(request: Request, exc: Exception) -> JSONResponse:
        trace_id = get_trace_id(request)
        return JSONResponse(
            status_code=500,
            content={
                "error": {
                    "code": "INTERNAL_ERROR",
                    "message": "An internal error occurred.",
                    "details": {},
                },
                "trace_id": trace_id,
            },
        )

    app.include_router(router)
    return app


app = create_app()
