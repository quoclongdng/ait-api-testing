<?php

namespace App\Actions\Runs;

use App\Models\TestRun;
use App\Models\TestSuite;
use Illuminate\Database\Eloquent\Collection;

class ListRecentSuiteRunsAction
{
    /**
     * M09: latest runs of a suite, each linking to its M13 report.
     *
     * @return Collection<int, TestRun>
     */
    public function execute(TestSuite $suite, int $limit = 5): Collection
    {
        return $suite->testRuns()
            ->latest('id')
            ->limit($limit)
            ->get();
    }
}
