<?php

namespace App\Http\Requests\Admin;

use App\Actions\User\ProvisionAdminAction;
use App\Enums\UserRole;
use App\Support\EmailAddress;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
        ]);
        $this->normalizeEmail();
    }

    /**
     * QA S13-03: compare and store emails in lower case, so QA-User@ait.local and qa-user@ait.local
     * are one account (unique:users,email and the lower(email) index see the same value).
     */
    protected function normalizeEmail(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => EmailAddress::normalize($this->input('email'))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // The M03 form shows a field error; /api/v1 reports duplicates as 409 CONFLICT (CreateUserAction).
            'email' => array_merge(['required', 'string', 'email', 'max:255'], $this->is('api/*') ? [] : ['unique:users,email']),
            // One password policy for every account, the same as the deploy bootstrap ADMIN
            // (ait:admin:create, ADR-0007): at least 12 characters (S13-03).
            'password' => ['required', 'string', 'min:'.ProvisionAdminAction::MIN_PASSWORD_LENGTH, 'max:255'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * Custom attribute names for validation messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'họ và tên',
            'email' => 'địa chỉ email',
            'password' => 'mật khẩu',
            'role' => 'vai trò',
            'is_active' => 'trạng thái kích hoạt',
        ];
    }
}
