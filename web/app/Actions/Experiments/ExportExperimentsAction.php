<?php

namespace App\Actions\Experiments;

use App\Data\Reports\ExportedFile;
use App\Enums\ReportFormat;
use App\Models\Experiment;
use App\Services\Experiments\ExperimentExporter;

/**
 * Exports raw experiment data as JSON or CSV (S11-02): GET /api/v1/experiments/{id}/export and
 * `php artisan ait:experiments:export`.
 */
class ExportExperimentsAction
{
    public function __construct(private readonly ExperimentExporter $exporter) {}

    public function single(Experiment $experiment, ReportFormat $format): ExportedFile
    {
        $experiment->load(['benchmark.bugs', 'bugDetections']);

        return $this->exporter->export([$experiment], $format, "experiment-{$experiment->id}-export");
    }

    /**
     * @param  list<int>|null  $ids  null = every experiment, oldest first
     */
    public function many(?array $ids, ReportFormat $format): ExportedFile
    {
        $experiments = Experiment::query()
            ->with(['benchmark.bugs', 'bugDetections'])
            ->when($ids !== null, fn ($query) => $query->whereIn('id', $ids ?? []))
            ->orderBy('id')
            ->get()
            ->values()
            ->all();

        $stem = $ids !== null && count($ids) === 1 ? "experiment-{$ids[0]}-export" : 'experiments-export';

        return $this->exporter->export(array_values($experiments), $format, $stem);
    }
}
