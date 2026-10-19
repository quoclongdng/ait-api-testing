<?php

namespace App\Http\Requests\ApiSpecs;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\ApiSpec;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Pagination of GET /api/v1/specs/{id}/endpoints.
 *
 * Validates page/per_page like every other list operation (422 VALIDATION_FAILED
 * outside 1..100) instead of silently clamping. Access is checked before
 * validation so a non-owner always gets 403, never a 422.
 */
class ListEndpointsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $spec = $this->route('spec');

        return $spec instanceof ApiSpec
            && $this->user()?->can('view', $spec) === true;
    }

    /**
     * Keep the endpoint-specific §6 message for authorization failures.
     */
    protected function failedAuthorization(): void
    {
        throw new ApiException(
            ErrorCode::Forbidden,
            'Bạn không có quyền truy cập các endpoint của bản đặc tả này.'
        );
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
