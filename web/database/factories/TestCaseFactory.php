<?php

namespace Database\Factories;

use App\Enums\TestCaseSource;
use App\Enums\TestCaseStatus;
use App\Enums\TestTechnique;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\TestCase;
use App\Models\TestSuite;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TestCase>
 */
class TestCaseFactory extends Factory
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
            'endpoint_id' => function (array $attributes) {
                $suite = TestSuite::find($attributes['test_suite_id']);
                $apiSpecId = ($suite instanceof TestSuite)
                    ? $suite->api_spec_id
                    : ApiSpec::factory()->create()->id;

                return Endpoint::factory()->create([
                    'api_spec_id' => $apiSpecId,
                ])->id;
            },
            'generation_job_id' => function (array $attributes) {
                if (($attributes['source'] ?? TestCaseSource::Rule) === TestCaseSource::Manual) {
                    return null;
                }
                $suite = TestSuite::find($attributes['test_suite_id']);

                return GenerationJob::factory()->create([
                    'project_id' => ($suite instanceof TestSuite) ? $suite->project_id : Project::factory()->create()->id,
                    'api_spec_id' => ($suite instanceof TestSuite) ? $suite->api_spec_id : ApiSpec::factory()->create()->id,
                    'test_suite_id' => $attributes['test_suite_id'],
                ])->id;
            },
            'source' => TestCaseSource::Rule,
            'technique' => TestTechnique::HappyPath,
            'title' => fake()->sentence(),
            'dsl' => function (array $attributes) {
                $endpoint = Endpoint::find($attributes['endpoint_id']);
                $method = ($endpoint instanceof Endpoint) ? $endpoint->method->value : 'GET';
                $path = ($endpoint instanceof Endpoint) ? $endpoint->path : '/items/1';
                $title = $attributes['title'] ?? 'Get item';

                return [
                    'dsl_version' => '1.0',
                    'title' => $title,
                    'endpoint' => [
                        'method' => $method,
                        'path' => $path,
                    ],
                    'technique' => 'HAPPY_PATH',
                    'source' => 'RULE',
                    'steps' => [
                        [
                            'name' => 'Get item',
                            'request' => [
                                'method' => $method,
                                'path' => $path,
                            ],
                            'assertions' => [
                                ['type' => 'status', 'equals' => 200],
                            ],
                        ],
                    ],
                ];
            },
            'dsl_version' => '1.0',
            'fingerprint' => fake()->unique()->sha256(),
            'status' => TestCaseStatus::Valid,
            'validation_errors' => null,
            'repair_attempts' => 0,
        ];
    }

    /**
     * Indicate that the test case is created manually without a generation job.
     */
    public function manual(): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => TestCaseSource::Manual,
            'generation_job_id' => null,
            'dsl' => is_array($attributes['dsl'] ?? null)
                ? array_merge($attributes['dsl'], ['source' => 'MANUAL'])
                : $attributes['dsl'] ?? null,
        ]);
    }

    /**
     * Indicate that the test case is invalid and has null fingerprint.
     */
    public function invalid(): static
    {
        return $this->state(fn () => [
            'status' => TestCaseStatus::Invalid,
            'fingerprint' => null,
            'validation_errors' => ['Lỗi cú pháp DSL: thiếu steps.'],
        ]);
    }
}
