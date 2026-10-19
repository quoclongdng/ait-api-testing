<?php

namespace App\Http\Requests\Concerns;

use App\Models\Endpoint;
use Closure;

/**
 * Rules for business_rules.endpoint_id (BusinessRuleWrite/Patch: integer|null, int64, minimum 1),
 * shared by the M08 form and the /api/v1 store, update and patch requests.
 *
 * QA S13-03: without `bail` the ownership closure ran after `integer` had already failed, so "abc",
 * 1.5 or 99999999999999999999 reached a PostgreSQL bigint comparison (SQLSTATE 22P02) and the
 * request answered 500 INTERNAL_ERROR instead of 422 VALIDATION_FAILED.
 */
trait ValidatesProjectEndpointId
{
    /**
     * @return list<mixed>
     */
    protected function endpointIdRules(int $projectId): array
    {
        return [
            'bail',
            'nullable',
            'integer',
            'min:1',
            function (string $attribute, mixed $value, Closure $fail) use ($projectId): void {
                if ($value === null) {
                    return;
                }
                $id = self::endpointIdValue($value);
                if ($id === null) {
                    $fail('Endpoint áp dụng phải là một số nguyên dương.');

                    return;
                }
                $endpoint = Endpoint::query()->with('apiSpec')->find($id);
                if (! $endpoint instanceof Endpoint || $endpoint->apiSpec->project_id !== $projectId) {
                    $fail('Endpoint được chọn không thuộc dự án này.');
                }
            },
        ];
    }

    /**
     * A positive int64 from a JSON integer or a form string of digits; null for anything else
     * (floats, booleans, values above PHP_INT_MAX, whitespace or signs).
     */
    private static function endpointIdValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 1 ? $value : null;
        }
        if (! is_string($value) || preg_match('/^[1-9][0-9]{0,18}$/', $value) !== 1) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($id) ? $id : null;
    }
}
