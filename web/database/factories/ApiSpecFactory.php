<?php

namespace Database\Factories;

use App\Enums\ApiSpecFormat;
use App\Enums\ApiSpecStatus;
use App\Models\ApiSpec;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiSpec>
 */
class ApiSpecFactory extends Factory
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
            'version_label' => 'v1.0.'.fake()->numberBetween(0, 99),
            'source_filename' => 'openapi.json',
            'format' => ApiSpecFormat::Json,
            'openapi_version' => '3.0.3',
            'raw_content' => '{"openapi": "3.0.3", "info": {"title": "Test", "version": "1.0.0"}, "paths": {}}',
            'normalized' => [
                'openapi' => '3.0.3',
                'info' => ['title' => 'Test', 'version' => '1.0.0'],
                'paths' => [],
            ],
            'checksum' => fake()->unique()->sha256(),
            'status' => ApiSpecStatus::Uploaded,
            'parse_errors' => null,
        ];
    }
}
