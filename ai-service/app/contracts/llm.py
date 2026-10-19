"""LLM provider contract (D08). Every provider has a real and a fake implementation."""

from dataclasses import dataclass, field
from typing import Literal, Protocol

Role = Literal["system", "user", "assistant"]


@dataclass(frozen=True)
class ChatMessage:
    role: Role
    content: str


@dataclass(frozen=True)
class LlmCompletion:
    """Raw provider output. Stored verbatim in llm_call_logs for reproducibility (D09)."""

    content: str
    model: str
    prompt_tokens: int = 0
    completion_tokens: int = 0
    raw: dict[str, object] = field(default_factory=dict)


class LlmProviderError(Exception):
    """Provider unreachable or returned an unusable response.

    ``str(exc)`` is the short message shown to users (Vietnamese, no provider URL); ``detail``
    is the technical text (e.g. the redacted httpx error) kept for llm_calls[].error (D09).
    """

    def __init__(self, message: str, detail: str | None = None) -> None:
        super().__init__(message)
        self.detail = detail if detail is not None else message


class LlmProvider(Protocol):
    name: str

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
    ) -> LlmCompletion: ...
