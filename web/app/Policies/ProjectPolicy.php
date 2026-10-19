<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isTester();
    }

    public function view(User $user, Project $project): bool
    {
        return $user->isAdmin() || $project->owner_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isTester();
    }

    public function update(User $user, Project $project): bool
    {
        return $this->view($user, $project);
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->view($user, $project);
    }

    public function createSpec(User $user, Project $project): bool
    {
        return $user->isAdmin() || $project->owner_id === $user->id;
    }
}
