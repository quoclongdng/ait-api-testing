<?php

namespace App\Actions\TestCases;

use App\Enums\TestCaseStatus;
use App\Models\TestCase;
use App\Models\TestSuite;
use Illuminate\Support\Facades\DB;

class BulkTransitionTestCasesAction
{
    /**
     * Apply a §4.2 transition to every selected case of the suite that allows it.
     * Cases of other suites or in a non-eligible status are skipped.
     *
     * @param  list<int>  $testCaseIds
     * @return int number of transitioned cases
     */
    public function execute(TestSuite $suite, array $testCaseIds, TestCaseStatus $target): int
    {
        return DB::transaction(function () use ($suite, $testCaseIds, $target): int {
            $count = 0;

            $cases = TestCase::query()
                ->where('test_suite_id', $suite->id)
                ->whereIn('id', $testCaseIds)
                ->lockForUpdate()
                ->get();

            foreach ($cases as $case) {
                if (TransitionTestCaseStatusAction::canTransition($case->status, $target)) {
                    $case->update(['status' => $target]);
                    $count++;
                }
            }

            return $count;
        });
    }
}
