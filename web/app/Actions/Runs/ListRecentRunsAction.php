<?php

namespace App\Actions\Runs;

use App\Models\TestRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ListRecentRunsAction
{
    /**
     * M02: latest runs the user may see (ADMIN: every project, TESTER: own projects), most recently
     * finished first; a run still in progress counts from its creation. Ordering by time, not by id,
     * keeps runs imported later but executed earlier (official experiment records of the demo
     * showcase, dated when they ran) below the user's own recent runs (QA S14-06).
     *
     * @return Collection<int, TestRun>
     */
    public function execute(User $user, int $limit = 5): Collection
    {
        return self::visibleTo($user)
            ->with('testSuite')
            ->orderByRaw('coalesce(finished_at, created_at) desc')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Runs the user may open: ADMIN every run of a live project, TESTER the runs of own projects.
     * A run of a soft-deleted project is 404 for every role (BelongsToLiveProject), so it is left
     * out of the ADMIN dashboard too, not only out of the TESTER one (S14-06).
     *
     * @return Builder<TestRun>
     */
    public static function visibleTo(User $user): Builder
    {
        if ($user->isAdmin()) {
            return TestRun::query()->withLiveProject();
        }

        return TestRun::query()
            ->whereHas('testSuite.project', fn (Builder $project) => $project->where('owner_id', $user->id));
    }
}
