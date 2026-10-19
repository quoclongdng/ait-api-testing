<?php

namespace App\Actions\Experiments;

use App\Data\Reports\ExportedFile;
use App\Enums\ReportFormat;
use App\Models\Experiment;
use App\Services\Experiments\ExperimentExporter;
use App\Services\Experiments\ExperimentSummaryBuilder;
use Illuminate\Support\Carbon;

/**
 * Builds the result folder of `make experiment` (S12-02,
 * `php artisan ait:experiments:export --dir=...`):
 *
 * - `experiment-<id>.json` / `experiment-<id>.csv`: raw data of each experiment (ExperimentExporter);
 * - `experiments.json` / `experiments.csv`: every selected experiment in one file;
 * - `summary.csv` / `summary.md`: §11 metrics, mean ± std per benchmark × strategy (ExperimentSummaryBuilder).
 */
class ExportExperimentBundleAction
{
    public function __construct(
        private readonly ExperimentExporter $exporter,
        private readonly ExperimentSummaryBuilder $summary,
    ) {}

    /**
     * @param  list<int>|null  $ids  null = every experiment, oldest first
     * @return list<ExportedFile>
     */
    public function execute(?array $ids): array
    {
        $experiments = Experiment::query()
            ->with(['benchmark.bugs', 'bugDetections'])
            ->when($ids !== null, fn ($query) => $query->whereIn('id', $ids ?? []))
            ->orderBy('id')
            ->get()
            ->values()
            ->all();
        $experiments = array_values($experiments);

        $files = [];
        foreach ($experiments as $experiment) {
            foreach ([ReportFormat::Json, ReportFormat::Csv] as $format) {
                $files[] = $this->exporter->export([$experiment], $format, "experiment-{$experiment->id}");
            }
        }
        foreach ([ReportFormat::Json, ReportFormat::Csv] as $format) {
            $files[] = $this->exporter->export($experiments, $format, 'experiments');
        }

        $files[] = new ExportedFile($this->summary->toCsv($experiments), ReportFormat::Csv->contentType(), 'summary.csv');
        $files[] = new ExportedFile(
            $this->summary->toMarkdown($experiments, Carbon::now()->utc()->format('Y-m-d H:i:s')),
            'text/markdown; charset=UTF-8',
            'summary.md',
        );

        return $files;
    }
}
