<?php

namespace App\Actions\Projects;

use App\Models\Project;

class UpdateProjectAction
{
    /**
     * @param  array{name?: string, description?: string|null, default_target_url?: string|null}  $data
     */
    public function execute(Project $project, array $data): Project
    {
        $project->update($data);

        return $project->refresh();
    }
}
