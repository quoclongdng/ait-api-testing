<?php

namespace App\Actions\Experiments;

use App\Enums\ErrorCode;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\User;

/**
 * Finds the account that artisan experiment commands act as (S11-02): the active user with the
 * given e-mail, or the first active ADMIN when no e-mail is given. Generated suites and runs
 * belong to this user, exactly as when the experiment is started from M15.
 */
class ResolveExperimentOperatorAction
{
    /**
     * @throws ApiException
     */
    public function execute(?string $email): User
    {
        $query = User::query()->where('is_active', true);
        $user = $email !== null && trim($email) !== ''
            ? $query->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])->first()
            : $query->where('role', UserRole::Admin->value)->orderBy('id')->first();

        if (! $user instanceof User || ! ($user->isAdmin() || $user->isTester())) {
            throw new ApiException(
                ErrorCode::NotFound,
                $email !== null && trim($email) !== ''
                    ? "Không tìm thấy người dùng đang hoạt động với email {$email}."
                    : 'Không có tài khoản ADMIN đang hoạt động; hãy chạy seeder hoặc truyền --user=<email>.',
            );
        }

        return $user;
    }
}
