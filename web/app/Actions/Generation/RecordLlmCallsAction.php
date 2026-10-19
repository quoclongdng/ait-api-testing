<?php

namespace App\Actions\Generation;

use App\Data\Ai\AiLlmCall;
use App\Models\GenerationJob;
use App\Models\LlmCallLog;
use Illuminate\Support\Collection;

class RecordLlmCallsAction
{
    /**
     * Record LLM call logs in the database.
     *
     * @param  list<AiLlmCall>|array<int, mixed>  $llmCalls
     * @return Collection<int, LlmCallLog>
     */
    public function execute(GenerationJob|int $job, array $llmCalls, ?int $testCaseId = null): Collection
    {
        $jobId = $job instanceof GenerationJob ? $job->id : $job;
        /** @var Collection<int, LlmCallLog> $created */
        $created = collect();

        foreach ($llmCalls as $call) {
            $data = $call instanceof AiLlmCall ? $call->toArray() : (array) $call;

            $log = LlmCallLog::create([
                'generation_job_id' => $jobId,
                'test_case_id' => $testCaseId,
                'provider' => (string) ($data['provider'] ?? 'unknown'),
                'model' => (string) ($data['model'] ?? 'unknown'),
                'purpose' => $data['purpose'] ?? 'GENERATE',
                'prompt' => $data['prompt'] ?? [],
                'response_raw' => (string) ($data['response_raw'] ?? ''),
                'temperature' => (float) ($data['temperature'] ?? 0.2),
                'seed' => isset($data['seed']) ? (int) $data['seed'] : null,
                'prompt_tokens' => isset($data['prompt_tokens']) ? (int) $data['prompt_tokens'] : null,
                'completion_tokens' => isset($data['completion_tokens']) ? (int) $data['completion_tokens'] : null,
                'latency_ms' => (int) ($data['latency_ms'] ?? 0),
                'error' => isset($data['error']) ? (string) $data['error'] : null,
            ]);

            $created->push($log);
        }

        return $created;
    }
}
