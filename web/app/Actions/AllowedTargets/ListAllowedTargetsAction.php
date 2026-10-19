<?php

namespace App\Actions\AllowedTargets;

use App\Models\AllowedTarget;
use Illuminate\Database\Eloquent\Collection;

class ListAllowedTargetsAction
{
    /**
     * @return Collection<int, AllowedTarget>
     */
    public function execute(): Collection
    {
        return AllowedTarget::query()
            ->with('creator')
            ->orderBy('host')
            ->orderBy('port')
            ->get();
    }
}
