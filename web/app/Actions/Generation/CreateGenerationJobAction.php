<?php

namespace App\Actions\Generation;

use App\Enums\ApiSpecStatus;
use App\Enums\ErrorCode;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\QueueName;
use App\Exceptions\ApiException;
use App\Jobs\GenerateTestsJob;
use App\Models\GenerationJob;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateGenerationJobAction
{
    /**
     * Create a new GenerationJob in QUEUED state and dispatch GenerateTestsJob worker (§4.1, D10).
     *
     * @param  array{
     *     strategy: string|GenerationStrategy,
     *     endpoint_ids?: list<int>|null,
     *     techniques?: list<string>|null,
     *     model?: string|null,
     *     temperature?: float|null,
     *     seed?: int|null,
     *     max_repair_attempts?: int|null,
     *     options?: array<string, mixed>|null
     * }  $params
     */
    public function execute(User $user, TestSuite $suite, array $params): GenerationJob
    {
        $strategy = $params['strategy'] instanceof GenerationStrategy
            ? $params['strategy']
            : GenerationStrategy::from($params['strategy']);

        // RULE jobs are created by CreateRuleGenerationJobAction (S3-03); this action handles LLM/HYBRID only.
        if ($strategy === GenerationStrategy::Rule) {
            throw new ApiException(ErrorCode::ValidationFailed, 'Chiến lược RULE dùng luồng sinh test theo luật riêng.');
        }

        // ADR-0007: a server without an LLM refuses LLM/HYBRID before queueing anything.
        app(ResolveLlmAvailabilityAction::class)->assertStrategyAvailable($strategy);

        // A spec that is not PARSED cannot be used for generation (§4.5).
        if ($suite->apiSpec->status !== ApiSpecStatus::Parsed) {
            throw new ApiException(ErrorCode::ValidationFailed, 'Chỉ có thể sinh test từ đặc tả đã phân tích thành công.');
        }

        return DB::transaction(function () use ($user, $suite, $strategy, $params): GenerationJob {
            $job = GenerationJob::create([
                'project_id' => $suite->project_id,
                'api_spec_id' => $suite->api_spec_id,
                'test_suite_id' => $suite->id,
                'strategy' => $strategy,
                'status' => GenerationJobStatus::Queued,
                // `strategy` is a column, not part of the GenerationParams contract.
                'params' => array_diff_key($params, ['strategy' => true]),
                'stats' => null,
                'created_by' => $user->id,
            ]);

            // Long LLM work goes to its own queue so RULE jobs and runs never wait behind it (S14-06).
            GenerateTestsJob::dispatch($job->id)->onQueue(QueueName::Llm)->afterCommit();

            return $job;
        });
    }
}
