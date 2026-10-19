<?php

namespace App\Policies;

use App\Models\Benchmark;
use App\Models\User;

/**
 * Benchmarks and their ground truth are managed by ADMIN only (M14, docs/DESIGN.md §1).
 */
class BenchmarkPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Benchmark $benchmark): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Delete a benchmark no experiment uses (DeleteBenchmarkAction checks that, S14-06).
     */
    public function delete(User $user, Benchmark $benchmark): bool
    {
        return $user->isAdmin();
    }

    /**
     * Synchronise benchmarks from benchmark/<slug>/ground-truth.yaml.
     */
    public function import(User $user): bool
    {
        return $user->isAdmin();
    }
}
