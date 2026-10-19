<?php

namespace App\Casts;

use App\Support\OpenApiStructure;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Cast OpenAPI specification data (parameters, request_body, responses, security, normalized)
 * to/from JSON while preserving typed data literals:
 * - Structural containers are PHP associative arrays.
 * - JSON object literals in schema const and enum values
 *   retain their stdClass representation (e.g. empty object {} and numeric-key objects {"0": "a"}).
 * - JSON array literals (e.g. [], ['a', 'b']) remain PHP arrays.
 *
 * @implements CastsAttributes<mixed, mixed>
 */
class OpenApiSpecCast implements CastsAttributes
{
    /**
     * Cast the given value from database/raw attributes to PHP representation.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || $trimmed === 'null') {
                return null;
            }
            try {
                return OpenApiStructure::decodeJson($trimmed);
            } catch (\JsonException) {
                return null;
            }
        }

        if (is_array($value) || is_object($value)) {
            return OpenApiStructure::normalize($value);
        }

        return $value;
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
