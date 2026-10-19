<?php

namespace App\Actions\Generation;

use App\Models\GenerationJob;
use App\Models\TestSuite;
use Illuminate\Database\Eloquent\Collection;

class ListRecentSuiteGenerationJobsAction
{
    /**
     * M09: latest generation jobs of a suite, each linking to its M11 progress/statistics page.
     *
     * @return Collection<int, GenerationJob>
     */
    public function execute(TestSuite $suite, int $limit = 5): Collection
    {
        return $suite->generationJobs()
            ->latest('id')
            ->limit($limit)
            ->get(['id', 'test_suite_id', 'strategy', 'status', 'created_at', 'finished_at']);
    }
}
