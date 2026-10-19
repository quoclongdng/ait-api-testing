<?php

namespace Database\Factories;

use App\Enums\TestVerdict;
use App\Models\TestCase;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\TestSuite;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TestResult>
 */
class TestResultFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'test_run_id' => TestRun::factory(),
            'test_case_id' => function (array $attributes) {
                $run = TestRun::find($attributes['test_run_id']);
                $suiteId = ($run instanceof TestRun)
                    ? $run->test_suite_id
                    : TestSuite::factory()->create()->id;

                return TestCase::factory()->create([
                    'test_suite_id' => $suiteId,
                ])->id;
            },
            'verdict' => TestVerdict::Pass,
            'duration_ms' => 150,
            'request_log' => [
                'method' => 'GET',
                'url' => 'http://localhost:8000/items/1',
                'headers' => [],
                'body' => null,
            ],
            'response_log' => [
                'status' => 200,
                'headers' => ['content-type' => ['application/json']],
                'body' => '{"id": 1}',
            ],
            'assertion_results' => [
                ['type' => 'status', 'expected' => 200, 'actual' => 200, 'passed' => true],
            ],
            'error_message' => null,
        ];
    }
}
