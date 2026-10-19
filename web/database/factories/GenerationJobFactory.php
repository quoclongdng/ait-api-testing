<?php

namespace Database\Factories;

use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Models\ApiSpec;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GenerationJob>
 */
class GenerationJobFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'api_spec_id' => fn (array $attributes) => ApiSpec::factory()->create([
                'project_id' => $attributes['project_id'],
            ])->id,
            'test_suite_id' => fn (array $attributes) => TestSuite::factory()->create([
                'project_id' => $attributes['project_id'],
                'api_spec_id' => $attributes['api_spec_id'],
            ])->id,
            'strategy' => GenerationStrategy::Rule,
            'status' => GenerationJobStatus::Queued,
            'params' => ['techniques' => ['HAPPY_PATH']],
            'stats' => null,
            'error' => null,
            'started_at' => null,
            'finished_at' => null,
            'created_by' => User::factory(),
        ];
    }
}
