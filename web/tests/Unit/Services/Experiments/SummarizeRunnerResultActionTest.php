<?php

namespace Tests\Unit\Services\Experiments;

use App\Actions\Experiments\SummarizeRunnerResultAction;
use App\Exceptions\ApiException;
use App\Services\Experiments\ExperimentMetricsCalculator;
use PHPUnit\Framework\TestCase;

class SummarizeRunnerResultActionTest extends TestCase
{
    public function test_recomputes_metrics_from_runner_repetitions(): void
    {
        // Shape of benchmark/runner ExperimentResult.model_dump() (S9-01).
        $json = (string) json_encode([
            'id' => 'abc',
            'name' => 'exp-run',
            'benchmark' => 'simple-store',
            'strategy' => 'WITNESS',
            'status' => 'COMPLETED',
            'repetitions_data' => [
                ['repetition' => 1, 'seed' => 42, 'total_cases' => 10, 'clean_pass_count' => 8, 'clean_fail_count' => 2, 'clean_error_count' => 0, 'bugs_total' => 10, 'bugs_detected' => 6, 'detections' => []],
                ['repetition' => 2, 'seed' => 1024, 'total_cases' => 10, 'clean_pass_count' => 6, 'clean_fail_count' => 2, 'clean_error_count' => 2, 'bugs_total' => 10, 'bugs_detected' => 8, 'detections' => []],
            ],
        ]);

        $summary = (new SummarizeRunnerResultAction(new ExperimentMetricsCalculator))->execute($json);

        $this->assertSame('simple-store', $summary['benchmark']);
        $this->assertSame(2, $summary['repetitions']);
        $this->assertSame(0.9, $summary['metrics']['executable_rate']);
        $this->assertSame(0.225, $summary['metrics']['false_positive_rate']);
        $this->assertSame(0.7, $summary['metrics']['bug_detection_rate']);
        $this->assertSame(0.7, $summary['metrics']['mutation_score']);
        $this->assertSame(0.1414, $summary['stddev']['bug_detection_rate']);
    }

    /**
     * S10-01: the same raw runner file must give the same mean/stddev here and in
     * benchmark/runner (test_audit_r1_metrics.py reads this fixture too).
     */
    public function test_matches_runner_metrics_on_shared_crosscheck_fixture(): void
    {
        $path = dirname(__DIR__, 5).'/benchmark/runner/tests/fixtures/metrics_crosscheck.json';
        $json = (string) file_get_contents($path);
        /** @var array{expected: array{metrics: array<string, float|int>, stddev: array<string, float|int>}} $data */
        $data = json_decode($json, true);

        $summary = (new SummarizeRunnerResultAction(new ExperimentMetricsCalculator))->execute($json);

        foreach ($data['expected']['metrics'] as $key => $value) {
            $this->assertSame((float) $value, $summary['metrics'][$key], $key);
        }
        foreach ($data['expected']['stddev'] as $key => $value) {
            $this->assertSame((float) $value, $summary['stddev'][$key], $key);
        }
    }

    public function test_rejects_files_without_repetitions(): void
    {
        $this->expectException(ApiException::class);
        (new SummarizeRunnerResultAction(new ExperimentMetricsCalculator))->execute('{"name": "x"}');
    }
}
