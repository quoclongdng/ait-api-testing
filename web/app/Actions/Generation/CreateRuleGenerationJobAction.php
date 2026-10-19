<?php

namespace App\Actions\Generation;

use App\Enums\ApiSpecStatus;
use App\Enums\ErrorCode;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\QueueName;
use App\Exceptions\ApiException;
use App\Jobs\Generation\GenerateRuleTestCases;
use App\Models\GenerationJob;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateRuleGenerationJobAction
{
    /**
     * @param  array{strategy: string, params: array<string, mixed>}  $input
     */
    public function execute(TestSuite $suite, User $creator, array $input): GenerationJob
    {
        if ($input['strategy'] !== GenerationStrategy::Rule->value) {
            throw new ApiException(ErrorCode::ValidationFailed, 'Hiện chỉ hỗ trợ tạo job sinh test theo RULE.');
        }

        $apiSpec = $suite->apiSpec;
        if ($apiSpec->status !== ApiSpecStatus::Parsed) {
            throw new ApiException(ErrorCode::ValidationFailed, 'Chỉ có thể sinh test từ đặc tả đã phân tích thành công.');
        }

        $params = $input['params'];
        $endpointIds = array_values(array_map('intval', $params['endpoint_ids']));
        $validEndpointIds = $apiSpec->endpoints()
            ->whereIn('id', $endpointIds)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if (count($endpointIds) !== count($validEndpointIds)) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'Danh sách endpoint phải thuộc đặc tả đang gắn với test suite.',
                ['params.endpoint_ids' => ['Endpoint không thuộc đặc tả của test suite.']]
            );
        }

        $params['endpoint_ids'] = $endpointIds;
        $params['techniques'] = array_values($params['techniques'] ?? []);
        $params['max_cases_per_endpoint'] = (int) ($params['max_cases_per_endpoint'] ?? 10);

        return DB::transaction(function () use ($suite, $creator, $params): GenerationJob {
            $job = GenerationJob::create([
                'project_id' => $suite->project_id,
                'api_spec_id' => $suite->api_spec_id,
                'test_suite_id' => $suite->id,
                'strategy' => GenerationStrategy::Rule,
                'status' => GenerationJobStatus::Queued,
                'params' => $params,
                'created_by' => $creator->id,
            ]);

            GenerateRuleTestCases::dispatch($job->id)->onQueue(QueueName::Default)->afterCommit();

            return $job;
        });
    }
}
