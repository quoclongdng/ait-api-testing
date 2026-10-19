<?php

namespace App\Actions\Runs;

use App\Models\TestRun;
use App\Models\TestSuite;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListSuiteTestRunsAction
{
    /**
     * @return LengthAwarePaginator<int, TestRun>
     */
    public function execute(TestSuite $suite, int $perPage = 15): LengthAwarePaginator
    {
        return $suite->testRuns()
            ->with('triggerer')
            ->orderByDesc('id')
            ->paginate(min(max($perPage, 1), 100));
    }
}
