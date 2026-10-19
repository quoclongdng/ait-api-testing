<?php

namespace App\Policies;

use App\Models\GenerationJob;
use App\Models\TestSuite;
use App\Models\User;

class GenerationJobPolicy
{
    public function viewAny(User $user, TestSuite $suite): bool
    {
        return $user->isAdmin() || $suite->project->owner_id === $user->id;
    }

    public function view(User $user, GenerationJob $job): bool
    {
        return $user->isAdmin() || $job->project->owner_id === $user->id;
    }

    public function create(User $user, TestSuite $suite): bool
    {
        return $user->isAdmin() || $suite->project->owner_id === $user->id;
    }

    public function cancel(User $user, GenerationJob $job): bool
    {
        return $this->view($user, $job);
    }
}
