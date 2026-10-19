<?php

namespace App\Actions\User;

use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * M03 "Khóa / Mở khóa" button. Goes through UpdateUserAction so it shares the same
 * transaction, ADMIN-row locks, self-lock guard and last-active-ADMIN guard as
 * PATCH /admin/users/{id}. The form sends the target state explicitly; when it is
 * missing, the flip is computed from the row read under the lock, never from a stale model.
 */
class ToggleUserStatusAction
{
    public function __construct(
        private readonly UpdateUserAction $updateUser,
    ) {}

    /**
     * @throws ValidationException
     */
    public function execute(User $actor, User $user, ?bool $targetActive = null): User
    {
        try {
            return DB::transaction(function () use ($actor, $user, $targetActive): User {
                // Same lock order as UpdateUserAction (ADMIN rows, then the target) to avoid deadlocks.
                User::query()->where('role', UserRole::Admin->value)->lockForUpdate()->get(['id']);
                $locked = User::query()->lockForUpdate()->whereKey($user->getKey())->firstOrFail();

                $target = $targetActive ?? ! $locked->is_active;
                if ($target === $locked->is_active) {
                    return $locked;
                }

                return $this->updateUser->execute($actor, $locked, ['is_active' => $target]);
            });
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['user' => $e->getMessage()]);
        }
    }
}
