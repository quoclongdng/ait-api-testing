<?php

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\User;

class CreateProjectAction
{
    /**
     * @param  array{name: string, description?: string|null, default_target_url?: string|null}  $data
     */
    public function execute(User $user, array $data): Project
    {
        return $user->projects()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'default_target_url' => $data['default_target_url'] ?? null,
        ]);
    }
}
