<?php

namespace Database\Factories;

use App\Models\BusinessRule;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessRule>
 */
class BusinessRuleFactory extends Factory
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
            'endpoint_id' => null,
            'code' => 'BR-'.fake()->unique()->numerify('###'),
            'description' => fake()->sentence(),
        ];
    }
}
