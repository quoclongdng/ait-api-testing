<?php

namespace App\Support;

use Illuminate\Support\ViewErrorBag;

/**
 * aria-invalid/aria-describedby attributes for a form control with a server-side validation error,
 * printed by the Blade directive invalid('field', 'error-id') so assistive technology links the field
 * to the message rendered under it by the field-error component.
 *
 * A control that also has a static hint passes its id as $hintId instead of writing its own
 * aria-describedby: HTML keeps only the first duplicate attribute, so both ids must share one
 * attribute ("hint-id error-id") for the error to stay linked.
 */
final class FieldA11y
{
    public static function attributes(?ViewErrorBag $errors, string $field, string $describedBy, bool $when = true, ?string $hintId = null): string
    {
        $hasError = $when && $errors !== null && $errors->has($field);

        $ids = [];
        if ($hintId !== null && $hintId !== '') {
            $ids[] = $hintId;
        }
        if ($hasError) {
            $ids[] = $describedBy;
        }

        $attributes = [];
        if ($hasError) {
            $attributes[] = 'aria-invalid="true"';
        }
        if ($ids !== []) {
            $attributes[] = 'aria-describedby="'.e(implode(' ', $ids)).'"';
        }

        return implode(' ', $attributes);
    }
}
