<?php

namespace App\Actions\Runs;

use App\Enums\TestVerdict;
use App\Models\TestResult;
use App\Models\User;

class ComputeRecentPassRateAction
{
    /**
     * M02: PASS / (PASS + FAIL + ERROR) over results recorded in the last $days days
     * of runs the user may see. SKIPPED results are excluded; null when nothing ran.
     */
    public function execute(User $user, int $days = 7): ?float
    {
        $query = TestResult::query()
            ->where('created_at', '>=', now()->subDays($days))
            ->where('verdict', '!=', TestVerdict::Skipped->value)
            ->whereIn('test_run_id', ListRecentRunsAction::visibleTo($user)->select('id'));

        $total = (clone $query)->count();
        if ($total === 0) {
            return null;
        }

        $passed = (clone $query)->where('verdict', TestVerdict::Pass->value)->count();

        return $passed / $total;
    }
}
