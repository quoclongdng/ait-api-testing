<?php

namespace App\Actions\TestSuites;

use App\Models\Project;
use App\Models\TestSuite;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListTestSuitesAction
{
    /**
     * @return LengthAwarePaginator<int, TestSuite>
     */
    public function execute(Project $project, int $perPage = 12): LengthAwarePaginator
    {
        return $project->testSuites()
            ->with(['apiSpec', 'creator'])
            ->withCount('testCases')
            ->latest('id')
            ->paginate($perPage);
    }
}
