<?php

namespace App\Actions\Runs;

use App\Enums\TestVerdict;
use App\Models\TestResult;
use App\Models\TestRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListTestResultsAction
{
    /**
     * @return LengthAwarePaginator<int, TestResult>
     */
    public function execute(TestRun $testRun, int $perPage = 20, ?TestVerdict $verdict = null): LengthAwarePaginator
    {
        $query = $testRun->testResults()->orderBy('id');

        if ($verdict !== null) {
            $query->where('verdict', $verdict->value);
        }

        return $query->paginate(min(max($perPage, 1), 100));
    }
}
