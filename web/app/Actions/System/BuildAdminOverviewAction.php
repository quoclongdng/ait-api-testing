<?php

namespace App\Actions\System;

use App\Actions\Runs\ComputeRecentPassRateAction;
use App\Actions\Runs\ListRecentRunsAction;
use App\Data\AdminOverview;
use App\Enums\TestRunStatus;
use App\Enums\UserRole;
use App\Models\AllowedTarget;
use App\Models\Benchmark;
use App\Models\BenchmarkBug;
use App\Models\Experiment;
use App\Models\Project;
use App\Models\TestRun;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * ADMIN dashboard "Tổng quan quản trị" (S14-04): system-wide user, project, run, benchmark and
 * service figures. Only an ADMIN may build it (Gate `admin`).
 */
final class BuildAdminOverviewAction
{
    public const RUN_WINDOW_DAYS = 7;

    public function __construct(
        private readonly CheckHealthAction $checkHealth,
        private readonly ListRecentRunsAction $listRecentRuns,
        private readonly ComputeRecentPassRateAction $computeRecentPassRate,
    ) {}

    public function execute(User $admin): AdminOverview
    {
        Gate::forUser($admin)->authorize('admin');

        $userCounts = User::query()
            ->selectRaw('count(*) filter (where is_active) as active')
            ->selectRaw('count(*) filter (where not is_active) as locked')
            ->selectRaw('count(*) filter (where role = ?) as admins', [UserRole::Admin->value])
            ->toBase()
            ->first();

        // Runs of soft-deleted projects are unreachable (404) and not counted (S14-06).
        $runCounts = TestRun::query()
            ->withLiveProject()
            ->where('created_at', '>=', now()->subDays(self::RUN_WINDOW_DAYS))
            ->selectRaw('count(*) as total')
            ->selectRaw('count(*) filter (where status = ?) as completed', [TestRunStatus::Completed->value])
            ->selectRaw('count(*) filter (where status = ?) as failed', [TestRunStatus::Failed->value])
            ->toBase()
            ->first();

        return new AdminOverview(
            activeUsers: (int) ($userCounts->active ?? 0),
            lockedUsers: (int) ($userCounts->locked ?? 0),
            adminUsers: (int) ($userCounts->admins ?? 0),
            projects: Project::query()->count(),
            runsLast7Days: (int) ($runCounts->total ?? 0),
            completedRunsLast7Days: (int) ($runCounts->completed ?? 0),
            failedRunsLast7Days: (int) ($runCounts->failed ?? 0),
            activeRuns: TestRun::query()
                ->withLiveProject()
                ->whereIn('status', [TestRunStatus::Queued->value, TestRunStatus::Running->value])
                ->count(),
            passRateLast7Days: $this->computeRecentPassRate->execute($admin, self::RUN_WINDOW_DAYS),
            benchmarks: Benchmark::query()->count(),
            benchmarkBugs: BenchmarkBug::query()->count(),
            experiments: Experiment::query()->count(),
            allowedTargets: AllowedTarget::query()->count(),
            health: $this->checkHealth->execute(),
            recentRuns: $this->listRecentRuns->execute($admin),
        );
    }
}
