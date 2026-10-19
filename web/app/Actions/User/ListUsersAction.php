<?php

namespace App\Actions\User;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListUsersAction
{
    /**
     * Users for M03 and GET /admin/users, filtered by validated input only.
     *
     * @param  array{search?: string|null, role?: string|null, status?: string|int|null}  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function execute(array $filters, int $perPage = 10): LengthAwarePaginator
    {
        $query = User::query();

        // QA S13-03: case-insensitive (PostgreSQL LIKE is not) and literal, so "%" or "_" typed in
        // the M03 search box no longer match every user.
        $search = is_string($filters['search'] ?? null) ? trim($filters['search']) : '';
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($q) use ($like): void {
                $q->where('name', 'ilike', $like)
                    ->orWhere('email', 'ilike', $like);
            });
        }

        $role = $filters['role'] ?? null;
        if (is_string($role) && $role !== '') {
            $query->where('role', $role);
        }

        $status = $filters['status'] ?? null;
        if ($status !== null && $status !== '') {
            $query->where('is_active', (string) $status === '1');
        }

        return $query->orderBy('id')->paginate($perPage);
    }
}
