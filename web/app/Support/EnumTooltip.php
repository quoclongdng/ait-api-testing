<?php

namespace App\Support;

/**
 * Tooltip (`title`) of a badge or label that shows a stored enum (S14-06 live QA r4): the
 * Vietnamese label first and the stored value in parentheses, "Hoàn thành (COMPLETED)", like the
 * visible labels (docs/DESIGN.md §8), instead of the bare raw enum. Stored values and exports are
 * unchanged.
 */
final class EnumTooltip
{
    /**
     * "Hoàn thành (COMPLETED)". The value alone when there is no distinct label, and the label alone
     * when it already ends with the value in parentheses ("Đạt (PASS)").
     */
    public static function for(?string $label, \BackedEnum|string|null $value): string
    {
        $raw = $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
        $label = trim((string) $label);

        if ($raw === '') {
            return $label;
        }
        if ($label === '' || $label === $raw) {
            return $raw;
        }
        if (str_ends_with($label, '('.$raw.')')) {
            return $label;
        }

        return $label.' ('.$raw.')';
    }
}
