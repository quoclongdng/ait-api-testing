<?php

namespace App\Actions\Generation;

use App\Data\Generation\GenerationJobInput;
use App\Enums\ErrorCode;
use App\Enums\GenerationStrategy;
use App\Exceptions\ApiException;
use App\Models\GenerationJob;
use App\Models\TestSuite;
use App\Models\User;

/**
 * M11 entry point: creates a QUEUED GenerationJob for RULE, LLM or HYBRID (§4.1, D10).
 *
 * RULE goes through CreateRuleGenerationJobAction (S3-03); LLM/HYBRID through
 * CreateGenerationJobAction (S6-03/S7-01). Params are normalised to the GenerationParams
 * contract so the same values are stored on the job and forwarded to ai-service.
 */
class StartGenerationJobAction
{
    public function __construct(
        protected CreateRuleGenerationJobAction $createRuleJob,
        protected CreateGenerationJobAction $createAiJob,
    ) {}

    public function execute(User $user, TestSuite $suite, GenerationJobInput $input): GenerationJob
    {
        $endpointIds = $this->resolveEndpointIds($suite, $input->endpointIds);
        $params = $input->toParams($endpointIds);

        if ($input->strategy === GenerationStrategy::Rule) {
            return $this->createRuleJob->execute($suite, $user, [
                'strategy' => GenerationStrategy::Rule->value,
                'params' => $params,
            ]);
        }

        return $this->createAiJob->execute($user, $suite, ['strategy' => $input->strategy] + $params);
    }

    /**
     * Empty selection = every endpoint of the suite's spec; otherwise each id must belong to that spec.
     *
     * @param  list<int>  $requested
     * @return list<int>
     */
    private function resolveEndpointIds(TestSuite $suite, array $requested): array
    {
        $specEndpointIds = $suite->apiSpec->endpoints()->orderBy('id')->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($requested === []) {
            return array_values($specEndpointIds);
        }

        $foreign = array_diff($requested, $specEndpointIds);
        if ($foreign !== []) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'Danh sách endpoint phải thuộc đặc tả đang gắn với test suite.',
                ['params.endpoint_ids' => ['Endpoint không thuộc đặc tả của test suite.']]
            );
        }

        return $requested;
    }
}
