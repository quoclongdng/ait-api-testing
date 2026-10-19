<?php

namespace Database\Factories;

use App\Enums\HttpMethod;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Endpoint>
 */
class EndpointFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'api_spec_id' => ApiSpec::factory(),
            'method' => fake()->randomElement(HttpMethod::cases()),
            'path' => '/'.fake()->unique()->slug(),
            'operation_id' => fake()->word().'Op',
            'summary' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'parameters' => [],
            'request_body' => null,
            'responses' => [
                '200' => ['description' => 'OK'],
            ],
            'security' => null,
        ];
    }
}
