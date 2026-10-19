from app.config import Settings
from app.contracts.llm import LlmProvider
from app.providers.disabled import DisabledLlmProvider
from app.providers.fake import FakeLlmProvider
from app.providers.openai_compatible import OpenAICompatibleProvider


def make_provider(settings: Settings) -> LlmProvider:
    """Select the LLM provider from configuration (AI_LLM_PROVIDER)."""
    if settings.llm_provider == "openai_compatible":
        return OpenAICompatibleProvider(
            base_url=settings.llm_base_url,
            model=settings.llm_model,
            api_key=settings.llm_api_key,
            timeout_seconds=settings.llm_timeout_seconds,
            rate_limit_retries=settings.llm_rate_limit_retries,
        )
    if settings.llm_provider == "disabled":
        return DisabledLlmProvider()
    return FakeLlmProvider()
