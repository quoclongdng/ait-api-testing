<?php

namespace App\Support;

/**
 * One rounding rule for the §11 metrics wherever they are shown (QA S14-06): M15 (x-metric-value),
 * summary.md of `make experiment` (ExperimentSummaryBuilder) and the report tables copied from it.
 *
 * Values are rounded half up on their decimal value, so a stored mean of 0.2415 is "24.2" everywhere.
 * `sprintf('%.1f')` on the binary float 0.2415 × 100 = 24.149999… gave "24.1" in summary.md while M15
 * showed "24,2".
 */
final class MetricFormat
{
    /**
     * A rate in [0, 1] as a percentage number, without the % sign ("24,2" or "24.2").
     */
    public static function percent(float|int $fraction, int $decimals = 1, string $decimalSeparator = ',', string $thousandsSeparator = '.'): string
    {
        return self::number($fraction * 100, $decimals, $decimalSeparator, $thousandsSeparator);
    }

    /**
     * A number rounded half up to $decimals on its decimal value.
     */
    public static function number(float|int $value, int $decimals = 1, string $decimalSeparator = ',', string $thousandsSeparator = '.'): string
    {
        // Snap away the binary representation error first (24.149999999999999 → 24.15), then round half up.
        $snapped = round((float) $value, 9);
        $rounded = round($snapped, $decimals, PHP_ROUND_HALF_UP);
        if ($rounded == 0.0) {
            $rounded = 0.0; // never "-0.0"
        }

        return number_format($rounded, $decimals, $decimalSeparator, $thousandsSeparator);
    }
}
