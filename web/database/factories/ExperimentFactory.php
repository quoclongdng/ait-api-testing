<?php

namespace Database\Factories;

use App\Enums\ExperimentStatus;
use App\Enums\GenerationStrategy;
use App\Models\Benchmark;
use App\Models\Experiment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Experiment>
 */
class ExperimentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => sprintf('%s Experiment', fake()->word()),
            'benchmark_id' => Benchmark::factory(),
            'strategy' => GenerationStrategy::Rule,
            'config' => [
                'model' => 'qwen2.5-coder:7b',
                'seed' => 42,
                'temperature' => 0.2,
                'repetitions' => 3,
            ],
            'status' => ExperimentStatus::Planned,
            'metrics' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }
}
