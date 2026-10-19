<?php

namespace App\Actions\Generation;

use App\Contracts\AiGeneratorClient;
use App\Data\Ai\AiGenerateRequest;
use App\Data\Ai\AiGenerateResponse;
use App\Enums\ErrorCode;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Exceptions\ApiException;
use App\Models\BusinessRule;
use App\Models\Endpoint;
use App\Models\GenerationJob;
use App\Services\Generation\HybridSeedCaseProvider;
use App\Services\Support\GenerationJobError;
use Illuminate\Support\Carbon;
use Throwable;

class ExecuteAiGenerationAction
{
    public function __construct(
        protected AiGeneratorClient $aiClient,
        protected RecordLlmCallsAction $recordLlmCalls,
        protected ?TransitionGenerationJobAction $transition = null,
        protected ?HybridSeedCaseProvider $seedCaseProvider = null,
    ) {}

    /**
     * Execute full end-to-end AI generation including web validation, dedup, and persistence.
     *
     * @throws ApiException
     */
    public function executeEndToEnd(GenerationJob $job, ?AiGenerateRequest $request = null, ?ProcessGeneratedTestCasesAction $processAction = null): GenerationJob
    {
        $response = $this->execute($job, $request);
        $processAction ??= app(ProcessGeneratedTestCasesAction::class);
        $processAction->execute($job, $response->testCases, $response->llmCalls);

        return $job->refresh();
    }

    /**
     * Execute AI test generation for a GenerationJob.
     * Transitions state and saves LLM call logs.
     * When ai-service is down or request build fails, sets job status to FAILED with error code and finished_at.
     *
     * @throws ApiException
     */
    public function execute(GenerationJob $job, ?AiGenerateRequest $request = null): AiGenerateResponse
    {
        // 2. Transition state: QUEUED -> RUNNING
        $job = $this->transition()->execute($job, GenerationJobStatus::Running, ['started_at' => Carbon::now()]);
        $response = null;
        $unlinkedRecorded = false;

        try {
            // 3. Build request inside try block to ensure errors do not leave job stuck in RUNNING
            $request ??= $this->buildRequest($job);

            // S11-02: never start an LLM run the web would abandon mid-way (D09).
            $this->assertTimeoutCoversGenerateBudget();

            // 4. Call ai-service
            $response = $this->aiClient->generate($request);

            // 5. Save llm_call_logs. REPAIR calls tied to one case (case_index) are saved by
            // ProcessGeneratedTestCasesAction with that case's test_case_id (M10, S13-03): the
            // table is INSERT-only, so the link cannot be added after the case is created.
            $this->recordLlmCalls->execute($job, $response->unlinkedLlmCalls());
            $unlinkedRecorded = true;

            // 6. Update stats
            $job->update([
                'stats' => $response->stats->toArray(),
            ]);

            return $response;
        } catch (Throwable $e) {
            // Persist valid LLM audit logs if available before failure handling
            if ($e instanceof ApiException && ! empty($e->llmCalls)) {
                $this->recordLlmCalls->execute($job, $e->llmCalls);
            }
            // No case will be persisted: every call of a response is still logged (D09).
            if ($response instanceof AiGenerateResponse) {
                $this->recordLlmCalls->execute($job, $unlinkedRecorded ? $response->linkedLlmCalls() : $response->llmCalls);
            }

            // 7. Handle failure: transition RUNNING -> FAILED with finished_at and error code
            $errorCode = ($e instanceof ApiException && $e->errorCode !== ErrorCode::InternalError)
                ? $e->errorCode->value
                : ErrorCode::AiServiceUnavailable->value;

            if ($e instanceof \InvalidArgumentException) {
                $errorCode = ErrorCode::ValidationFailed->value;
            }

            // Keep the user-facing cause next to the code (S10-01): M11/M15 show it instead of a
            // generic text, e.g. the AI_SERVICE_TIMEOUT guard or an ai-service 422 prompt-size error.
            $this->transition()->execute($job, GenerationJobStatus::Failed, [
                'error' => GenerationJobError::compose($errorCode, $e instanceof ApiException ? $e->getMessage() : null),
                'finished_at' => Carbon::now(),
            ]);

            if ($e instanceof ApiException) {
                throw $e;
            }

            if ($e instanceof \InvalidArgumentException) {
                throw new ApiException(
                    ErrorCode::ValidationFailed,
                    $e->getMessage(),
                    ['job_id' => $job->id]
                );
            }

            throw new ApiException(
                ErrorCode::AiServiceUnavailable,
                'AI service không khả dụng: '.$e->getMessage(),
                ['job_id' => $job->id]
            );
        }
    }

