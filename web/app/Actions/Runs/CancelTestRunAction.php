<?php

namespace App\Actions\Runs;

use App\Enums\TestRunStatus;
use App\Models\TestRun;

class CancelTestRunAction
{
    public function __construct(
        private TransitionTestRunStatusAction $transitionAction,
    ) {}

    public function execute(TestRun $testRun): TestRun
    {
        return $this->transitionAction->execute($testRun, TestRunStatus::Cancelled);
    }
}
