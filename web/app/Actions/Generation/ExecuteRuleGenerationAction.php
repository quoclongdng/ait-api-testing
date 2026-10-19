<?php

namespace App\Actions\Generation;

use App\Enums\ErrorCode;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\TestCaseSource;
use App\Enums\TestCaseStatus;
use App\Enums\TestTechnique;
use App\Exceptions\ApiException;
use App\Models\GenerationJob;
use App\Models\TestCase;
use App\Models\TestSuite;
use App\Services\Dsl\DslValidator;
use App\Services\Generation\RuleCaseSelector;
use App\Services\Generation\RuleGenerator;
use App\Services\Support\SafeErrorMessage;
use App\Services\TestEngine\TestDeduplicationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class ExecuteRuleGenerationAction
{
    private const PERSISTED_COLUMNS = [
        'test_suite_id',
        'endpoint_id',
        'generation_job_id',
        'source',
        'technique',
        'title',
        'dsl',
        'dsl_version',
        'fingerprint',
        'status',
        'validation_errors',
        'repair_attempts',
    ];

    public function __construct(
        protected RuleGenerator $generator,
        protected DslValidator $validator,
        protected TransitionGenerationJobAction $transition,
        protected TestDeduplicationService $deduplicator,
        protected RuleCaseSelector $selector,
    ) {}

    public function execute(int $generationJobId): GenerationJob
    {
        $job = DB::transaction(function () use ($generationJobId): GenerationJob {
            $job = GenerationJob::query()->lockForUpdate()->findOrFail($generationJobId);
            if ($job->strategy !== GenerationStrategy::Rule) {
                throw new ApiException(
                    ErrorCode::InvalidStateTransition,
                    'Chỉ job RULE được xử lý bởi Rule Generator.',
                    ['generation_job_id' => $job->id]
                );
            }
            $job = $this->transition->execute($job, GenerationJobStatus::Running, ['started_at' => Carbon::now()]);

            return $job;
        });

        try {
            return $this->generate($job);
        } catch (Throwable $exception) {
            $job->refresh();
            if ($job->status === GenerationJobStatus::Running) {
                $this->transition->execute($job, GenerationJobStatus::Failed, [
                    'error' => SafeErrorMessage::from(
                        $exception,
                        'Lỗi hệ thống khi sinh test case theo luật (INTERNAL_ERROR).',
                        ['generation_job_id' => $job->id],
                    ),
                    'finished_at' => Carbon::now(),
                ]);
            }

            return $job->refresh();
        }
    }

    private function generate(GenerationJob $job): GenerationJob
    {
        $job->load(['apiSpec.endpoints', 'testSuite']);
        $apiSpec = $job->apiSpec;
        $params = $job->params;
        $endpointIds = isset($params['endpoint_ids']) && is_array($params['endpoint_ids'])
            ? array_map('intval', $params['endpoint_ids'])
            : [];
        // Spec (id) order, not PostgreSQL heap order (the relation has no ORDER BY): test case ids, the suite list
        // and the run order must not depend on which endpoint rows were updated (S13-03).
        $endpoints = $apiSpec->endpoints->whereIn('id', $endpointIds)->sortBy('id')->values();

        if ($endpoints->isEmpty()) {
            throw new ApiException(ErrorCode::ValidationFailed, 'GenerationJob không có endpoint hợp lệ để sinh test.');
        }

        $rawTechniques = isset($params['techniques']) && is_array($params['techniques'])
            ? $params['techniques']
            : [];
        $techniques = array_values(array_filter(
            $rawTechniques,
            static fn (mixed $technique): bool => is_string($technique) || $technique instanceof TestTechnique
        ));
        $maxCasesPerEndpoint = isset($params['max_cases_per_endpoint'])
            ? max(1, (int) $params['max_cases_per_endpoint'])
            : 10;
        $normalizedSpec = $apiSpec->normalized;
        $componentsSchemas = is_array($normalizedSpec)
            && isset($normalizedSpec['components']['schemas'])
            && is_array($normalizedSpec['components']['schemas'])
            ? $normalizedSpec['components']['schemas']
            : [];
        // ADR-0006: experiment jobs carry the benchmark's role names (never tokens). RULE then tests
        // secured endpoints with the executor's default credential and adds AUTH cases.
        $auth = $job->authContext();
        $contextVariables = $job->contextVariables();
        $testCases = [];
        $errors = [];
        // Only VALID/APPROVED cases are "existing" for dedup (§10.5): a stale DUPLICATE row whose
        // original was edited or archived must not make the same content a duplicate again.
        $knownFingerprints = $this->deduplicator->existingFingerprints($job->test_suite_id);

        foreach ($endpoints as $endpoint) {
            $endpointKey = strtoupper($endpoint->method->value).':'.$endpoint->path;

            try {
                $cases = $this->generator->generate($endpoint, $techniques, $componentsSchemas, $auth);
                $endpointCases = [];

                foreach ($cases as $dsl) {
                    $validation = $this->validator->validate($dsl, $apiSpec, $contextVariables);
                    $dslEndpoint = $dsl['endpoint'] ?? [];
                    $key = is_array($dslEndpoint)
                        ? strtoupper((string) ($dslEndpoint['method'] ?? '')).':'.(string) ($dslEndpoint['path'] ?? '')
                        : '';
                    $validationErrors = $validation->errors;
                    if ($key !== $endpointKey) {
                        $validationErrors[] = "DSL endpoint '{$key}' không khớp endpoint đang sinh '{$endpointKey}'.";
                    }

                    $isValid = $validation->isValid && $key === $endpointKey;

                    $endpointCases[] = [
                        'test_suite_id' => $job->test_suite_id,
                        'endpoint_id' => $endpoint->id,
                        'generation_job_id' => $job->id,
                        'source' => TestCaseSource::Rule,
                        'technique' => TestTechnique::tryFrom((string) ($dsl['technique'] ?? '')) ?? TestTechnique::HappyPath,
                        'title' => (string) ($dsl['title'] ?? 'Generated test case'),
                        'dsl' => $dsl,
                        'dsl_version' => (string) ($dsl['dsl_version'] ?? '1.0'),
                        'fingerprint' => null,
                        'status' => $isValid ? TestCaseStatus::Valid : TestCaseStatus::Invalid,
                        'validation_errors' => $validationErrors,
                        'repair_attempts' => 0,
                    ];
                }

                // The cap applies after validation and dedup, spread across techniques and fields,
                // so truncation never drops a whole technique (see RuleCaseSelector).
                $endpointCases = $this->selector->select($endpointCases, $maxCasesPerEndpoint, $knownFingerprints);
                $endpointValidCount = 0;
                foreach ($endpointCases as $record) {
                    $endpointValidCount += $record['status'] === TestCaseStatus::Valid ? 1 : 0;
                    $testCases[] = $record;
                }

                // §4.1: an endpoint that yields no VALID case counts as an endpoint error (PARTIAL/FAILED).
                if ($cases === []) {
                    $errors[] = "Không sinh được test case cho {$endpointKey}.";
                } elseif ($endpointValidCount === 0) {
                    $errors[] = "Không có test case hợp lệ cho {$endpointKey}.";
                }
            } catch (Throwable $exception) {
                $errors[] = $endpointKey.': '.SafeErrorMessage::from(
                    $exception,
                    'Lỗi hệ thống khi sinh test case cho endpoint này.',
                    ['generation_job_id' => $job->id, 'endpoint' => $endpointKey],
                );
            }
        }

        return DB::transaction(function () use ($job, $testCases, $errors): GenerationJob {
            // Serialize dedup per suite so concurrent jobs cannot persist the same fingerprint twice (§10.5).
            TestSuite::query()->lockForUpdate()->whereKey($job->test_suite_id)->first();

            $deduplicated = $this->deduplicator->deduplicateBatch($testCases, $job->test_suite_id);

            $validCount = 0;
            $invalidCount = 0;
            $duplicateCount = 0;
            foreach ($deduplicated->processed as $record) {
                $persisted = array_intersect_key($record, array_flip(self::PERSISTED_COLUMNS));
                TestCase::create($persisted);

                match ($record['status'] ?? null) {
                    TestCaseStatus::Valid => $validCount++,
                    TestCaseStatus::Duplicate => $duplicateCount++,
                    default => $invalidCount++,
                };
            }

            $stats = [
                'generated' => count($deduplicated->processed),
                'valid' => $validCount,
                'invalid' => $invalidCount,
                'repaired' => 0,
                'duplicates' => $duplicateCount,
            ];

            $finishedAt = Carbon::now();
            if ($validCount === 0 && $duplicateCount > 0 && $invalidCount === 0 && $errors === []) {
                // Every case duplicates one already in the suite and nothing failed: the job
                // succeeded without finding anything new (§4.1, M11 says so).
                $this->transition->execute($job, GenerationJobStatus::Succeeded, [
                    'stats' => $stats,
                    'error' => null,
                    'finished_at' => $finishedAt,
                ]);
            } elseif ($validCount === 0) {
                $this->transition->execute($job, GenerationJobStatus::Failed, [
                    'stats' => $stats,
                    'error' => $errors[0] ?? ($duplicateCount > 0 && $invalidCount > 0
                        ? "Không có test case mới hợp lệ: {$invalidCount} test case không hợp lệ, {$duplicateCount} test case trùng với test case đã có trong suite."
                        : 'Không sinh được test case hợp lệ.'),
                    'finished_at' => $finishedAt,
                ]);
            } elseif ($errors !== []) {
                $this->transition->execute($job, GenerationJobStatus::Partial, [
                    'stats' => $stats,
                    'error' => implode("\n", $errors),
                    'finished_at' => $finishedAt,
                ]);
            } else {
                $this->transition->execute($job, GenerationJobStatus::Succeeded, [
                    'stats' => $stats,
                    'error' => null,
                    'finished_at' => $finishedAt,
                ]);
            }

            return $job->refresh();
        });
    }
}