    /**
     * Build standard AiGenerateRequest from GenerationJob models and parameters.
     */
    public function buildRequest(GenerationJob $job): AiGenerateRequest
    {
        $params = $job->params;

        /** @var list<int>|null $selectedEndpointIds */
        $selectedEndpointIds = null;
        if (isset($params['endpoint_ids']) && is_array($params['endpoint_ids'])) {
            $selectedEndpointIds = array_values(array_filter(
                array_map('intval', $params['endpoint_ids']),
                fn (int $id): bool => $id > 0
            ));
        }

        $targetEndpointIds = $job->getTargetEndpointIds();

        if ($selectedEndpointIds !== null && $selectedEndpointIds !== [] && $targetEndpointIds === []) {
            throw new \InvalidArgumentException('Không tìm thấy endpoint nào hợp lệ từ danh sách endpoint_ids đã chọn.');
        }

        // Deterministic order (by id) so prompts and experiment repetitions are reproducible (D09, S11-02).
        $endpointsQuery = $job->apiSpec->endpoints->whereIn('id', $targetEndpointIds)->sortBy('id');

        $endpoints = $endpointsQuery->map(fn (Endpoint $ep): array => [
            'id' => $ep->id,
            'method' => $ep->method->value,
            'path' => $ep->path,
            'operation_id' => $ep->operation_id,
            'summary' => $ep->summary,
            'description' => $ep->description,
            'parameters' => $ep->getPreservedParameters(),
            'request_body' => $ep->getPreservedRequestBody(),
            'responses' => $ep->getPreservedResponses(),
            'security' => $ep->security !== null ? array_values($ep->security) : null,
        ])->values()->all();

        if ($selectedEndpointIds !== null && $selectedEndpointIds !== [] && $endpoints === []) {
            throw new \InvalidArgumentException('Không tìm thấy endpoint nào hợp lệ từ danh sách endpoint_ids đã chọn.');
        }

        /** @var list<array<string, mixed>> $endpointsList */
        $endpointsList = array_values($endpoints);
        if ($endpointsList === []) {
            throw new \InvalidArgumentException('API spec has no endpoints to generate tests for.');
        }

        if (isset($params['business_rules']) && is_array($params['business_rules'])) {
            // ADR-0006: experiment jobs carry the business rules snapshotted when the experiment was
            // created (method/path from ground-truth.yaml), not the shared project's live rules.
            $businessRules = $this->snapshotRules($params['business_rules'], $job, $selectedEndpointIds);
        } else {
            $rulesQuery = $job->project->businessRules;
            if ($selectedEndpointIds !== null && $selectedEndpointIds !== []) {
                $rulesQuery = $rulesQuery->filter(fn (BusinessRule $br): bool => $br->endpoint_id === null || in_array($br->endpoint_id, $selectedEndpointIds, true));
            }

            $businessRules = $rulesQuery->map(fn (BusinessRule $br): array => [
                'code' => $br->code,
                'description' => $br->description,
                'endpoint_id' => $br->endpoint_id,
            ])->values()->all();
        }

        /** @var list<array<string, mixed>> $businessRulesList */
        $businessRulesList = array_values($businessRules);

        // HYBRID: the RULE cases of the target endpoints are the seed for the LLM (§10.2). Seeds always
        // come from HybridSeedCaseProvider, never from params: the `array` cast of
        // generation_jobs.params would turn every `{}` of a seed DSL into `[]` (S13-03).
        /** @var list<array<string, mixed>> $seedCases */
        $seedCases = $job->strategy === GenerationStrategy::Hybrid
            ? ($this->seedCaseProvider ?? app(HybridSeedCaseProvider::class))->forJob($job, $targetEndpointIds)
            : [];

        $rawOptions = isset($params['options']) && is_array($params['options'])
            ? $params['options']
            : $params;

        $allowedOptionKeys = [
            'max_cases_per_endpoint',
            'techniques',
            'model',
            'temperature',
            'seed',
            'max_repair_attempts',
        ];

        /** @var array<string, mixed> $options */
        $options = array_intersect_key($rawOptions, array_flip($allowedOptionKeys));

        return new AiGenerateRequest(
            jobId: $job->id,
            strategy: $job->strategy,
            endpoints: $endpointsList,
            businessRules: $businessRulesList,
            seedCases: $seedCases,
            options: $options,
            auth: $job->authContext()?->toPublicArray(),
        );
    }

