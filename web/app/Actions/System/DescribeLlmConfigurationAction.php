<?php

namespace App\Actions\System;

use App\Contracts\AiGeneratorClient;

/**
 * M04 (read-only): system LLM provider/model as reported by ai-service GET /health (ADR-0004),
 * plus the web-side connection settings. Never exposes AI_LLM_API_KEY (§9).
 */
final class DescribeLlmConfigurationAction
{
    public function __construct(private readonly AiGeneratorClient $aiClient) {}

    /**
     * timeout_sufficient is false when AI_SERVICE_TIMEOUT < ai-service AI_GENERATE_BUDGET_SECONDS
     * + 60 (generation is then refused, S11-02), null when ai-service does not report its budget.
     *
     * @return array{driver: string, url: string, timeout: int, reachable: bool, provider: ?string, version: ?string, model: ?string, temperature: ?float, seed: ?int, generate_budget_seconds: ?float, required_timeout: ?int, timeout_sufficient: ?bool}
     */
    public function execute(): array
    {
        $status = $this->aiClient->status();
        $timeout = (int) config('ait.ai_service.timeout_seconds', 1380);
        $required = $status->requiredWebTimeoutSeconds();

        return [
            'driver' => (string) config('ait.ai_service.driver', 'http'),
            'url' => (string) config('ait.ai_service.url', 'http://ai-service:8001'),
            'timeout' => $timeout,
            'reachable' => $status->reachable,
            'provider' => $status->provider,
            'version' => $status->version,
            'model' => $status->model,
            'temperature' => $status->temperature,
            'seed' => $status->seed,
            'generate_budget_seconds' => $status->generateBudgetSeconds,
            'required_timeout' => $required,
            'timeout_sufficient' => $required === null ? null : $timeout >= $required,
        ];
    }
}
