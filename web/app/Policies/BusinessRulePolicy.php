<?php

namespace App\Policies;

use App\Models\BusinessRule;
use App\Models\Project;
use App\Models\User;

class BusinessRulePolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        return $user->isAdmin() || $project->owner_id === $user->id;
    }

    public function view(User $user, BusinessRule $rule): bool
    {
        return $user->isAdmin() || $rule->project->owner_id === $user->id;
    }

    public function create(User $user, Project $project): bool
    {
        return $user->isAdmin() || $project->owner_id === $user->id;
    }

    public function update(User $user, BusinessRule $rule): bool
    {
        return $this->view($user, $rule);
    }

    public function delete(User $user, BusinessRule $rule): bool
    {
        return $this->view($user, $rule);
    }
}