    /**
     * Maps snapshot rules (`{code, description, method, path}`) to the job spec's endpoint ids, with
     * the same endpoint filter as project rules. A rule whose operation is not in the spec is kept
     * as a global rule so no snapshotted text is silently dropped.
     *
     * @param  array<mixed>  $rules
     * @param  list<int>|null  $selectedEndpointIds
     * @return list<array{code: string, description: string, endpoint_id: int|null}>
     */
    private function snapshotRules(array $rules, GenerationJob $job, ?array $selectedEndpointIds): array
    {
        $endpointIds = [];
        foreach ($job->apiSpec->endpoints as $endpoint) {
            $endpointIds[$endpoint->method->value.' '.$endpoint->path] = $endpoint->id;
        }

        $result = [];
        foreach ($rules as $rule) {
            if (! is_array($rule) || ! is_string($rule['code'] ?? null) || ! is_string($rule['description'] ?? null)) {
                continue;
            }
            $key = is_string($rule['method'] ?? null) && is_string($rule['path'] ?? null)
                ? strtoupper($rule['method']).' '.$rule['path']
                : null;
            $endpointId = $key !== null ? ($endpointIds[$key] ?? null) : null;
            if ($endpointId !== null && $selectedEndpointIds !== null && $selectedEndpointIds !== []
                && ! in_array($endpointId, $selectedEndpointIds, true)) {
                continue;
            }
            $result[] = [
                'code' => mb_substr($rule['code'], 0, 50),
                'description' => mb_substr($rule['description'], 0, 5000),
                'endpoint_id' => $endpointId,
            ];
        }

        return $result;
    }

    /**
     * The web must wait longer than ai-service's AI_GENERATE_BUDGET_SECONDS (+60 s). Otherwise it
     * gives up with a ConnectionException while ai-service keeps calling the LLM for the rest of
     * its budget, and none of those GENERATE/REPAIR calls reach llm_call_logs (D09). This happens
     * on stacks whose web/.env predates S11-02 (AI_SERVICE_TIMEOUT=120 vs the 1320 s budget):
     * refuse before any LLM call. An ai-service that does not report its budget is not checked.
     *
     * @throws ApiException
     */
    private function assertTimeoutCoversGenerateBudget(): void
    {
        $status = $this->aiClient->status();

        // ADR-0007 AI_LLM_PROVIDER=disabled: a job queued before the switch (or by an experiment) fails
        // with the same Vietnamese reason M11 shows, without calling ai-service /v1/generate.
        $disabledReason = (new ResolveLlmAvailabilityAction($this->aiClient))->execute($status);
        if ($disabledReason !== null) {
            throw new ApiException(ErrorCode::AiServiceUnavailable, $disabledReason);
        }

        $required = $status->requiredWebTimeoutSeconds();
        $timeout = (int) config('ait.ai_service.timeout_seconds');

        if ($required === null || $timeout >= $required) {
            return;
        }

        throw new ApiException(
            ErrorCode::AiServiceUnavailable,
            "Cấu hình thời gian không khớp: AI_SERVICE_TIMEOUT của web ({$timeout} s) phải ≥ "
            ."AI_GENERATE_BUDGET_SECONDS của ai-service + 60 ({$required} s). Hãy đặt "
            ."AI_SERVICE_TIMEOUT={$required} trong web/.env (hoặc giảm AI_GENERATE_BUDGET_SECONDS) rồi khởi động lại web và worker.",
            [
                'ai_service_timeout' => $timeout,
                'required_ai_service_timeout' => $required,
            ]
        );
    }

    private function transition(): TransitionGenerationJobAction
    {
        return $this->transition ?? app(TransitionGenerationJobAction::class);
    }
}
