<?php

namespace App\Actions\Projects;

use App\Models\Project;

class ShowProjectAction
{
    public function execute(Project $project): Project
    {
        return $project->loadCount(['apiSpecs', 'testSuites']);
    }
}
