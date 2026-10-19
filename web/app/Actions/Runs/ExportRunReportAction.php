<?php

namespace App\Actions\Runs;

use App\Data\Reports\ExportedFile;
use App\Enums\ReportFormat;
use App\Models\TestRun;
use App\Services\Reports\RunReportBuilder;
use App\Services\Reports\RunReportExporter;

/**
 * Exports the report of a test run as JSON, CSV or HTML (S8-01, GET /api/v1/runs/{id}/export).
 */
class ExportRunReportAction
{
    public function __construct(
        private RunReportBuilder $builder,
        private RunReportExporter $exporter,
    ) {}

    public function execute(TestRun $run, ReportFormat $format): ExportedFile
    {
        return $this->exporter->export($this->builder->build($run), $format);
    }
}
