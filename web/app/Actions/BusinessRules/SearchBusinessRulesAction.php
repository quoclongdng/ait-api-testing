<?php

namespace App\Actions\BusinessRules;

use App\Models\BusinessRule;
use App\Models\Project;
use Illuminate\Pagination\LengthAwarePaginator;

class SearchBusinessRulesAction
{
    /**
     * Business rules of a project for M08, newest first, filtered by validated query input.
     *
     * The search is case-insensitive (PostgreSQL LIKE is not) and literal: "%" and "_" typed in the
     * search box match themselves, not any text, like M03 and the test case search (QA S13-03).
     * $endpointFilter is "global" (rules without an endpoint), an endpoint id, or null/'' for all.
     *
     * @return LengthAwarePaginator<int, BusinessRule>
     */
    public function execute(Project $project, ?string $search, ?string $endpointFilter, int $perPage = 15): LengthAwarePaginator
    {
        $query = $project->businessRules()->with('endpoint.apiSpec')->latest()->orderByDesc('id');

        $term = trim((string) $search);
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(function ($q) use ($like): void {
                $q->where('code', 'ilike', $like)
                    ->orWhere('description', 'ilike', $like);
            });
        }

        if ($endpointFilter === 'global') {
            $query->whereNull('endpoint_id');
        } elseif (is_numeric($endpointFilter)) {
            $query->where('endpoint_id', (int) $endpointFilter);
        }

        return $query->paginate($perPage);
    }
}
