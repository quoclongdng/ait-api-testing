<?php

namespace Tests\Feature\Generation;

use App\Actions\Generation\RecordLlmCallsAction;
use App\Data\Ai\AiLlmCall;
use App\Enums\LlmPurpose;
use App\Models\GenerationJob;
use App\Models\LlmCallLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordLlmCallsTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') === 'pgsql') {
            $host = (string) config('database.connections.pgsql.host');
            $port = (int) config('database.connections.pgsql.port');
            $fp = @fsockopen($host, $port, $errno, $errstr, 0.1);
            if (! $fp) {
                config(['database.default' => 'sqlite']);
                config(['database.connections.sqlite.database' => ':memory:']);
            } else {
                fclose($fp);
            }
        }
    }

    public function test_record_llm_calls_persists_in_database(): void
    {
        $job = GenerationJob::factory()->create();

        $call1 = new AiLlmCall(
            purpose: LlmPurpose::Generate,
            provider: 'ollama',
            model: 'qwen2.5-coder:7b',
            prompt: [
                ['role' => 'system', 'content' => 'System prompt'],
                ['role' => 'user', 'content' => 'User query'],
            ],
            responseRaw: '{"dsl": 1}',
            temperature: 0.2,
            seed: 42,
            promptTokens: 150,
            completionTokens: 80,
            latencyMs: 1200,
            error: null,
        );

        $call2 = new AiLlmCall(
            purpose: LlmPurpose::Repair,
            provider: 'ollama',
            model: 'qwen2.5-coder:7b',
            prompt: [
                ['role' => 'user', 'content' => 'Repair DSL'],
            ],
            responseRaw: '{"dsl": 2}',
            temperature: 0.1,
            seed: null,
            promptTokens: 200,
            completionTokens: 90,
            latencyMs: 800,
            error: 'Minor warning',
        );

        $action = new RecordLlmCallsAction;
        $created = $action->execute($job, [$call1, $call2]);

        $this->assertCount(2, $created);
        $this->assertDatabaseCount('llm_call_logs', 2);

        $this->assertDatabaseHas('llm_call_logs', [
            'generation_job_id' => $job->id,
            'provider' => 'ollama',
            'model' => 'qwen2.5-coder:7b',
            'purpose' => 'GENERATE',
            'prompt_tokens' => 150,
            'completion_tokens' => 80,
            'latency_ms' => 1200,
            'error' => null,
        ]);

        $this->assertDatabaseHas('llm_call_logs', [
            'generation_job_id' => $job->id,
            'provider' => 'ollama',
            'purpose' => 'REPAIR',
            'prompt_tokens' => 200,
            'latency_ms' => 800,
            'error' => 'Minor warning',
        ]);

        /** @var LlmCallLog $first */
        $first = $created->first();
        $this->assertSame(LlmPurpose::Generate, $first->purpose);
        /** @var list<array{role: string, content: string}> $prompt */
        $prompt = $first->prompt;
        $this->assertSame('System prompt', $prompt[0]['content']);
    }
}
