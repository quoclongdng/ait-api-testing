<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\TestSuite;
use App\Models\User;

class TestSuitePolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        return $user->isAdmin() || $project->owner_id === $user->id;
    }

    public function view(User $user, TestSuite $suite): bool
    {
        return $user->isAdmin() || $suite->project->owner_id === $user->id;
    }

    public function create(User $user, Project $project): bool
    {
        return $user->isAdmin() || $project->owner_id === $user->id;
    }

    public function update(User $user, TestSuite $suite): bool
    {
        return $this->view($user, $suite);
    }

    public function delete(User $user, TestSuite $suite): bool
    {
        return $this->view($user, $suite);
    }
}
