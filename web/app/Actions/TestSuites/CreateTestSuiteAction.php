<?php

namespace App\Actions\TestSuites;

use App\Models\Project;
use App\Models\TestSuite;
use App\Models\User;

class CreateTestSuiteAction
{
    /**
     * @param  array{api_spec_id: int, name: string, description?: string|null}  $data
     */
    public function execute(User $user, Project $project, array $data): TestSuite
    {
        return TestSuite::create([
            'project_id' => $project->id,
            'api_spec_id' => $data['api_spec_id'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'created_by' => $user->id,
        ]);
    }
}
