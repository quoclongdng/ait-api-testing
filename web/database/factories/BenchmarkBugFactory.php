<?php

namespace Database\Factories;

use App\Enums\BenchmarkBugCategory;
use App\Enums\HttpMethod;
use App\Models\Benchmark;
use App\Models\BenchmarkBug;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BenchmarkBug>
 */
class BenchmarkBugFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'benchmark_id' => Benchmark::factory(),
            'code' => 'BUG-'.fake()->unique()->numerify('###'),
            'method' => HttpMethod::Get,
            'path' => '/items',
            'category' => BenchmarkBugCategory::ValidationMissing,
            'description' => fake()->sentence(),
            'toggle_flag' => 'BUG_ENABLE_'.fake()->unique()->numerify('###'),
        ];
    }
}
