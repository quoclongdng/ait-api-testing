<?php

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\User;

class CountProjectsAction
{
    public function execute(User $user): int
    {
        $query = Project::query();

        if (! $user->isAdmin()) {
            $query->where('owner_id', $user->id);
        }

        return $query->count();
    }
}
