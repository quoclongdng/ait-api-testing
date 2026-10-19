<?php

namespace App\Actions\Generation;

use App\Contracts\AiGeneratorClient;
use App\Data\AiServiceStatus;
use App\Enums\ErrorCode;
use App\Enums\GenerationStrategy;
use App\Exceptions\ApiException;

/**
 * Production profile without an LLM (ADR-0007): when ai-service reports provider "disabled" on
 * GET /health (AI_LLM_PROVIDER=disabled), LLM and HYBRID generation are unavailable; RULE always
 * works. Returns the Vietnamese reason shown on M11/M15 and in the API error, or null when LLM
 * generation is available. An unreachable ai-service is not "disabled": the existing
 * AI_SERVICE_UNAVAILABLE flow reports it when a job runs.
 */
final class ResolveLlmAvailabilityAction
{
    // Shown to every TESTER of the public site, so no configuration names here; the admin page M04
    // explains how to enable an LLM (S13-03).
    public const DISABLED_MESSAGE = 'Máy chủ này chỉ hỗ trợ chiến lược RULE: LLM chưa được bật nên không dùng được LLM hoặc HYBRID. '
        .'Hãy liên hệ quản trị viên nếu cần sinh test bằng LLM.';

    public function __construct(private readonly AiGeneratorClient $aiClient) {}

    /**
     * @param  AiServiceStatus|null  $status  an already fetched ai-service status (avoids a second /health call)
     */
    public function execute(?AiServiceStatus $status = null): ?string
    {
        $status ??= $this->aiClient->status();

        return $status->llmDisabled() ? self::DISABLED_MESSAGE : null;
    }

    /**
     * What M11 needs from one ai-service GET /health call: the reason LLM/HYBRID are disabled (or
     * null) and the model suggestions (S14-06). The suggestion is the model of the configured
     * provider reported by ai-service; the web default (AI_LLM_MODEL of web/.env) is only a
     * fallback when ai-service does not report one. Hard-coded Ollama names did not exist on an
     * external provider and failed with HTTP 404.
     *
     * @return array{reason: ?string, models: list<string>}
     */
    public function formOptions(): array
    {
        $status = $this->aiClient->status();
        $reason = $this->execute($status);

        $model = $reason === null && $status->reachable && is_string($status->model) && trim($status->model) !== ''
            ? trim($status->model)
            : null;
        $fallback = config('ait.ai_service.default_model');
        $model ??= is_string($fallback) && trim($fallback) !== '' ? trim($fallback) : null;

        return ['reason' => $reason, 'models' => $model !== null ? [$model] : []];
    }

    /**
     * Refuse an LLM/HYBRID request with VALIDATION_FAILED (422) on `$field`; RULE passes.
     *
     * @throws ApiException
     */
    public function assertStrategyAvailable(GenerationStrategy $strategy, string $field = 'strategy'): void
    {
        if ($strategy === GenerationStrategy::Rule) {
            return;
        }

        $reason = $this->execute();
        if ($reason !== null) {
            throw new ApiException(ErrorCode::ValidationFailed, $reason, [$field => [$reason]]);
        }
    }
}
