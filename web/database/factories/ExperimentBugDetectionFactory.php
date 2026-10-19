<?php

namespace Database\Factories;

use App\Enums\TestRunStatus;
use App\Enums\TestVerdict;
use App\Models\Benchmark;
use App\Models\BenchmarkBug;
use App\Models\Experiment;
use App\Models\ExperimentBugDetection;
use App\Models\TestCase;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\TestSuite;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExperimentBugDetection>
 */
class ExperimentBugDetectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'experiment_id' => Experiment::factory(),
            'benchmark_bug_id' => function (array $attributes) {
                $experiment = isset($attributes['experiment_id']) ? Experiment::find($attributes['experiment_id']) : null;
                $benchmarkId = ($experiment instanceof Experiment)
                    ? $experiment->benchmark_id
                    : Benchmark::factory()->create()->id;

                return BenchmarkBug::factory()->create([
                    'benchmark_id' => $benchmarkId,
                ])->id;
            },
            'repetition' => 1,
            'detected' => true,
            'clean_test_run_id' => function (array $attributes) {
                $suite = TestSuite::factory()->create();
                $testCase = TestCase::factory()->create(['test_suite_id' => $suite->id]);

                $cleanRun = TestRun::factory()->create([
                    'test_suite_id' => $suite->id,
                    'benchmark_variant' => 'clean',
                    'status' => TestRunStatus::Completed,
                    'started_at' => now()->subMinutes(2),
                    'finished_at' => now()->subMinute(),
                    'totals' => ['total' => 1, 'pass' => 1, 'fail' => 0, 'error' => 0, 'skipped' => 0],
                ]);

                TestResult::factory()->create([
                    'test_run_id' => $cleanRun->id,
                    'test_case_id' => $testCase->id,
                    'verdict' => TestVerdict::Pass,
                ]);

                return $cleanRun->id;
            },
            'bug_test_run_id' => function (array $attributes) {
                $cleanRun = isset($attributes['clean_test_run_id'])
                    ? TestRun::find($attributes['clean_test_run_id'])
                    : null;

                $suiteId = $cleanRun instanceof TestRun
                    ? $cleanRun->test_suite_id
                    : TestSuite::factory()->create()->id;

                $testCase = TestCase::where('test_suite_id', $suiteId)->first()
                    ?? TestCase::factory()->create(['test_suite_id' => $suiteId]);

                $bug = isset($attributes['benchmark_bug_id'])
                    ? BenchmarkBug::find($attributes['benchmark_bug_id'])
                    : null;
                $bugVariant = $bug instanceof BenchmarkBug ? $bug->code : 'bug_1';

                $detected = $attributes['detected'] ?? true;

                $bugRun = TestRun::factory()->create([
                    'test_suite_id' => $suiteId,
                    'benchmark_variant' => $bugVariant,
                    'status' => TestRunStatus::Completed,
                    'started_at' => now()->subMinute(),
                    'finished_at' => now(),
                    'totals' => $detected
                        ? ['total' => 1, 'pass' => 0, 'fail' => 1, 'error' => 0, 'skipped' => 0]
                        : ['total' => 1, 'pass' => 1, 'fail' => 0, 'error' => 0, 'skipped' => 0],
                ]);

                TestResult::factory()->create([
                    'test_run_id' => $bugRun->id,
                    'test_case_id' => $testCase->id,
                    'verdict' => $detected ? TestVerdict::Fail : TestVerdict::Pass,
                ]);

                return $bugRun->id;
            },
            'detecting_test_case_ids' => function (array $attributes) {
                $detected = $attributes['detected'] ?? true;
                if (! $detected) {
                    return [];
                }

                $cleanRun = isset($attributes['clean_test_run_id'])
                    ? TestRun::find($attributes['clean_test_run_id'])
                    : null;
                $suiteId = $cleanRun instanceof TestRun ? $cleanRun->test_suite_id : null;

                if ($suiteId !== null) {
                    $testCase = TestCase::where('test_suite_id', $suiteId)->first();
                    if ($testCase instanceof TestCase) {
                        return [$testCase->id];
                    }
                }

                return [1];
            },
        ];
    }

    /**
     * Indicate that the bug was not detected.
     */
    public function notDetected(): static
    {
        return $this->state(fn () => [
            'detected' => false,
        ]);
    }
}
