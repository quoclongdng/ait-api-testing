from app.contracts.llm import ChatMessage, LlmCompletion


class FakeLlmProvider:
    """Deterministic provider for tests, CI and offline development.

    Returns queued responses in order, then a fixed empty-suite JSON document.
    """

    name = "fake"

    def __init__(self, responses: list[str] | None = None) -> None:
        self._responses = list(responses or [])
        self.calls: list[list[ChatMessage]] = []
        self.max_tokens: list[int | None] = []

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
        self.calls.append(messages)
        self.max_tokens.append(max_tokens)
        content = self._responses.pop(0) if self._responses else '{"test_cases": []}'
        return LlmCompletion(content=content, model=model or "fake")
