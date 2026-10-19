<?php

namespace Database\Factories;

use App\Models\ApiSpec;
use App\Models\Project;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TestSuite>
 */
class TestSuiteFactory extends Factory
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
            'name' => sprintf('%s Suite', fake()->word()),
            'description' => fake()->sentence(),
            'created_by' => User::factory(),
        ];
    }
}
