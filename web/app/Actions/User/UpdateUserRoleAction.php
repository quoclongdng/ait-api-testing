<?php

namespace App\Actions\User;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateUserRoleAction
{
    /**
     * M03: ADMIN changes another user's role (§1 "đổi vai trò").
     * An ADMIN cannot change their own role, and the last ADMIN cannot be demoted.
     *
     * @throws ValidationException
     */
    public function execute(User $actor, User $user, UserRole $role): User
    {
        if ($actor->id === $user->id) {
            throw ValidationException::withMessages([
                'role' => 'Không thể tự đổi vai trò của chính mình.',
            ]);
        }

        if ($user->role === $role) {
            return $user;
        }

        return DB::transaction(function () use ($user, $role): User {
            if ($user->role === UserRole::Admin) {
                // Lock the other ADMIN rows so two concurrent demotions cannot both pass.
                $hasOtherAdmin = User::query()
                    ->where('role', UserRole::Admin->value)
                    ->whereKeyNot($user->id)
                    ->lockForUpdate()
                    ->exists();

                if (! $hasOtherAdmin) {
                    throw ValidationException::withMessages([
                        'role' => 'Không thể hạ quyền ADMIN cuối cùng của hệ thống.',
                    ]);
                }
            }

            $user->role = $role;
            $user->save();

            return $user;
        });
    }
}
