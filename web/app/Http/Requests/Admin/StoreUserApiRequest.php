<?php

namespace App\Http\Requests\Admin;

/**
 * POST /api/v1/admin/users (UserCreate). Same rules as the M03 form, but UserCreate has no
 * is_active field: a missing value means an active account, not an unchecked checkbox.
 */
class StoreUserApiRequest extends CreateUserRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->has('is_active') ? $this->boolean('is_active') : true,
        ]);
        $this->normalizeEmail();
    }
}
