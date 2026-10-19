<?php

namespace App\Services\Support;

use App\Enums\ErrorCode;

/**
 * Format of generation_jobs.error for a failed LLM/HYBRID job: the ErrorCode on the first line,
 * optionally followed by the user-facing ApiException message on the next line (S10-01).
 *
 * Keeping the cause (e.g. the AI_SERVICE_TIMEOUT guard or an ai-service 422 "prompt too large")
 * lets M11 and M15 show it instead of a generic text derived from the code alone.
 */
final class GenerationJobError
{
    private const MAX_DETAIL_CHARS = 1000;

    /**
     * Prefix of the ApiException message for an ai-service 502 (LLM_PROVIDER_ERROR): the rest is
     * the ai-service error.message, which already states the provider's reason (S14-06).
     */
    public const PROVIDER_ERROR_PREFIX = 'Lỗi từ LLM provider: ';

    public static function compose(string $code, ?string $detail): string
    {
        $detail = self::normalize($detail);

        return $detail === '' ? $code : $code."\n".mb_substr($detail, 0, self::MAX_DETAIL_CHARS);
    }

    /**
     * The ErrorCode stored on the first line, or null when the error is free text
     * (e.g. per-endpoint RULE errors "POST:/items: ...").
     */
    public static function code(?string $error): ?ErrorCode
    {
        if ($error === null || $error === '') {
            return null;
        }

        return ErrorCode::tryFrom(trim(explode("\n", $error, 2)[0]));
    }

    /**
     * The message stored after the ErrorCode line, or null when there is none.
     */
    public static function detail(?string $error): ?string
    {
        if (self::code($error) === null) {
            return null;
        }
        $parts = explode("\n", (string) $error, 2);
        $detail = self::normalize($parts[1] ?? null);

        return $detail === '' ? null : $detail;
    }

    /**
     * Single-line form for compact displays (M15 repetition list, experiments.metrics.error).
     */
    public static function oneLine(?string $error): string
    {
        $code = self::code($error);
        $detail = self::detail($error);

        if ($code !== null && $detail !== null) {
            return "{$code->value}: {$detail}";
        }

        return self::normalize($error);
    }

    private static function normalize(?string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    }
}
