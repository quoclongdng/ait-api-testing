<?php

namespace App\Actions\User;

use App\Enums\ErrorCode;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ADMIN renames a user, changes their role or locks/unlocks them (docs/DESIGN.md §1, M03,
 * PATCH /admin/users/{userId}). An admin cannot change their own role or lock themselves,
 * and the last active ADMIN can be neither demoted nor locked.
 */
class UpdateUserAction
{
    /**
     * @param  array{name?: string, role?: UserRole|string, is_active?: bool|int|string}  $data
     *
     * @throws ApiException
     */
    public function execute(User $actor, User $user, array $data): User
    {
        return DB::transaction(function () use ($actor, $user, $data): User {
            // Serialize admin changes so two concurrent demotions cannot remove the last ADMIN.
            $admins = User::query()->where('role', UserRole::Admin->value)->lockForUpdate()->get(['id', 'is_active']);
            $locked = User::query()->lockForUpdate()->whereKey($user->getKey())->firstOrFail();

            $attributes = [];
            if (array_key_exists('name', $data)) {
                $attributes['name'] = $data['name'];
            }
            if (array_key_exists('role', $data)) {
                $attributes['role'] = $data['role'] instanceof UserRole ? $data['role'] : UserRole::from((string) $data['role']);
            }
            if (array_key_exists('is_active', $data)) {
                $attributes['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
            }

            $roleChanges = isset($attributes['role']) && $attributes['role'] !== $locked->role;
            $deactivates = ($attributes['is_active'] ?? $locked->is_active) === false && $locked->is_active;

            if ($locked->id === $actor->id && $roleChanges) {
                throw $this->invalid('role', 'Không thể tự đổi vai trò của chính mình.');
            }
            if ($locked->id === $actor->id && $deactivates) {
                throw $this->invalid('is_active', 'Không thể tự khóa tài khoản của chính mình.');
            }

            $losesAdmin = $locked->isAdmin() && $locked->is_active && ($roleChanges || $deactivates);
            $activeAdmins = $admins->where('is_active', true)->count();
            if ($losesAdmin && $activeAdmins <= 1) {
                throw $this->invalid($roleChanges ? 'role' : 'is_active', 'Phải còn ít nhất một ADMIN đang hoạt động.');
            }

            $locked->fill($attributes)->save();

            return $locked;
        });
    }

    private function invalid(string $field, string $message): ApiException
    {
        return new ApiException(ErrorCode::ValidationFailed, $message, [$field => [$message]]);
    }
}
