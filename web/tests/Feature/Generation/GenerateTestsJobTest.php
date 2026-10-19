<?php

namespace Tests\Feature\Generation;

use App\Actions\Generation\CreateGenerationJobAction;
use App\Actions\Generation\ExecuteAiGenerationAction;
use App\Actions\Generation\ProcessGeneratedTestCasesAction;
use App\Actions\Generation\RecordLlmCallsAction;
use App\Contracts\AiGeneratorClient;
use App\Data\Ai\AiGeneratedTestCase;
use App\Data\Ai\AiGenerateRequest;
use App\Data\Ai\AiGenerateResponse;
use App\Data\Ai\AiGenerationStats;
use App\Data\Ai\AiLlmCall;
use App\Data\AiServiceStatus;
use App\Enums\ApiSpecStatus;
use App\Enums\ErrorCode;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\HttpMethod;
use App\Enums\LlmPurpose;
use App\Enums\TestCaseSource;
use App\Enums\TestCaseStatus;
use App\Enums\TestTechnique;
use App\Exceptions\ApiException;
use App\Jobs\GenerateTestsJob;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Models\GenerationJob;
use App\Models\TestCase as TestCaseModel;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\Ai\FakeAiGeneratorClient;
use App\Services\Support\GenerationJobError;
use App\Services\TestEngine\TestFingerprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GenerateTestsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_end_to_end_llm_generation_job_succeeds_and_saves_validated_cases(): void
    {
        $spec = ApiSpec::factory()->create();
        $endpoint = Endpoint::factory()->create([
            'api_spec_id' => $spec->id,
            'method' => HttpMethod::Get,
            'path' => '/items',
            'responses' => [
                '200' => [
                    'description' => 'OK',
                    'content' => ['application/json' => ['schema' => ['type' => 'object']]],
                ],
            ],
        ]);

        $suite = TestSuite::factory()->create([
            'project_id' => $spec->project_id,
            'api_spec_id' => $spec->id,
        ]);

        $job = GenerationJob::factory()->create([
            'project_id' => $spec->project_id,
            'api_spec_id' => $spec->id,
            'test_suite_id' => $suite->id,
            'strategy' => GenerationStrategy::Llm,
            'status' => GenerationJobStatus::Queued,
            'params' => [
                'endpoint_ids' => [$endpoint->id],
                'options' => ['temperature' => 0.2],
            ],
        ]);

        $validDsl = [
            'dsl_version' => '1.0',
            'title' => 'Get items happy path',
            'endpoint' => [
                'method' => 'GET',
                'path' => '/items',
            ],
            'technique' => 'HAPPY_PATH',
            'source' => 'LLM',
            'steps' => [
                [
                    'name' => 'Call get items',
                    'request' => [
                        'method' => 'GET',
                        'path' => '/items',
                    ],
                    'assertions' => [
                        ['type' => 'status', 'equals' => 200],
                    ],
                ],
            ],
        ];

        $mockClient = new class($validDsl) implements AiGeneratorClient
        {
            /** @param array<string, mixed> $dsl */
            public function __construct(private array $dsl) {}

            public function status(): AiServiceStatus
            {
                return new AiServiceStatus(reachable: true, provider: 'fake');
            }

            public function generate(AiGenerateRequest $request): AiGenerateResponse
            {
                return new AiGenerateResponse(
                    testCases: [
                        new AiGeneratedTestCase(
                            dsl: $this->dsl,
                            status: 'VALID',
                            validationErrors: [],
                            repairAttempts: 0,
                            fingerprint: null,
                        ),
                    ],
                    llmCalls: [
                        new AiLlmCall(
                            purpose: LlmPurpose::Generate,
                            provider: 'fake',
                            model: 'test-model',
                            prompt: [['role' => 'user', 'content' => 'Generate']],
                            responseRaw: (string) json_encode(['status' => 'ok']),
                            temperature: 0.2,
                            seed: 42,
                            promptTokens: 100,
                            completionTokens: 50,
                            latencyMs: 120,
                        ),
                    ],
                    stats: new AiGenerationStats(
                        generated: 1,
                        valid: 1,
                        invalid: 0,
                        repaired: 0,
                        duplicates: 0,
                    ),
                );
            }
        };

        $this->app->instance(AiGeneratorClient::class, $mockClient);

        // Execute worker job
        $workerJob = new GenerateTestsJob($job->id);
        $this->app->call([$workerJob, 'handle']);

        // Assert job transitioned QUEUED -> RUNNING -> SUCCEEDED (§4.1)
        $job->refresh();
        $this->assertSame(GenerationJobStatus::Succeeded, $job->status);
        $this->assertNotNull($job->started_at);
        $this->assertNotNull($job->finished_at);
        $this->assertNull($job->error);

        // Assert stats
        $this->assertIsArray($job->stats);
        $this->assertSame(1, $job->stats['generated']);
        $this->assertSame(1, $job->stats['valid']);
        $this->assertSame(0, $job->stats['invalid']);
        $this->assertSame(0, $job->stats['duplicates']);

        // Assert test case persisted in database
        $this->assertDatabaseCount('test_cases', 1);
        $case = TestCaseModel::firstOrFail();
        $this->assertSame($suite->id, $case->test_suite_id);
        $this->assertSame($endpoint->id, $case->endpoint_id);
        $this->assertSame($job->id, $case->generation_job_id);
        $this->assertSame(TestCaseSource::Llm, $case->source);
        $this->assertSame(TestTechnique::HappyPath, $case->technique);
        $this->assertSame('Get items happy path', $case->title);
        $this->assertSame(TestCaseStatus::Valid, $case->status);
        $this->assertNotNull($case->fingerprint);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $case->fingerprint);
        $this->assertNull($case->validation_errors);

        // Assert LLM call log recorded
        $this->assertDatabaseCount('llm_call_logs', 1);
        $this->assertDatabaseHas('llm_call_logs', [
            'generation_job_id' => $job->id,
            'provider' => 'fake',
            'model' => 'test-model',
        ]);
    }

    public function test_web_validation_catches_invalid_case_and_marks_invalid_and_fails_job_if_zero_valid(): void
    {
        $spec = ApiSpec::factory()->create();
        $endpoint = Endpoint::factory()->create([
            'api_spec_id' => $spec->id,
            'method' => HttpMethod::Get,
            'path' => '/items',
        ]);

        $suite = TestSuite::factory()->create([
            'project_id' => $spec->project_id,
            'api_spec_id' => $spec->id,
        ]);

        $job = GenerationJob::factory()->create([
            'project_id' => $spec->project_id,
            'api_spec_id' => $spec->id,
            'test_suite_id' => $suite->id,
            'strategy' => GenerationStrategy::Llm,
            'status' => GenerationJobStatus::Queued,
        ]);

        // DSL targeting an undeclared endpoint path '/unknown' (violates semantic validation with spec D04)
        $invalidDsl = [
            'dsl_version' => '1.0',
            'title' => 'Invalid endpoint test',
            'endpoint' => [
                'method' => 'GET',
                'path' => '/unknown',
            ],
            'technique' => 'HAPPY_PATH',
            'source' => 'LLM',
            'steps' => [
                [
                    'name' => 'Call unknown',
                    'request' => [
                        'method' => 'GET',
                        'path' => '/unknown',
                    ],
                    'assertions' => [
                        ['type' => 'status', 'equals' => 200],
                    ],
                ],
            ],
        ];

        $mockClient = new class($invalidDsl) implements AiGeneratorClient
        {
            /** @param array<string, mixed> $dsl */
            public function __construct(private array $dsl) {}

            public function status(): AiServiceStatus
            {
                return new AiServiceStatus(reachable: true, provider: 'fake');
            }

            public function generate(AiGenerateRequest $request): AiGenerateResponse
            {
                // ai-service falsely thought this was VALID, but web validator must catch it (D04)
                return new AiGenerateResponse(
                    testCases: [
                        new AiGeneratedTestCase(
                            dsl: $this->dsl,
                            status: 'VALID',
                            validationErrors: [],
                            repairAttempts: 1,
                            fingerprint: null,
                        ),
                    ],
                    llmCalls: [],
                    stats: new AiGenerationStats(1, 1, 0, 1, 0),
                );
            }
        };

        $this->app->instance(AiGeneratorClient::class, $mockClient);

        $workerJob = new GenerateTestsJob($job->id);
        $this->app->call([$workerJob, 'handle']);

        $job->refresh();
        // Since 0 cases are valid, job must be FAILED per §4.1
        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertSame(ErrorCode::DslInvalid, GenerationJobError::code($job->error));
        $this->assertIsArray($job->stats);
        $this->assertSame(0, $job->stats['valid']);
        $this->assertSame(1, $job->stats['invalid']);

        // Test case is saved as INVALID with errors and null fingerprint (D04, D05)
        $this->assertDatabaseCount('test_cases', 1);
        $case = TestCaseModel::firstOrFail();
        $this->assertSame(TestCaseStatus::Invalid, $case->status);
        $this->assertNull($case->fingerprint);
        $this->assertIsArray($case->validation_errors);
        $this->assertNotEmpty($case->validation_errors);
        $this->assertSame(1, $case->repair_attempts);
    }

    public function test_deduplication_marks_duplicate_and_updates_stats(): void
    {
        $spec = ApiSpec::factory()->create();
        $endpoint = Endpoint::factory()->create([
            'api_spec_id' => $spec->id,
            'method' => HttpMethod::Get,
            'path' => '/items',
            'responses' => ['200' => ['description' => 'OK']],
        ]);

        $suite = TestSuite::factory()->create([
            'project_id' => $spec->project_id,
            'api_spec_id' => $spec->id,
        ]);

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Duplicate case test',
            'endpoint' => ['method' => 'GET', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'LLM',
            'steps' => [
                [
                    'name' => 'Call',
                    'request' => ['method' => 'GET', 'path' => '/items'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        // Pre-create an existing test case in the same suite with identical semantic content
        $fingerprintService = $this->app->make(TestFingerprintService::class);
        $existingFingerprint = $fingerprintService->calculate($dsl);

        TestCaseModel::create([
            'test_suite_id' => $suite->id,
            'endpoint_id' => $endpoint->id,
            'source' => TestCaseSource::Rule,
            'technique' => TestTechnique::HappyPath,
            'title' => 'Pre-existing case',
            'dsl' => $dsl,
            'dsl_version' => '1.0',
            'fingerprint' => $existingFingerprint,
            'status' => TestCaseStatus::Valid,
        ]);

        // Job generates two cases: one identical to pre-existing, and one new
        $newDsl = $dsl;
        $newDsl['title'] = 'New different case';
        $newDsl['steps'][0]['name'] = 'Call items with header';
        $newDsl['steps'][0]['request']['headers'] = ['X-Page' => '2'];

        $job = GenerationJob::factory()->create([
            'project_id' => $spec->project_id,
            'api_spec_id' => $spec->id,
            'test_suite_id' => $suite->id,
            'strategy' => GenerationStrategy::Llm,
            'status' => GenerationJobStatus::Queued,
        ]);

        $mockClient = new class([$dsl, $newDsl]) implements AiGeneratorClient
        {
            /** @param list<array<string, mixed>> $cases */
            public function __construct(private array $cases) {}

            public function status(): AiServiceStatus
            {
                return new AiServiceStatus(reachable: true, provider: 'fake');
            }

            public function generate(AiGenerateRequest $request): AiGenerateResponse
            {
                return new AiGenerateResponse(
                    testCases: array_map(fn ($c) => new AiGeneratedTestCase(
                        dsl: $c,
                        status: 'VALID',
                        validationErrors: [],
                        repairAttempts: 0,
                    ), $this->cases),
                    llmCalls: [],
                    stats: new AiGenerationStats(2, 2, 0, 0, 0),
                );
            }
        };

        $this->app->instance(AiGeneratorClient::class, $mockClient);

        $workerJob = new GenerateTestsJob($job->id);
        $this->app->call([$workerJob, 'handle']);

        $job->refresh();
        $this->assertSame(GenerationJobStatus::Succeeded, $job->status);
        $this->assertIsArray($job->stats);
        $this->assertSame(2, $job->stats['generated']);
        $this->assertSame(1, $job->stats['valid']);
        $this->assertSame(1, $job->stats['duplicates']);

        // Verify total test cases in DB: 1 pre-existing + 2 newly created (1 duplicate, 1 valid)
        $this->assertDatabaseCount('test_cases', 3);
        $this->assertSame(1, TestCaseModel::where('generation_job_id', $job->id)->where('status', TestCaseStatus::Duplicate)->count());
        $this->assertSame(1, TestCaseModel::where('generation_job_id', $job->id)->where('status', TestCaseStatus::Valid)->count());
    }

    public function test_partial_job_status_when_some_endpoints_have_no_valid_cases(): void
    {
        $spec = ApiSpec::factory()->create();
        $endpoint1 = Endpoint::factory()->create([
            'api_spec_id' => $spec->id,
            'method' => HttpMethod::Get,
            'path' => '/endpoint-one',
            'responses' => ['200' => ['description' => 'OK']],
        ]);
        $endpoint2 = Endpoint::factory()->create([
            'api_spec_id' => $spec->id,
            'method' => HttpMethod::Post,
            'path' => '/endpoint-two',
            'responses' => ['201' => ['description' => 'Created']],
        ]);

        $suite = TestSuite::factory()->create([
            'project_id' => $spec->project_id,
            'api_spec_id' => $spec->id,
        ]);

        $job = GenerationJob::factory()->create([
            'project_id' => $spec->project_id,
            'api_spec_id' => $spec->id,
            'test_suite_id' => $suite->id,
            'strategy' => GenerationStrategy::Llm,
            'status' => GenerationJobStatus::Queued,
            'params' => [
                'endpoint_ids' => [$endpoint1->id, $endpoint2->id],
            ],
        ]);

        // Generator only produces valid case for endpoint1, nothing for endpoint2
        $dsl1 = [
            'dsl_version' => '1.0',
            'title' => 'Endpoint 1 case',
            'endpoint' => ['method' => 'GET', 'path' => '/endpoint-one'],
            'technique' => 'HAPPY_PATH',
            'source' => 'LLM',
            'steps' => [
                [
                    'name' => 'Step 1',
                    'request' => ['method' => 'GET', 'path' => '/endpoint-one'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $mockClient = new class([$dsl1]) implements AiGeneratorClient
        {
            /** @param list<array<string, mixed>> $cases */
            public function __construct(private array $cases) {}

            public function status(): AiServiceStatus
            {
                return new AiServiceStatus(reachable: true, provider: 'fake');
            }

            public function generate(AiGenerateRequest $request): AiGenerateResponse
            {
                return new AiGenerateResponse(
                    testCases: [new AiGeneratedTestCase($this->cases[0], 'VALID')],
                    llmCalls: [],
                    stats: new AiGenerationStats(1, 1, 0, 0, 0),
                );
            }
        };

        $this->app->instance(AiGeneratorClient::class, $mockClient);

        $workerJob = new GenerateTestsJob($job->id);
        $this->app->call([$workerJob, 'handle']);

        $job->refresh();
        // Since endpoint2 had no valid cases while endpoint1 succeeded -> PARTIAL (§4.1)
        $this->assertSame(GenerationJobStatus::Partial, $job->status);
        $this->assertIsArray($job->stats);
        $this->assertSame(1, $job->stats['valid']);
    }

    public function test_ai_service_down_transitions_job_to_failed(): void
    {
        $spec = ApiSpec::factory()->create();
        Endpoint::factory()->create(['api_spec_id' => $spec->id]);
        $suite = TestSuite::factory()->create(['api_spec_id' => $spec->id]);

        $job = GenerationJob::factory()->create([
            'project_id' => $spec->project_id,
            'api_spec_id' => $spec->id,
            'test_suite_id' => $suite->id,
            'strategy' => GenerationStrategy::Llm,
            'status' => GenerationJobStatus::Queued,
        ]);

        $this->app->instance(AiGeneratorClient::class, new FakeAiGeneratorClient(reachable: false));

        $workerJob = new GenerateTestsJob($job->id);
        $this->app->call([$workerJob, 'handle']);

        $job->refresh();
        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertSame(ErrorCode::AiServiceUnavailable, GenerationJobError::code($job->error));
        $this->assertNotNull($job->finished_at);
        $this->assertDatabaseCount('test_cases', 0);
    }

    public function test_create_generation_job_action_dispatches_worker_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $spec = ApiSpec::factory()->create(['status' => ApiSpecStatus::Parsed]);
        $suite = TestSuite::factory()->create(['api_spec_id' => $spec->id]);

        $action = new CreateGenerationJobAction;
        $job = $action->execute($user, $suite, [
            'strategy' => 'LLM',
            'options' => ['temperature' => 0.4],
        ]);

        $this->assertSame(GenerationJobStatus::Queued, $job->status);
        $this->assertSame(GenerationStrategy::Llm, $job->strategy);
        $this->assertSame($user->id, $job->created_by);

        Queue::assertPushed(GenerateTestsJob::class, function (GenerateTestsJob $queueJob) use ($job) {
            return $queueJob->generationJobId === $job->id;
        });
    }

    public function test_create_generation_job_action_rejects_rule_strategy(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $spec = ApiSpec::factory()->create(['status' => ApiSpecStatus::Parsed]);
        $suite = TestSuite::factory()->create(['api_spec_id' => $spec->id]);

        try {
            (new CreateGenerationJobAction)->execute($user, $suite, ['strategy' => 'RULE']);
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::ValidationFailed, $e->errorCode);
        }

        $this->assertDatabaseCount('generation_jobs', 0);
        Queue::assertNotPushed(GenerateTestsJob::class);
    }

    public function test_create_generation_job_action_rejects_unparsed_spec(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $spec = ApiSpec::factory()->create(['status' => ApiSpecStatus::ParseFailed]);
        $suite = TestSuite::factory()->create(['api_spec_id' => $spec->id]);

        try {
            (new CreateGenerationJobAction)->execute($user, $suite, ['strategy' => 'LLM']);
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::ValidationFailed, $e->errorCode);
        }

        $this->assertDatabaseCount('generation_jobs', 0);
        Queue::assertNotPushed(GenerateTestsJob::class);
    }

    public function test_rule_job_given_to_llm_worker_is_failed_not_left_queued(): void
    {
        $spec = ApiSpec::factory()->create(['status' => ApiSpecStatus::Parsed]);
        $suite = TestSuite::factory()->create(['api_spec_id' => $spec->id]);
        $job = GenerationJob::factory()->create([
            'project_id' => $suite->project_id,
            'api_spec_id' => $spec->id,
            'test_suite_id' => $suite->id,
            'strategy' => GenerationStrategy::Rule,
            'status' => GenerationJobStatus::Queued,
        ]);

        app()->call([new GenerateTestsJob($job->id), 'handle']);

        $job->refresh();
        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertSame(ErrorCode::ValidationFailed, GenerationJobError::code($job->error));
        $this->assertNotNull($job->finished_at);
    }

    public function test_execute_end_to_end_method_on_execute_ai_generation_action(): void
    {
        $spec = ApiSpec::factory()->create();
        $endpoint = Endpoint::factory()->create([
            'api_spec_id' => $spec->id,
            'method' => HttpMethod::Get,
            'path' => '/sync',
            'responses' => ['200' => ['description' => 'OK']],
        ]);
        $suite = TestSuite::factory()->create(['api_spec_id' => $spec->id]);

        $job = GenerationJob::factory()->create([
            'project_id' => $spec->project_id,
            'api_spec_id' => $spec->id,
            'test_suite_id' => $suite->id,
            'strategy' => GenerationStrategy::Llm,
            'status' => GenerationJobStatus::Queued,
        ]);

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Sync end-to-end test',
            'endpoint' => ['method' => 'GET', 'path' => '/sync'],
            'technique' => 'HAPPY_PATH',
            'source' => 'LLM',
            'steps' => [
                [
                    'name' => 'Step',
                    'request' => ['method' => 'GET', 'path' => '/sync'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $mockClient = new class($dsl) implements AiGeneratorClient
        {
            /** @param array<string, mixed> $dsl */
            public function __construct(private array $dsl) {}

            public function status(): AiServiceStatus
            {
                return new AiServiceStatus(reachable: true, provider: 'fake');
            }

            public function generate(AiGenerateRequest $request): AiGenerateResponse
            {
                return new AiGenerateResponse(
                    testCases: [new AiGeneratedTestCase($this->dsl, 'VALID')],
                    llmCalls: [],
                    stats: new AiGenerationStats(1, 1, 0, 0, 0),
                );
            }
        };

        $executor = new ExecuteAiGenerationAction($mockClient, new RecordLlmCallsAction);
        $resultJob = $executor->executeEndToEnd($job);

        $this->assertSame(GenerationJobStatus::Succeeded, $resultJob->status);
        $this->assertIsArray($resultJob->stats);
        $this->assertSame(1, $resultJob->stats['valid']);
        $this->assertDatabaseCount('test_cases', 1);
        $this->assertSame('Sync end-to-end test', TestCaseModel::firstOrFail()->title);
    }

    public function test_ai_service_invalid_case_is_never_promoted_to_valid_by_web(): void
    {
        $spec = ApiSpec::factory()->create();
        $endpoint = Endpoint::factory()->create([
            'api_spec_id' => $spec->id,
            'method' => HttpMethod::Get,
            'path' => '/orders',
            'responses' => ['200' => ['description' => 'OK']],
        ]);
        $suite = TestSuite::factory()->create(['api_spec_id' => $spec->id]);

        $job = GenerationJob::factory()->create([
            'project_id' => $spec->project_id,
            'api_spec_id' => $spec->id,
            'test_suite_id' => $suite->id,
            'strategy' => GenerationStrategy::Llm,
            'status' => GenerationJobStatus::Running,
        ]);

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Orders case rejected by ai-service',
            'endpoint' => ['method' => 'GET', 'path' => '/orders'],
            'technique' => 'HAPPY_PATH',
            'source' => 'LLM',
            'steps' => [
                [
                    'name' => 'Step',
                    'request' => ['method' => 'GET', 'path' => '/orders'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $cases = app(ProcessGeneratedTestCasesAction::class)->execute($job, [
            new AiGeneratedTestCase($dsl, 'INVALID', ['repair exhausted'], 2),
        ]);

        $this->assertCount(1, $cases);
        $case = TestCaseModel::firstOrFail();
        $this->assertSame(TestCaseStatus::Invalid, $case->status);
        $this->assertSame($endpoint->id, $case->endpoint_id);
        $this->assertNull($case->fingerprint);
        $this->assertSame(['repair exhausted'], $case->validation_errors);

        $job->refresh();
        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertIsArray($job->stats);
        $this->assertSame(1, $job->stats['invalid']);
        // Repair was attempted but exhausted: not a successful repair (S10-01 web-ui r2, same rule as ai-service).
        $this->assertSame(0, $job->stats['repaired']);
    }
}
