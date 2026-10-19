<?php

namespace App\Actions\Generation;

use App\Data\Ai\AiGeneratedTestCase;
use App\Data\Ai\AiLlmCall;
use App\Enums\ErrorCode;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\TestCaseSource;
use App\Enums\TestCaseStatus;
use App\Enums\TestTechnique;
use App\Exceptions\ApiException;
use App\Models\Endpoint;
use App\Models\GenerationJob;
use App\Models\TestCase;
use App\Models\TestSuite;
use App\Services\Dsl\DslValidator;
use App\Services\Support\GenerationJobError;
use App\Services\TestEngine\TestDeduplicationService;
use App\Services\TestEngine\TestFingerprintService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessGeneratedTestCasesAction
{
    /** Error of an INVALID case when neither the web nor ai-service gave a reason. */
    public const AI_GAVE_UP_MESSAGE = 'Dịch vụ AI không sửa được test case này sau các lần tự sửa (INVALID).';

    public function __construct(
        protected DslValidator $dslValidator,
        protected TestFingerprintService $fingerprintService,
        protected TransitionGenerationJobAction $transition,
        protected RecordLlmCallsAction $recordLlmCalls,
        protected TestDeduplicationService $deduplicator,
    ) {}

    /**
     * Validate at Web (D04), deduplicate, and persist generated test cases.
     * Updates GenerationJob stats and transitions status to SUCCEEDED, PARTIAL, or FAILED (§4.1).
     *
     * REPAIR calls carrying a case_index are logged with the test_case_id of that case (M10 shows a
     * case's own repairs, S13-03); calls without one were already logged by ExecuteAiGenerationAction
     * and are ignored here.
     *
     * @param  list<AiGeneratedTestCase>  $generatedCases
     * @param  list<AiLlmCall>  $llmCalls
     * @return Collection<int, TestCase>
     */
    public function execute(GenerationJob $job, array $generatedCases, array $llmCalls = []): Collection
    {
        $job->loadMissing(['apiSpec.endpoints', 'testSuite']);
        // Spec (id) order: the fallback endpoint of an unmatched case must not depend on heap order (S13-03).
        $endpoints = $job->apiSpec->endpoints->sortBy('id')->values();
        $targetEndpointIds = $job->getTargetEndpointIds();

        /** @var array<int, list<AiLlmCall>> $repairCalls */
        $repairCalls = [];
        foreach ($llmCalls as $call) {
            if ($call->caseIndex !== null) {
                $repairCalls[$call->caseIndex][] = $call;
            }
        }

        try {
            return DB::transaction(fn (): Collection => $this->persist($job, $endpoints, $targetEndpointIds, $generatedCases, $repairCalls));
        } catch (Throwable $e) {
            // The transaction rolled the linked logs back with the cases: keep every call (D09),
            // without hiding the original error if logging fails too (e.g. database down).
            try {
                $this->recordLlmCalls->execute($job, array_merge(...array_values($repairCalls)));
            } catch (Throwable $logError) {
                Log::error('Could not log REPAIR calls of a failed generation job', [
                    'generation_job_id' => $job->id,
                    'exception' => $logError::class,
                ]);
            }

            throw $e;
        }
    }

    /**
     * @param  Collection<int, Endpoint>  $endpoints
     * @param  list<int>  $targetEndpointIds
     * @param  list<AiGeneratedTestCase>  $generatedCases
     * @param  array<int, list<AiLlmCall>>  $repairCalls  REPAIR calls keyed by case index
     * @return Collection<int, TestCase>
     */
    protected function persist(GenerationJob $job, Collection $endpoints, array $targetEndpointIds, array $generatedCases, array $repairCalls = []): Collection
    {
        // §4.1: only a RUNNING job may receive cases and a terminal status. Lock the row so a job
        // already moved to FAILED (or finished by another worker) is never overwritten.
        $locked = GenerationJob::query()->lockForUpdate()->whereKey($job->getKey())->first();
        if (! $locked instanceof GenerationJob || $locked->status !== GenerationJobStatus::Running) {
            throw new ApiException(
                ErrorCode::InvalidStateTransition,
                'Không thể lưu test case cho GenerationJob không ở trạng thái RUNNING.',
                ['generation_job_id' => $job->id, 'status' => $locked?->status->value]
            );
        }

        // Serialize dedup per suite (same lock as ExecuteRuleGenerationAction) so concurrent
        // RULE/LLM/HYBRID jobs cannot persist two VALID cases with one fingerprint (§10.5).
        TestSuite::query()->lockForUpdate()->whereKey($job->test_suite_id)->first();

        /** @var Collection<int, TestCase> $createdCases */
        $createdCases = new Collection;
        /** @var list<string> $seenFingerprints */
        $seenFingerprints = [];
        // §10.5: only the suite's VALID/APPROVED cases count as existing, never a stale DUPLICATE,
        // INVALID or ARCHIVED row (same rule as RULE jobs and ai-service).
        $existingFingerprints = $this->deduplicator->existingFingerprints($job->test_suite_id);

        $generatedCount = count($generatedCases);
        $validCount = 0;
        $invalidCount = 0;
        $duplicateCount = 0;
        $repairedCount = 0;

        $source = match ($job->strategy) {
            GenerationStrategy::Llm => TestCaseSource::Llm,
            GenerationStrategy::Hybrid => TestCaseSource::Hybrid,
            default => TestCaseSource::Rule,
        };

        foreach ($generatedCases as $caseIndex => $genCase) {
            $dsl = $genCase->dsl;

            // 1. Web validation (D04 chốt chặn cuối): JSON Schema + OpenAPI semantic validation
            $valResult = $this->dslValidator->validate($dsl, $job->apiSpec, $job->contextVariables());

            /** @var array<string, mixed> $dslArray */
            $dslArray = $valResult->dsl ?? $this->toAssocArray($dsl);

            // Extract endpoint matching criteria from DSL
            $dslEndpoint = $dslArray['endpoint'] ?? null;
            $dslMethod = is_array($dslEndpoint) ? ($dslEndpoint['method'] ?? null) : null;
            $dslPath = is_array($dslEndpoint) ? ($dslEndpoint['path'] ?? null) : null;

            $matchedEndpoint = $endpoints->first(function (Endpoint $ep) use ($dslMethod, $dslPath): bool {
                return is_string($dslMethod)
                    && strcasecmp($ep->method->value, $dslMethod) === 0
                    && $ep->path === $dslPath;
            });
            $endpoint = $matchedEndpoint
                ?? $endpoints->first(fn (Endpoint $ep): bool => in_array($ep->id, $targetEndpointIds, true))
                ?? $endpoints->first();
            if ($endpoint === null) {
                $this->recordLlmCalls->execute($job, $repairCalls[$caseIndex] ?? []);
                unset($repairCalls[$caseIndex]);

                continue;
            }

            // Extract technique
            $techniqueStr = is_string($dslArray['technique'] ?? null) ? $dslArray['technique'] : 'HAPPY_PATH';
            $technique = TestTechnique::tryFrom($techniqueStr) ?? TestTechnique::HappyPath;

            // Extract title metadata safely within varchar(255) column limit
            $rawTitle = $dslArray['title'] ?? null;
            $title = (is_string($rawTitle) && trim($rawTitle) !== '') ? trim($rawTitle) : 'Generated Test Case';
            $safeTitle = mb_substr($title, 0, 255, 'UTF-8');

            // Determine status, fingerprint, errors
            $fingerprint = null;
            $validationErrors = null;

            // ai-service đã kết luận INVALID (hết lượt repair) thì web không nâng cấp lên VALID.
            $aiRejected = strtoupper($genCase->status) === TestCaseStatus::Invalid->value;

            if ($valResult->isValid && ! $aiRejected) {
                // 2. Canonical fingerprint (RFC 8785)
                $fingerprint = $this->fingerprintService->calculate($dsl);

                // 3. Deduplication check in current batch and test suite
                $isDuplicate = in_array($fingerprint, $seenFingerprints, true)
                    || isset($existingFingerprints[$fingerprint]);

                if ($isDuplicate) {
                    $status = TestCaseStatus::Duplicate;
                    $duplicateCount++;
                } else {
                    $status = TestCaseStatus::Valid;
                    $validCount++;
                    $seenFingerprints[] = $fingerprint;
                }
            } else {
                $status = TestCaseStatus::Invalid;
                $invalidCount++;
                $fingerprint = null; // NULL khi case INVALID chưa thể chuẩn hóa (docs/DESIGN.md D05 / db-schema.md)
                $validationErrors = self::invalidCaseErrors($valResult->errors, $aiRejected ? $genCase->validationErrors : []);
            }

            // Chỉ đếm repair thành công (thống nhất với ai-service): case vẫn INVALID không phải "đã tự sửa".
            if ($genCase->repairAttempts > 0 && $status !== TestCaseStatus::Invalid) {
                $repairedCount++;
            }

            $testCase = TestCase::create([
                'test_suite_id' => $job->test_suite_id,
                'endpoint_id' => $endpoint->id,
                'generation_job_id' => $job->id,
                'source' => $source,
                'technique' => $technique,
                'title' => $safeTitle,
                'dsl' => $dsl,
                'dsl_version' => '1.0',
                'fingerprint' => $fingerprint,
                'status' => $status,
                'validation_errors' => $validationErrors,
                'repair_attempts' => $genCase->repairAttempts,
            ]);
            $this->recordLlmCalls->execute($job, $repairCalls[$caseIndex] ?? [], $testCase->id);
            unset($repairCalls[$caseIndex]);

            $createdCases->push($testCase);
        }

        // case_index outside test_cases (should not happen): still logged, without a link.
        $this->recordLlmCalls->execute($job, array_merge(...array_values($repairCalls)));

        $stats = [
            'generated' => $generatedCount,
            'valid' => $validCount,
            'invalid' => $invalidCount,
            'duplicates' => $duplicateCount,
            'repaired' => $repairedCount,
        ];

        // 4. Determine terminal job status (§4.1). An endpoint "failed" when it has no VALID case and
        // either produced nothing or an INVALID case; an endpoint whose cases are all DUPLICATE was
        // processed without error (it only found nothing new).
        $failedEndpointIds = [];
        foreach ($targetEndpointIds as $endpointId) {
            $endpointCases = $createdCases->where('endpoint_id', $endpointId);
            $hasValid = $endpointCases->contains(fn (TestCase $case): bool => $case->status === TestCaseStatus::Valid);
            $hasInvalid = $endpointCases->contains(fn (TestCase $case): bool => $case->status === TestCaseStatus::Invalid);
            if (! $hasValid && ($endpointCases->isEmpty() || $hasInvalid)) {
                $failedEndpointIds[] = $endpointId;
            }
        }

        $now = Carbon::now();
        if ($validCount > 0 || ($duplicateCount > 0 && $invalidCount === 0 && $failedEndpointIds === [])) {
            // SUCCEEDED also covers a job whose cases were all DUPLICATE of cases already in the
            // suite: nothing failed, the job just found nothing new (M11 says so).
            $finalStatus = $validCount > 0 && count($targetEndpointIds) > 1 && $failedEndpointIds !== []
                ? GenerationJobStatus::Partial
                : GenerationJobStatus::Succeeded;

            $this->transition->execute($locked, $finalStatus, [
                'stats' => $stats,
                'finished_at' => $now,
                'error' => null,
            ]);
        } else {
            // §4.1: 0 case VALID and something failed -> RUNNING -> FAILED, with the actual cause.
            $this->transition->execute($locked, GenerationJobStatus::Failed, [
                'stats' => $stats,
                'finished_at' => $now,
                'error' => $this->failureError($invalidCount, $duplicateCount, count($failedEndpointIds)),
            ]);
        }

        return $createdCases;
    }

    /**
     * validation_errors of an INVALID case. The web check is the final one (D04): when it found
     * problems they are the case's errors. ai-service words the same problem differently ("Tham số
     * truy vấn ..." next to "Tham số query ..."), so its errors are only used when the web found
     * none (e.g. a case ai-service gave up on after the repair budget).
     *
     * @param  array<mixed>  $webErrors
     * @param  array<mixed>  $aiErrors  errors of a case ai-service rejected, [] otherwise
     * @return non-empty-list<string>
     */
    public static function invalidCaseErrors(array $webErrors, array $aiErrors): array
    {
        $webErrors = array_values(array_filter($webErrors, 'is_string'));
        $errors = array_values(array_unique($webErrors !== [] ? $webErrors : array_values(array_filter($aiErrors, 'is_string'))));

        return $errors !== [] ? $errors : [self::AI_GAVE_UP_MESSAGE];
    }

    /**
     * generation_jobs.error of a job that ended with 0 VALID case: DSL_INVALID only when cases were
     * rejected by the DSL validation, otherwise the plain reason (as a RULE job stores it).
     */
    private function failureError(int $invalidCount, int $duplicateCount, int $failedEndpoints): string
    {
        if ($invalidCount > 0 && $duplicateCount === 0) {
            return ErrorCode::DslInvalid->value;
        }

        if ($invalidCount > 0) {
            return GenerationJobError::compose(
                ErrorCode::DslInvalid->value,
                "Không có test case mới hợp lệ: {$invalidCount} test case không qua được kiểm tra DSL, "
                ."{$duplicateCount} test case trùng với test case đã có trong suite.",
            );
        }

        if ($duplicateCount > 0) {
            return "Không có test case mới: {$duplicateCount} test case trùng với test case đã có trong suite "
                ."và {$failedEndpoints} endpoint không sinh được test case nào.";
        }

        return 'Không sinh được test case nào cho các endpoint đã chọn.';
    }

    /**
     * Convert a decoded DSL (array or nested stdClass) into a plain associative array.
     *
     * @return array<string, mixed>
     */
    protected function toAssocArray(mixed $dsl): array
    {
        try {
            $decoded = json_decode((string) json_encode($dsl, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        /** @var array<string, mixed> */
        return is_array($decoded) ? $decoded : [];
    }
}
