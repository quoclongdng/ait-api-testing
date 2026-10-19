from collections.abc import Iterator
from typing import Annotated

from fastapi import Depends

from app.config import Settings, get_settings
from app.contracts.llm import LlmProvider
from app.providers.factory import make_provider
from app.services.generator import GeneratorService


def get_provider(settings: Annotated[Settings, Depends(get_settings)]) -> Iterator[LlmProvider]:
    """One provider per request; its HTTP client is closed when the request ends."""
    provider = make_provider(settings)
    try:
        yield provider
    finally:
        close = getattr(provider, "close", None)
        if callable(close):
            close()


def get_generator_service(
    provider: Annotated[LlmProvider, Depends(get_provider)],
    settings: Annotated[Settings, Depends(get_settings)],
) -> GeneratorService:
    return GeneratorService(provider=provider, settings=settings)
