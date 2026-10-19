<?php

namespace App\Support;

use App\Data\AiServiceStatus;

/**
 * Display helpers for LLM parameters on the Vietnamese UI (S14-06 live QA r3): the provider enum of
 * ai-service / llm_call_logs gets a display name, and the temperature uses the Vietnamese decimal
 * comma like the percentages and means next to it ("0,2", not "0.2"). Stored values stay unchanged;
 * exports (JSON/CSV) keep the raw numbers.
 */
final class LlmDisplay
{
    /** Display name of an LLM provider enum ("openai_compatible" → "OpenAI-compatible"); null when unknown. */
    public static function provider(?string $provider): ?string
    {
        return match ($provider) {
            null, '' => null,
            'openai_compatible' => 'OpenAI-compatible',
            'fake' => 'giả lập',
            AiServiceStatus::PROVIDER_DISABLED => 'đã tắt',
            default => $provider,
        };
    }

    /**
     * Temperature with a decimal comma and no trailing zeros ("0,2", "1", "0,05"); "—" when absent
     * or not numeric.
     */
    public static function temperature(mixed $temperature): string
    {
        if (! is_int($temperature) && ! is_float($temperature) && ! (is_string($temperature) && is_numeric($temperature))) {
            return '—';
        }

        $formatted = MetricFormat::number((float) $temperature, 2);

        return rtrim(rtrim($formatted, '0'), ',');
    }
}
