<?php

namespace App\Policies;

use App\Models\TestCase;
use App\Models\User;

class TestCasePolicy
{
    public function view(User $user, TestCase $testCase): bool
    {
        return $user->isAdmin() || $testCase->suite->project->owner_id === $user->id;
    }

    public function update(User $user, TestCase $testCase): bool
    {
        return $this->view($user, $testCase);
    }

    public function delete(User $user, TestCase $testCase): bool
    {
        return $this->view($user, $testCase);
    }

    public function approve(User $user, TestCase $testCase): bool
    {
        return $this->view($user, $testCase);
    }

    public function archive(User $user, TestCase $testCase): bool
    {
        return $this->view($user, $testCase);
    }
}
