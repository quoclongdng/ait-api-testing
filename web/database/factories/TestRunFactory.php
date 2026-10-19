<?php

namespace Database\Factories;

use App\Enums\TestRunStatus;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TestRun>
 */
class TestRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'test_suite_id' => TestSuite::factory(),
            'target_base_url' => 'http://localhost:8000',
            'benchmark_variant' => 'clean',
            'status' => TestRunStatus::Queued,
            'totals' => null,
            'triggered_by' => User::factory(),
            'started_at' => null,
            'finished_at' => null,
        ];
    }
}
