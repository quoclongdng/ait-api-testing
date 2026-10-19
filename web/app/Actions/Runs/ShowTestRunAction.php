<?php

namespace App\Actions\Runs;

use App\Models\TestRun;

class ShowTestRunAction
{
    public function execute(TestRun $testRun): TestRun
    {
        return $testRun->loadMissing(['testSuite.project', 'triggerer']);
    }
}
