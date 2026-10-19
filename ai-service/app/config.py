from functools import lru_cache
from typing import Literal

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    """ai-service settings. Every field is documented in docs/DESIGN.md section 7."""

    model_config = SettingsConfigDict(env_prefix="AI_", env_file=".env", extra="ignore")

    app_version: str = "1.0.0"
    # "disabled" = no LLM on this server (production profile, ADR-0007): /health reports it so
    # the web disables LLM/HYBRID, and POST /v1/generate refuses without calling any model.
    llm_provider: Literal["fake", "openai_compatible", "disabled"] = "fake"
    llm_base_url: str = "http://ollama:11434/v1"
    llm_api_key: str = ""
    llm_model: str = "qwen2.5-coder:7b"
    # Per-call HTTP timeout of one chat completion. One GENERATE call asks for at most
    # AI_LLM_CASES_PER_CALL cases and max_tokens = cases * AI_LLM_OUTPUT_TOKENS_PER_CASE + 256
    # (1456 by default): ~80 s at ~18 tokens/s (qwen2.5-coder:7b on a laptop) plus prompt
    # processing. 300 s leaves room for a busy Ollama without one call eating the budget.
    llm_timeout_seconds: float = Field(default=300.0, gt=0)
    # HTTP 429/503 from the provider (rate limit, e.g. a free tier): wait as the provider asks
    # (Retry-After / x-ratelimit-reset-*) and retry, never past the time left for the call.
    llm_rate_limit_retries: int = Field(default=3, ge=0, le=10)
    llm_temperature: float = 0.2
    llm_seed: int = 42
    max_repair_attempts: int = Field(default=2, ge=0, le=10)
    # Overall wall-clock budget for one POST /v1/generate. Each endpoint gets its own
    # GENERATE call (in order), then invalid cases are repaired while time remains. Must
    # stay below the web's AI_SERVICE_TIMEOUT so partial results and llm_calls are returned
    # before it gives up (web default 1380 s = this + 60 s, inside the 1500 s job timeouts).
    # 1320 s covers the largest benchmark (ecommerce-order, 13 endpoints) at ~60-90 s per
    # endpoint; when it runs out the remaining endpoints/repairs are skipped (job PARTIAL).
    generate_budget_seconds: float = Field(default=1320.0, gt=0)
    # Upper bound on the characters of one prompt (all messages). Keep it within the
    # model's context window: ceil(chars / 3) + output reserve <= AI_LLM_CONTEXT_TOKENS.
    max_prompt_chars: int = Field(default=36000, ge=1000)
    # Context window (tokens) of the model behind the provider: Ollama num_ctx /
    # OLLAMA_CONTEXT_LENGTH (compose sets 16384). A prompt whose conservative token
    # estimate (chars / 3) plus the output reserve exceeds it is never sent, because the
    # provider would silently drop its head (the system prompt).
    llm_context_tokens: int = Field(default=16384, ge=1024)
    # Cases requested from the model in one GENERATE call (one call per endpoint). The
    # effective number is min(options.max_cases_per_endpoint, this): small local models
    # stay accurate and fast with a few cases per call.
    llm_cases_per_call: int = Field(default=3, ge=1, le=20)
    # Output tokens budgeted per requested case; max_tokens of a GENERATE call is
    # cases * this + 256, of a REPAIR call this * 2 + 256.
    llm_output_tokens_per_case: int = Field(default=400, ge=100, le=8192)


@lru_cache
def get_settings() -> Settings:
    return Settings()
