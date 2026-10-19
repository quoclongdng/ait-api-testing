<?php

namespace Database\Factories;

use App\Enums\LlmPurpose;
use App\Models\GenerationJob;
use App\Models\LlmCallLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LlmCallLog>
 */
class LlmCallLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'generation_job_id' => GenerationJob::factory(),
            'test_case_id' => null,
            'provider' => 'ollama',
            'model' => 'qwen2.5-coder:7b',
            'purpose' => LlmPurpose::Generate,
            'prompt' => [
                'messages' => [
                    ['role' => 'user', 'content' => 'Generate tests'],
                ],
            ],
            'response_raw' => '{"title": "test"}',
            'temperature' => 0.2,
            'seed' => 42,
            'prompt_tokens' => 120,
            'completion_tokens' => 85,
            'latency_ms' => 1200,
            'error' => null,
        ];
    }
}
