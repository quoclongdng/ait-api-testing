<?php

namespace App\Support;

/**
 * Display of test_runs.benchmark_variant (S14-06): the clean variant is stored as `clean`
 * (RunExperimentAction) and shown as "Bản sạch (clean)"; a bug variant keeps its code (BUG-032).
 * The stored value, the API and the exports stay unchanged.
 */
final class VariantLabel
{
    public const CLEAN = 'clean';

    public static function display(?string $variant): string
    {
        if ($variant === null || $variant === '') {
            return '—';
        }

        return $variant === self::CLEAN ? 'Bản sạch (clean)' : $variant;
    }
}
