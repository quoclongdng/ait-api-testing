"""Provider used when the server runs without an LLM (AI_LLM_PROVIDER=disabled, ADR-0007)."""

from app.contracts.llm import ChatMessage, LlmCompletion, LlmProviderError

LLM_DISABLED_MESSAGE = (
    "Máy chủ này chưa cấu hình LLM (AI_LLM_PROVIDER=disabled): chỉ dùng được chiến lược RULE. "
    "Quản trị viên cần khai báo AI_LLM_BASE_URL, AI_LLM_API_KEY, AI_LLM_MODEL và đặt "
    "AI_LLM_PROVIDER=openai_compatible để sinh test LLM/HYBRID."
)


class DisabledLlmProvider:
    """Never calls a model: every completion fails with a clear message."""

    name = "disabled"

    def complete(
        self,
        messages: list[ChatMessage],
        *,
        temperature: float,
        seed: int | None = None,
        json_mode: bool = True,
        model: str | None = None,
        timeout: float | None = None,
        max_tokens: int | None = None,
    ) -> LlmCompletion:
        raise LlmProviderError(LLM_DISABLED_MESSAGE)
