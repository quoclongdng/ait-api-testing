<?php

namespace App\Actions\BusinessRules;

use App\Models\BusinessRule;
use App\Models\Project;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListBusinessRulesAction
{
    /**
     * @return LengthAwarePaginator<int, BusinessRule>
     */
    public function execute(Project $project, int $perPage = 20): LengthAwarePaginator
    {
        return $project->businessRules()->orderBy('id')->paginate($perPage);
    }
}
