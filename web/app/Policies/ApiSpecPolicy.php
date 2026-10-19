<?php

namespace App\Policies;

use App\Models\ApiSpec;
use App\Models\User;

class ApiSpecPolicy
{
    /**
     * Determine whether the user can view the API spec.
     */
    public function view(User $user, ApiSpec $apiSpec): bool
    {
        return $user->isAdmin() || $apiSpec->project->owner_id === $user->id;
    }
}
