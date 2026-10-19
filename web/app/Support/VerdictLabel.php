<?php

namespace App\Support;

use App\Enums\TestVerdict;

/**
 * Vietnamese display of a test result verdict (S14-06 live QA r3), as on the M13 summary cards:
 * "Đạt (PASS)", Vietnamese first and the stored value in parentheses (docs/DESIGN.md §8). The stored
 * verdict and the exports keep PASS/FAIL/ERROR/SKIPPED.
 */
final class VerdictLabel
{
    /** Short Vietnamese word ("Đạt"); the raw value when it is not a known verdict. */
    public static function short(TestVerdict|string|null $verdict): string
    {
        $value = $verdict instanceof TestVerdict ? $verdict->value : (string) $verdict;

        return match ($value) {
            TestVerdict::Pass->value => 'Đạt',
            TestVerdict::Fail->value => 'Không đạt',
            TestVerdict::Error->value => 'Lỗi',
            TestVerdict::Skipped->value => 'Bỏ qua',
            '' => '—',
            default => $value,
        };
    }

    /** "Đạt (PASS)"; an unknown value is shown as is. */
    public static function display(TestVerdict|string|null $verdict): string
    {
        $value = $verdict instanceof TestVerdict ? $verdict->value : (string) $verdict;
        $short = self::short($value);

        return $short === $value || $value === '' ? $short : $short.' ('.$value.')';
    }

    /**
     * Every verdict mapped to its display label, for the Alpine screens (M12).
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        $labels = [];
        foreach (TestVerdict::cases() as $verdict) {
            $labels[$verdict->value] = self::display($verdict);
        }

        return $labels;
    }
}
