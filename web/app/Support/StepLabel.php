<?php

namespace App\Support;

/**
 * Vietnamese display of the step names RuleGenerator writes ("Execute GET /items") and of the
 * fallback name TestExecutor gives an unnamed step ("Step 2"). The name itself stays in the DSL
 * and the request log unchanged because it is part of the dedup fingerprint (docs/DESIGN.md §10.5):
 * renaming it would make new RULE cases differ from stored ones. Only the label shown in the UI,
 * in test_results.error_message and in reports is translated.
 */
final class StepLabel
{
    public static function display(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $label = preg_replace('/^Execute (GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS) /', 'Gọi $1 ', $name) ?? $name;

        return preg_replace('/^Step ([0-9]+)$/', 'Bước $1', $label) ?? $label;
    }
}
