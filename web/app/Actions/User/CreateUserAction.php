<?php

namespace App\Actions\User;

use App\Enums\ErrorCode;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\EmailAddress;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;

class CreateUserAction
{
    /**
     * Create a new user.
     *
     * @param  array{name: string, email: string, password: string, role: UserRole|string, is_active?: bool|null}  $data
     */
    public function execute(array $data): User
    {
        $role = $data['role'] instanceof UserRole
            ? $data['role']
            : UserRole::from((string) $data['role']);

        $isActive = array_key_exists('is_active', $data)
            ? (bool) $data['is_active']
            : true;

        // Case-insensitive, like the users_email_lower_unique index (QA S13-03).
        $email = (string) EmailAddress::normalize($data['email']);
        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw $this->duplicate($email);
        }

        try {
            return User::create([
                'name' => $data['name'],
                'email' => $email,
                'password' => Hash::make($data['password']),
                'role' => $role,
                'is_active' => $isActive,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent create with the same email passed the check above.
            throw $this->duplicate($email);
        }
    }

    private function duplicate(string $email): ApiException
    {
        return new ApiException(
            ErrorCode::Conflict,
            'Địa chỉ email này đã được sử dụng.',
            ['email' => ["Địa chỉ email {$email} đã được sử dụng."]],
        );
    }
}
