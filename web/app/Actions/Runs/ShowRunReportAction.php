<?php

namespace App\Actions\Runs;

use App\Data\Reports\RunReport;
use App\Models\TestRun;
use App\Services\Reports\RunReportBuilder;

/**
 * Builds the M13 report of a test run (S8-01).
 */
class ShowRunReportAction
{
    public function __construct(private RunReportBuilder $builder) {}

    public function execute(TestRun $run): RunReport
    {
        return $this->builder->build($run);
    }
}
