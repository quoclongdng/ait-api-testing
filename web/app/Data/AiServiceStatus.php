<?php

namespace App\Data;

final readonly class AiServiceStatus
{
    /**
     * $model/$temperature/$seed are the non-secret system LLM defaults reported by ai-service
     * GET /health (ADR-0004), shown read-only on M04. They are not part of the public web /health.
     * $generateBudgetSeconds is ai-service AI_GENERATE_BUDGET_SECONDS (S11-02), null when an older
     * ai-service does not report it.
     */
    public function __construct(
        public bool $reachable,
        public ?string $provider = null,
        public ?string $version = null,
        public ?string $model = null,
        public ?float $temperature = null,
        public ?int $seed = null,
        public ?float $generateBudgetSeconds = null,
    ) {}

    /** Provider reported by an ai-service that runs without any LLM (AI_LLM_PROVIDER=disabled, ADR-0007). */
    public const PROVIDER_DISABLED = 'disabled';

    /** True when a reachable ai-service reports that it has no LLM (LLM/HYBRID unavailable). */
    public function llmDisabled(): bool
    {
        return $this->reachable && $this->provider === self::PROVIDER_DISABLED;
    }

    /** Seconds AI_SERVICE_TIMEOUT must exceed AI_GENERATE_BUDGET_SECONDS by (docs/DESIGN.md §7). */
    public const TIMEOUT_MARGIN_SECONDS = 60;

    /**
     * Smallest web AI_SERVICE_TIMEOUT that lets ai-service return its partial result and llm_calls
     * before the web gives up (budget + 60 s), or null when the budget is unknown.
     */
    public function requiredWebTimeoutSeconds(): ?int
    {
        return $this->generateBudgetSeconds === null
            ? null
            : (int) ceil($this->generateBudgetSeconds) + self::TIMEOUT_MARGIN_SECONDS;
    }

    /**
     * Shape of `checks.ai_service` in the public web GET /api/v1/health (web-api contract).
     *
     * @return array{reachable: bool, provider: ?string, version: ?string}
     */
    public function toArray(): array
    {
        return [
            'reachable' => $this->reachable,
            'provider' => $this->provider,
            'version' => $this->version,
        ];
    }
}
