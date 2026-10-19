<?php

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

class ListProjectsAction
{
    /**
     * Return only the projects visible to the current user.
     *
     * @return LengthAwarePaginator<int, Project>
     */
    public function execute(User $user, int $perPage = 20, ?string $search = null): LengthAwarePaginator
    {
        $query = Project::query()
            ->with('owner')
            ->withCount(['apiSpecs', 'testSuites'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if (! $user->isAdmin()) {
            $query->where('owner_id', $user->id);
        }

        $normalizedSearch = trim((string) $search);

        // Literal search: "%" and "_" match themselves, not any text (QA S13-03, as in M03).
        if ($normalizedSearch !== '') {
            $query->where('name', 'ilike', '%'.addcslashes($normalizedSearch, '%_\\').'%');
        }

        return $query->paginate(min(max($perPage, 1), 100));
    }
}
