<?php

namespace App\Data;

use App\Models\TestRun;
use Illuminate\Database\Eloquent\Collection;

/**
 * System-wide figures of the ADMIN dashboard "Tổng quan quản trị" (S14-04).
 */
final readonly class AdminOverview
{
    /**
     * @param  array{status: 'ok'|'degraded', checks: array{database: bool, ai_service: array{reachable: bool, provider: ?string, version: ?string}}}  $health
     * @param  Collection<int, TestRun>  $recentRuns
     */
    public function __construct(
        public int $activeUsers,
        public int $lockedUsers,
        public int $adminUsers,
        public int $projects,
        public int $runsLast7Days,
        public int $completedRunsLast7Days,
        public int $failedRunsLast7Days,
        public int $activeRuns,
        public ?float $passRateLast7Days,
        public int $benchmarks,
        public int $benchmarkBugs,
        public int $experiments,
        public int $allowedTargets,
        public array $health,
        public Collection $recentRuns,
    ) {}

    public function totalUsers(): int
    {
        return $this->activeUsers + $this->lockedUsers;
    }
}
