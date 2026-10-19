<?php

namespace App\Models\Concerns;

/**
 * Route model binding for models keyed by a PostgreSQL bigint `id`: a route value that is not a
 * bigint (e.g. /runs/abc, /projects/99999999999999999999) resolves to no model, so the request
 * ends in the normal 404 page or the §6 NOT_FOUND envelope instead of a PostgreSQL
 * invalid_text_representation / numeric_value_out_of_range error rendered as 500 (S13-03).
 *
 * Works through resolveRouteBindingQuery(), so it composes with BelongsToLiveProject and with
 * SoftDeletes bindings, and runs after the auth middleware (guests still get 401 / login).
 */
trait HasIntegerRouteKey
{
    /**
     * Largest PostgreSQL bigint, as decimal digits.
     */
    private static string $bigIntMax = '9223372036854775807';

    /**
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation<*, *, *>  $query
     * @param  mixed  $value
     * @param  string|null  $field
     * @return \Illuminate\Contracts\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation<*, *, *>
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $field ??= $this->getRouteKeyName();

        if ($field === $this->getKeyName() && $this->getKeyType() === 'int' && ! self::isBigIntRouteValue($value)) {
            return $query->whereRaw('1 = 0');
        }

        return parent::resolveRouteBindingQuery($query, $value, $field);
    }

    /**
     * True when the route value is a non-negative PostgreSQL bigint written in decimal digits.
     */
    public static function isBigIntRouteValue(mixed $value): bool
    {
        if (is_int($value)) {
            return $value >= 0;
        }

        if (! is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1) {
            return false;
        }

        $digits = ltrim($value, '0');
        $max = self::$bigIntMax;

        return strlen($digits) < strlen($max)
            || (strlen($digits) === strlen($max) && strcmp($digits, $max) <= 0);
    }
}
