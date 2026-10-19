<?php

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\User;

/**
 * Most recently updated project the user can see (own projects; every project for ADMIN), used by
 * the M02 quick action "Tải lên OpenAPI" to open that project's M06 screen (S13-03).
 */
class FindLatestProjectAction
{
    public function execute(User $user): ?Project
    {
        $query = Project::query();

        if (! $user->isAdmin()) {
            $query->where('owner_id', $user->id);
        }

        return $query->orderByDesc('updated_at')->orderByDesc('id')->first();
    }
}
