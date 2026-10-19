<?php

namespace Database\Factories;

use App\Models\Benchmark;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Benchmark>
 */
class BenchmarkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(),
            'name' => sprintf('%s Benchmark', fake()->word()),
            'description' => fake()->sentence(),
            'base_url' => 'http://benchmark:8002',
            'version' => 'v1.0',
        ];
    }
}
