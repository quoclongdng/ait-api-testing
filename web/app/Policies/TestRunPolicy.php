<?php

namespace App\Policies;

use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;

class TestRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isTester();
    }

    public function view(User $user, TestRun $testRun): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $testRun->loadMissing('testSuite.project');

        return $testRun->testSuite->project->owner_id === $user->id;
    }

    public function create(User $user, ?TestSuite $suite = null): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($suite === null) {
            return $user->isTester();
        }

        $suite->loadMissing('project');

        return $suite->project->owner_id === $user->id;
    }

    public function cancel(User $user, TestRun $testRun): bool
    {
        return $this->view($user, $testRun);
    }
}
