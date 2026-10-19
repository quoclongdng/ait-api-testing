"""Helpers that complete test payloads with every key ai-service.openapi.yaml requires.

``NormalizedEndpoint`` and ``GenerateOptions`` list every property in ``required`` (S13-03),
so a test that only cares about a few keys fills the rest with the values the web client
sends by default (``AiGenerateRequest::toArray``, ``ExecuteAiGenerationAction``).
"""

from typing import Any

ENDPOINT_DEFAULTS: dict[str, Any] = {
    "operation_id": None,
    "summary": None,
    "description": None,
    "parameters": None,
    "request_body": None,
    "responses": {},
    "security": None,
}

OPTION_DEFAULTS: dict[str, Any] = {
    "max_cases_per_endpoint": 10,
    "techniques": [],
    "model": None,
    "temperature": 0.2,
    "seed": None,
    "max_repair_attempts": 2,
}


def contract_endpoint(endpoint: dict[str, Any]) -> dict[str, Any]:
    """Return ``endpoint`` with every contract-required key present."""
    return {**ENDPOINT_DEFAULTS, **endpoint}


def contract_options(**options: Any) -> dict[str, Any]:
    """Return generate options with every contract-required key present."""
    return {**OPTION_DEFAULTS, **options}
