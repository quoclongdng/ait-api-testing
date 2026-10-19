<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * UserPatch (web-api.openapi.yaml): rename, change role or lock/unlock a user. ADMIN only.
 */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User && ($this->user()?->can('update', $target) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // required: an empty name is reported as missing, not as "not a string" (S13-03).
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:255'],
            'role' => ['sometimes', Rule::enum(UserRole::class)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * minProperties: 1 — an empty patch is a validation error.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->hasAny(['name', 'role', 'is_active'])) {
                    $validator->errors()->add('user', 'Cần ít nhất một trong các trường: name, role, is_active.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'họ và tên',
            'role' => 'vai trò',
            'is_active' => 'trạng thái kích hoạt',
        ];
    }
}
