<?php

namespace App\Actions\Experiments;

use App\Models\Experiment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListExperimentsAction
{
    /**
     * Newest first.
     *
     * @return LengthAwarePaginator<int, Experiment>
     */
    public function execute(int $perPage = 20): LengthAwarePaginator
    {
        return Experiment::query()->with('benchmark')->orderByDesc('id')->paginate($perPage);
    }
}
