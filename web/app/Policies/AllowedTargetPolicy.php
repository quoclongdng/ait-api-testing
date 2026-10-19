<?php

namespace App\Policies;

use App\Models\AllowedTarget;
use App\Models\User;

class AllowedTargetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, AllowedTarget $allowedTarget): bool
    {
        return $user->isAdmin();
    }
}
