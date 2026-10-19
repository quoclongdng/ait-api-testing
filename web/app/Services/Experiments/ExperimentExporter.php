<?php

namespace App\Services\Experiments;

use App\Data\Reports\ExportedFile;
use App\Enums\ReportFormat;
use App\Models\BenchmarkBug;
use App\Models\Experiment;
use App\Models\ExperimentBugDetection;
use App\Services\Reports\RunReportExporter;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;

/**
 * Serialises experiments with their raw data for analysis (S11-02, S12-01):
 * configuration (model, temperature, seeds), per-repetition links and metrics, per-bug D14
 * detections and the aggregated §11 metrics.
 *
 * - JSON: `{format_version, exported_at, experiments: [...]}` (ExperimentExport in web-api.openapi.yaml).
 * - CSV: one "long" table (UTF-8 BOM, CSV-injection safe) with one row per value, so experiments,
 *   repetitions and bugs can be filtered in a spreadsheet or pandas. `record_type` is one of
 *   metric | stddev | repetition | detection | error.
 */
final class ExperimentExporter
{
    public const FORMAT_VERSION = '1.0';

    public const CSV_HEADER = [
        'experiment_id', 'experiment_name', 'benchmark', 'benchmark_version', 'strategy', 'status',
        'model', 'temperature', 'repetitions', 'record_type', 'repetition', 'seed', 'bug_code',
        'bug_category', 'key', 'value',
    ];

    /**
     * Experiments must have `benchmark.bugs` and `bugDetections` loaded.
     *
     * @param  list<Experiment>  $experiments
     */
    public function export(array $experiments, ReportFormat $format, string $filenameStem): ExportedFile
    {
        $document = $this->document($experiments);

        $content = match ($format) {
            ReportFormat::Json => json_encode(
                $document,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            ),
            ReportFormat::Csv => $this->toCsv($document['experiments']),
            ReportFormat::Html => throw new InvalidArgumentException('Thực nghiệm chỉ xuất được JSON hoặc CSV.'),
        };

        return new ExportedFile($content, $format->contentType(), "{$filenameStem}.{$format->value}");
    }

