<?php

namespace App\Actions\BusinessRules;

use App\Models\BusinessRule;

class DeleteBusinessRuleAction
{
    public function execute(BusinessRule $rule): void
    {
        $rule->delete();
    }
}
