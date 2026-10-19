<?php

namespace App\Actions\TestCases;

use App\Enums\TestCaseStatus;
use App\Models\TestCase;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class CountApprovedTestCasesAction
{
    /**
     * M02: number of APPROVED test cases the user may see; cases of a soft-deleted project are
     * unreachable (404) and not counted, for an ADMIN either (S14-06).
     */
    public function execute(User $user): int
    {
        $query = TestCase::query()->where('status', TestCaseStatus::Approved->value);

        if ($user->isAdmin()) {
            $query->withLiveProject();
        } else {
            $query->whereHas('suite.project', fn (Builder $project) => $project->where('owner_id', $user->id));
        }

        return $query->count();
    }
}
