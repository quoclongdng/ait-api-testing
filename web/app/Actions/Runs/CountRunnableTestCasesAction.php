<?php

namespace App\Actions\Runs;

use App\Enums\TestCaseStatus;
use App\Models\TestSuite;

/**
 * Number of cases a new run of the suite would execute: only VALID or APPROVED (docs/DESIGN.md §4.2).
 */
class CountRunnableTestCasesAction
{
    public function execute(TestSuite $suite): int
    {
        return $suite->testCases()
            ->whereIn('status', [TestCaseStatus::Valid->value, TestCaseStatus::Approved->value])
            ->count();
    }
}
