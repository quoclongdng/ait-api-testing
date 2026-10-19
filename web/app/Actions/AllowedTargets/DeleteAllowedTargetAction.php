<?php

namespace App\Actions\AllowedTargets;

use App\Models\AllowedTarget;

class DeleteAllowedTargetAction
{
    public function execute(AllowedTarget $allowedTarget): void
    {
        $allowedTarget->delete();
    }
}
