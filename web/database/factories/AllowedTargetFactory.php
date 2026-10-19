<?php

namespace Database\Factories;

use App\Models\AllowedTarget;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AllowedTarget>
 */
class AllowedTargetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'host' => fake()->unique()->domainName(),
            'port' => fake()->randomElement([80, 443, 8000, 8080, null]),
            'note' => fake()->sentence(),
            'created_by' => User::factory()->admin(),
        ];
    }
}
