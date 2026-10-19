<?php

namespace App\Policies;

use App\Models\Experiment;
use App\Models\User;

/**
 * Experiments are shared research results: every active TESTER and ADMIN may list, view and
 * start them (docs/DESIGN.md §6: GET/POST /experiments — TESTER, ADMIN). The generated suites and
 * runs stay in a project owned by the user who started the experiment, so the usual project
 * policies still protect them.
 */
class ExperimentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isTester();
    }

    public function view(User $user, Experiment $experiment): bool
    {
        return $user->isAdmin() || $user->isTester();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isTester();
    }
}