    /**
     * @param  list<Experiment>  $experiments
     * @return array{format_version: string, exported_at: string, experiments: list<array<string, mixed>>}
     */
    public function document(array $experiments): array
    {
        return [
            'format_version' => self::FORMAT_VERSION,
            'exported_at' => Carbon::now()->toISOString() ?? '',
            'experiments' => array_map(fn (Experiment $experiment): array => $this->experiment($experiment), $experiments),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function experiment(Experiment $experiment): array
    {
        $config = $experiment->config;
        $metrics = $experiment->metrics;
        /** @var array<int, array<string, mixed>> $perRepetitionMetrics */
        $perRepetitionMetrics = [];
        if (is_array($metrics) && is_array($metrics['repetitions'] ?? null)) {
            foreach ($metrics['repetitions'] as $row) {
                if (is_array($row) && is_numeric($row['repetition'] ?? null)) {
                    $perRepetitionMetrics[(int) $row['repetition']] = $row;
                }
            }
        }

        $repetitions = [];
        foreach ($experiment->repetitions_data ?? [] as $entry) {
            $number = is_numeric($entry['repetition'] ?? null) ? (int) $entry['repetition'] : 0;
            $repetitions[] = [
                'repetition' => $number,
                'seed' => $entry['seed'] ?? null,
                'generation_job_id' => $entry['generation_job_id'] ?? null,
                'generation_job_ids' => $this->generationJobIds($entry),
                'test_suite_id' => $entry['test_suite_id'] ?? null,
                'clean_test_run_id' => $entry['clean_test_run_id'] ?? null,
                'bug_runs' => is_array($entry['bug_runs'] ?? null) && $entry['bug_runs'] !== [] ? $entry['bug_runs'] : (object) [],
                'metrics' => $perRepetitionMetrics[$number] ?? null,
            ];
        }

        /** @var array<int, BenchmarkBug> $bugs */
        $bugs = $experiment->benchmark->bugs->keyBy('id')->all();
        $detections = $experiment->bugDetections
            ->sortBy(fn (ExperimentBugDetection $d): string => sprintf('%06d|%s', $d->repetition, $bugs[$d->benchmark_bug_id]->code ?? ''))
            ->map(function (ExperimentBugDetection $d) use ($bugs): array {
                $bug = $bugs[$d->benchmark_bug_id] ?? null;

                return [
                    'repetition' => $d->repetition,
                    'benchmark_bug_id' => $d->benchmark_bug_id,
                    'bug_code' => $bug?->code,
                    'category' => $bug?->category->value,
                    'method' => $bug?->method->value,
                    'path' => $bug?->path,
                    'detected' => $d->detected,
                    'clean_test_run_id' => $d->clean_test_run_id,
                    'bug_test_run_id' => $d->bug_test_run_id,
                    'detecting_test_case_ids' => array_values($d->detecting_test_case_ids ?? []),
                ];
            })
            ->values()
            ->all();

        return [
            'id' => $experiment->id,
            'name' => $experiment->name,
            'strategy' => $experiment->strategy->value,
            'status' => $experiment->status->value,
            'benchmark' => [
                'id' => $experiment->benchmark->id,
                'slug' => $experiment->benchmark->slug,
                'name' => $experiment->benchmark->name,
                'version' => $experiment->benchmark->version,
                'bugs_total' => $this->bugsTotal($metrics, $experiment, $bugs),
            ],
            'config' => [
                'model' => $config['model'] ?? null,
                'temperature' => isset($config['temperature']) && is_numeric($config['temperature']) ? (float) $config['temperature'] : null,
                'seed' => $config['seed'] ?? null,
                'seeds' => $config['seeds'] ?? array_values(array_filter(array_column($repetitions, 'seed'), fn (mixed $s): bool => $s !== null)),
                'repetitions' => $config['repetitions'] ?? count($repetitions),
                // ADR-0006 snapshot. null: experiment created before the snapshot existed, whose LLM/HYBRID
                // repetitions read the live project business rules (contract: ExperimentExportItem).
                'auth' => is_array($config['auth'] ?? null) ? $config['auth'] : null,
                'business_rules' => is_array($config['business_rules'] ?? null) ? array_values($config['business_rules']) : null,
                // ADR-0006 addendum 2026-10-12; experiments created before it ran without per-case reset.
                'reset_per_case' => ($config['reset_per_case'] ?? false) === true,
            ],
            'started_at' => $experiment->started_at?->toISOString(),
            'finished_at' => $experiment->finished_at?->toISOString(),
            'metrics' => $metrics,
            'repetitions' => $repetitions,
            'bug_detections' => $detections,
        ];
    }

    /**
     * Every generation job that filled the repetition suite (HYBRID: the RULE seed job, then the
     * HYBRID job), which is the case set the repetition metrics were computed from.
     *
     * @param  array<string, mixed>  $entry
     * @return list<int>
     */
    private function generationJobIds(array $entry): array
    {
        $ids = is_array($entry['generation_job_ids'] ?? null) && $entry['generation_job_ids'] !== []
            ? $entry['generation_job_ids']
            : [$entry['generation_job_id'] ?? null];

        return array_values(array_map(fn (mixed $id): int => (int) $id, array_filter($ids, 'is_numeric')));
    }

    /**
     * Number of seeded bugs the experiment planned (the D14 denominator, metrics.bugs_total), not the
     * number of benchmark_bugs rows, which keeps stale rows removed from ground-truth.yaml.
     *
     * @param  array<string, mixed>|null  $metrics
     * @param  array<int, BenchmarkBug>  $bugs
     */
    private function bugsTotal(?array $metrics, Experiment $experiment, array $bugs): int
    {
        if (is_array($metrics) && is_int($metrics['bugs_total'] ?? null)) {
            return $metrics['bugs_total'];
        }
        $detected = $experiment->bugDetections->pluck('benchmark_bug_id')->unique()->count();

        return $detected > 0 ? $detected : count($bugs);
    }

    /**
     * @param  list<array<string, mixed>>  $experiments
     */
    private function toCsv(array $experiments): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new RuntimeException('Không thể tạo bộ đệm CSV.');
        }

        $this->writeRow($handle, self::CSV_HEADER);
        foreach ($experiments as $experiment) {
            /** @var array{id: int, name: string, strategy: string, status: string, benchmark: array{slug: string, version: string}, config: array{model: mixed, temperature: mixed, repetitions: mixed}, metrics: array<string, mixed>|null, repetitions: list<array<string, mixed>>, bug_detections: list<array<string, mixed>>} $experiment */
            $prefix = [
                $experiment['id'], $experiment['name'], $experiment['benchmark']['slug'], $experiment['benchmark']['version'],
                $experiment['strategy'], $experiment['status'], $experiment['config']['model'],
                $experiment['config']['temperature'], $experiment['config']['repetitions'],
            ];
            $metrics = $experiment['metrics'] ?? [];

            foreach ($metrics as $key => $value) {
                if (is_scalar($value) && $key !== 'error') {
                    $this->writeRow($handle, [...$prefix, 'metric', null, null, null, null, $key, $value]);
                }
            }
            foreach (is_array($metrics['stddev'] ?? null) ? $metrics['stddev'] : [] as $key => $value) {
                $this->writeRow($handle, [...$prefix, 'stddev', null, null, null, null, $key, $value]);
            }
            foreach ($experiment['repetitions'] as $repetition) {
                $values = is_array($repetition['metrics'] ?? null) ? $repetition['metrics'] : [];
                $jobIds = is_array($repetition['generation_job_ids'] ?? null) ? $repetition['generation_job_ids'] : [];
                $values += [
                    'generation_job_id' => $repetition['generation_job_id'] ?? null,
                    'generation_job_ids' => implode(';', array_map('strval', $jobIds)),
                    'test_suite_id' => $repetition['test_suite_id'] ?? null,
                    'clean_test_run_id' => $repetition['clean_test_run_id'] ?? null,
                ];
                foreach ($values as $key => $value) {
                    if ($key === 'repetition' || $key === 'seed' || ! (is_scalar($value) || $value === null)) {
                        continue;
                    }
                    $this->writeRow($handle, [...$prefix, 'repetition', $repetition['repetition'], $repetition['seed'], null, null, $key, $value]);
                }
            }
            $seeds = [];
            foreach ($experiment['repetitions'] as $repetition) {
                $seeds[(int) $repetition['repetition']] = $repetition['seed'];
            }
            foreach ($experiment['bug_detections'] as $detection) {
                $number = (int) $detection['repetition'];
                foreach ([
                    'detected' => $detection['detected'] ? 1 : 0,
                    'clean_test_run_id' => $detection['clean_test_run_id'],
                    'bug_test_run_id' => $detection['bug_test_run_id'],
                    'detecting_test_case_ids' => implode(';', array_map('strval', (array) $detection['detecting_test_case_ids'])),
                ] as $key => $value) {
                    $this->writeRow($handle, [...$prefix, 'detection', $number, $seeds[$number] ?? null, $detection['bug_code'], $detection['category'], $key, $value]);
                }
            }
            if (is_string($metrics['error'] ?? null)) {
                $this->writeRow($handle, [...$prefix, 'error', null, null, null, null, 'error', $metrics['error']]);
            }
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return RunReportExporter::UTF8_BOM.$csv;
    }

    /**
     * @param  resource  $handle
     * @param  list<mixed>  $cells
     */
    private function writeRow($handle, array $cells): void
    {
        $safe = array_map(function (mixed $cell): string {
            $text = match (true) {
                $cell === null => '',
                is_bool($cell) => $cell ? '1' : '0',
                is_float($cell) => rtrim(rtrim(sprintf('%.6F', $cell), '0'), '.'),
                is_scalar($cell) => (string) $cell,
                default => (string) json_encode($cell),
            };

            return RunReportExporter::sanitizeCsvCell(mb_scrub($text, 'UTF-8'));
        }, $cells);
        fputcsv($handle, $safe, ',', '"', '', "\r\n");
    }
}
