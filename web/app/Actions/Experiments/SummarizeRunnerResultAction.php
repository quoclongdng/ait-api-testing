<?php

namespace App\Actions\Experiments;

use App\Data\Experiments\RepetitionObservation;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Services\Experiments\ExperimentMetricsCalculator;

/**
 * Recomputes §11 metrics from a raw result file of the Python experiment runner (S9-01,
 * `benchmark/results/raw/*.json`) with the same formulas as the web.
 *
 * The runner validates and de-duplicates cases before reporting, executes in-process and does not
 * create test runs, so only the metrics its output supports are computed (Executable Rate, False
 * Positive Rate, Bug Detection Rate, Mutation Score). Nothing is persisted: experiment_bug_detections
 * require real test runs (db-schema.md §16).
 */
class SummarizeRunnerResultAction
{
    public const METRICS = ['executable_rate', 'false_positive_rate', 'bug_detection_rate', 'mutation_score'];

    public function __construct(private readonly ExperimentMetricsCalculator $calculator) {}

    /**
     * @return array{name: string, benchmark: string, strategy: string, status: string, repetitions: int, metrics: array<string, float>, stddev: array<string, float>}
     *
     * @throws ApiException
     */
    public function execute(string $json): array
    {
        $data = json_decode($json, true);
        if (! is_array($data) || ! is_array($data['repetitions_data'] ?? null)) {
            throw new ApiException(ErrorCode::ValidationFailed, 'Tệp kết quả runner không hợp lệ: thiếu repetitions_data.');
        }

        $observations = [];
        foreach (array_values($data['repetitions_data']) as $index => $rep) {
            if (! is_array($rep)) {
                throw new ApiException(ErrorCode::ValidationFailed, "Lần lặp thứ {$index} không hợp lệ.");
            }
            $total = $this->int($rep, 'total_cases');
            $bugsTotal = $this->int($rep, 'bugs_total');
            $bugsDetected = $this->int($rep, 'bugs_detected');
            $observations[] = new RepetitionObservation(
                repetition: $this->int($rep, 'repetition', $index + 1),
                seed: isset($rep['seed']) && is_numeric($rep['seed']) ? (int) $rep['seed'] : null,
                generated: $total,
                valid: $total,
                cleanPass: $this->int($rep, 'clean_pass_count'),
                cleanFail: $this->int($rep, 'clean_fail_count'),
                cleanError: $this->int($rep, 'clean_error_count'),
                totalBugs: $bugsTotal,
                detectedBugs: $bugsDetected,
                totalMutants: $bugsTotal,
                killedMutants: $bugsDetected,
            );
        }

        $aggregate = $this->calculator->aggregate($observations);
        /** @var array<string, float> $stddev */
        $stddev = $aggregate['stddev'];

        $metrics = [];
        $deviations = [];
        foreach (self::METRICS as $key) {
            $metrics[$key] = (float) $aggregate[$key];
            $deviations[$key] = (float) ($stddev[$key] ?? 0.0);
        }

        return [
            'name' => is_string($data['name'] ?? null) ? $data['name'] : '—',
            'benchmark' => is_string($data['benchmark'] ?? null) ? $data['benchmark'] : '—',
            'strategy' => is_string($data['strategy'] ?? null) ? $data['strategy'] : '—',
            'status' => is_string($data['status'] ?? null) ? $data['status'] : '—',
            'repetitions' => count($observations),
            'metrics' => $metrics,
            'stddev' => $deviations,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private function int(array $row, string $key, int $default = 0): int
    {
        return isset($row[$key]) && is_numeric($row[$key]) ? max(0, (int) $row[$key]) : $default;
    }
}
