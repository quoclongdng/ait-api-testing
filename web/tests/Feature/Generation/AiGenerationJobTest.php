<?php

namespace Tests\Feature\Generation;

use App\Actions\Generation\ExecuteAiGenerationAction;
use App\Actions\Generation\ProcessGeneratedTestCasesAction;
use App\Actions\Generation\RecordLlmCallsAction;
use App\Actions\Generation\SummarizeGenerationJobAction;
use App\Contracts\AiGeneratorClient;
use App\Data\Ai\AiGenerateRequest;
use App\Data\Ai\AiGenerateResponse;
use App\Data\AiServiceStatus;
use App\Enums\ErrorCode;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\HttpMethod;
use App\Enums\LlmPurpose;
use App\Enums\TestCaseStatus;
use App\Exceptions\ApiException;
use App\Jobs\GenerateTestsJob;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Models\GenerationJob;
use App\Models\LlmCallLog;
use App\Models\TestCase as TestCaseModel;
use App\Models\TestSuite;
use App\Services\Ai\FakeAiGeneratorClient;
use App\Services\Ai\HttpAiGeneratorClient;
use App\Services\Dsl\DslValidator;
use App\Services\Support\GenerationJobError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Unit\Services\AiResponseContractTest;

class AiGenerationJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_literal_strings_survive_database_and_http_round_trip(): void
    {
        foreach (['1.0', 'true', 'null', '{}', '[]', '1e999', '"quoted"', 'Pen'] as $literal) {
            Http::swap(new Factory);
            $job = GenerationJob::factory()->create(['status' => GenerationJobStatus::Queued, 'strategy' => GenerationStrategy::Llm]);
            $schema = ['type' => 'string', 'const' => $literal, 'enum' => [$literal], 'example' => $literal,
                'examples' => [$literal], 'default' => $literal, 'description' => $literal];
            // Exercise reordered object keys even when the local database is SQLite.
            $storedSchema = array_reverse($schema, true);
            Endpoint::factory()->create([
                'api_spec_id' => $job->api_spec_id,
                'parameters' => [['name' => 'name', 'in' => 'query', 'schema' => $storedSchema]],
                'request_body' => ['content' => ['application/json' => ['schema' => $storedSchema]]],
                'responses' => ['200' => ['description' => $literal, 'content' => ['application/json' => ['schema' => $storedSchema]]]],
            ]);
            Http::fake(['ai-service/v1/generate' => Http::response(AiResponseContractTest::payload())]);

            (new ExecuteAiGenerationAction(new HttpAiGeneratorClient('http://ai-service', 30), new RecordLlmCallsAction))->execute($job);

            Http::assertSent(function ($request) use ($schema, $literal) {
                // The action also reads GET /health for the timeout guard (S11-02).
                if (! str_ends_with($request->url(), '/v1/generate')) {
                    return false;
                }
                $sent = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR)['endpoints'][0];

                // jsonb may reorder object keys; scalar types and array order must stay intact.
                ksort($schema);
                foreach ([
                    $sent['parameters'][0]['schema'],
                    $sent['request_body']['content']['application/json']['schema'],
                    $sent['responses']['200']['content']['application/json']['schema'],
                ] as $actualSchema) {
                    ksort($actualSchema);
                    if ($actualSchema !== $schema) {
                        return false;
                    }
                }

                return $sent['responses']['200']['description'] === $literal;
            });
            $this->assertSame(GenerationJobStatus::Running, $job->refresh()->status);
        }
    }

    public function test_failure_path_only_persists_calls_with_valid_json_shapes(): void
    {
        $job = GenerationJob::factory()->create(['status' => GenerationJobStatus::Queued, 'strategy' => GenerationStrategy::Llm]);
        Endpoint::factory()->create(['api_spec_id' => $job->api_spec_id]);
        $payload = AiResponseContractTest::payload();
        $valid = $payload['llm_calls'][0];
        $badPrompt = $valid;
        $badPrompt['model'] = 'invalid-prompt-shape';
        $badPrompt['prompt'] = (object) ['0' => (object) ['role' => 'user', 'content' => 'Generate']];
        $badMessage = $valid;
        $badMessage['model'] = 'invalid-message-shape';
        $badMessage['prompt'] = [['user', 'Generate']];
        $payload['llm_calls'] = [$valid, $badPrompt, $badMessage, array_values($valid)];
        Http::fake(['ai-service/v1/generate' => Http::response($payload)]);

        try {
            (new ExecuteAiGenerationAction(new HttpAiGeneratorClient('http://ai-service', 30), new RecordLlmCallsAction))->execute($job);
            $this->fail('Invalid JSON shapes were accepted.');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::AiServiceUnavailable, $e->errorCode);
            $this->assertCount(1, $e->llmCalls);
        }
        $this->assertSame(GenerationJobStatus::Failed, $job->refresh()->status);
        $this->assertDatabaseCount('llm_call_logs', 1);
        $this->assertDatabaseHas('llm_call_logs', ['generation_job_id' => $job->id, 'model' => 'model']);
    }

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

    public function test_ai_service_down_transitions_job_to_failed_with_ai_service_unavailable(): void
    {
        $job = GenerationJob::factory()->create([
            'status' => GenerationJobStatus::Queued,
            'strategy' => GenerationStrategy::Llm,
        ]);

        $fakeClient = new FakeAiGeneratorClient(reachable: false);
        Endpoint::factory()->create(['api_spec_id' => $job->api_spec_id]);
        $recordAction = new RecordLlmCallsAction;
        $executor = new ExecuteAiGenerationAction($fakeClient, $recordAction);

        try {
            $executor->execute($job);
            $this->fail('Expected ApiException to be thrown');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::AiServiceUnavailable, $e->errorCode);
        }

        // Assert Acceptance Criteria: ai-service down → job FAILED + AI_SERVICE_UNAVAILABLE
        $job->refresh();
        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertSame(ErrorCode::AiServiceUnavailable, GenerationJobError::code($job->error));
        $this->assertNotNull($job->finished_at);
    }

    public function test_successful_ai_generation_transitions_state_and_records_llm_logs(): void
    {
        $job = GenerationJob::factory()->create([
            'status' => GenerationJobStatus::Queued,
            'strategy' => GenerationStrategy::Llm,
            'params' => [
                'options' => ['temperature' => 0.3],
            ],
        ]);

        $fakeClient = new FakeAiGeneratorClient(reachable: true);
        Endpoint::factory()->create(['api_spec_id' => $job->api_spec_id]);
        $recordAction = new RecordLlmCallsAction;
        $executor = new ExecuteAiGenerationAction($fakeClient, $recordAction);

        $response = $executor->execute($job);

        $job->refresh();
        $this->assertSame(GenerationJobStatus::Running, $job->status);
        $this->assertNotNull($job->started_at);
        $this->assertNull($job->error);
        $this->assertIsArray($job->stats);
        $this->assertSame(1, $job->stats['valid']);

        $this->assertCount(1, $response->testCases);
        $this->assertDatabaseHas('llm_call_logs', [
            'generation_job_id' => $job->id,
            'provider' => 'fake',
            'temperature' => 0.3,
        ]);
    }

    public function test_cannot_execute_job_in_terminal_state(): void
    {
        $terminalStatuses = [
            GenerationJobStatus::Succeeded,
            GenerationJobStatus::Failed,
            GenerationJobStatus::Partial,
        ];

        $fakeClient = new FakeAiGeneratorClient(reachable: true);
        $recordAction = new RecordLlmCallsAction;
        $executor = new ExecuteAiGenerationAction($fakeClient, $recordAction);

        foreach ($terminalStatuses as $status) {
            $job = GenerationJob::factory()->create([
                'status' => $status,
                'strategy' => GenerationStrategy::Llm,
            ]);

            try {
                $executor->execute($job);
                $this->fail("Expected ApiException for status {$status->value}");
            } catch (ApiException $e) {
                $this->assertSame(ErrorCode::InvalidStateTransition, $e->errorCode);
            }

            $job->refresh();
            $this->assertSame($status, $job->status);
        }
    }

    public function test_empty_spec_fails_without_calling_ai_service(): void
    {
        $job = GenerationJob::factory()->create(['status' => GenerationJobStatus::Queued, 'strategy' => GenerationStrategy::Llm]);
        $client = new FakeAiGeneratorClient;
        try {
            (new ExecuteAiGenerationAction($client, new RecordLlmCallsAction))->execute($job);
            $this->fail('Empty spec was accepted.');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::ValidationFailed, $e->errorCode);
        }
        $job->refresh();
        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertSame(ErrorCode::ValidationFailed, GenerationJobError::code($job->error));
        $this->assertNotNull($job->finished_at);
        $this->assertSame([], $client->recordedRequests());
        $this->assertDatabaseMissing('llm_call_logs', ['generation_job_id' => $job->id]);
    }

    public function test_invalid_response_fails_job_without_saving_logs_or_stats(): void
    {
        $job = GenerationJob::factory()->create(['status' => GenerationJobStatus::Queued, 'strategy' => GenerationStrategy::Llm]);
        Endpoint::factory()->create(['api_spec_id' => $job->api_spec_id]);
        $originalStats = $job->stats;
        $payload = AiResponseContractTest::payload();
        $payload['llm_calls'][0]['purpose'] = 'BROKEN';
        Http::fake(['ai-service/v1/generate' => Http::response($payload)]);
        $client = new HttpAiGeneratorClient('http://ai-service', 30);
        try {
            (new ExecuteAiGenerationAction($client, new RecordLlmCallsAction))->execute($job);
            $this->fail('Invalid response was accepted.');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::AiServiceUnavailable, $e->errorCode);
        }
        $job->refresh();
        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertSame(ErrorCode::AiServiceUnavailable, GenerationJobError::code($job->error));
        $this->assertNotNull($job->finished_at);
        $this->assertSame($originalStats, $job->stats);
        $this->assertDatabaseMissing('llm_call_logs', ['generation_job_id' => $job->id]);
    }

    public function test_build_request_error_marks_job_as_failed_with_finished_at(): void
    {
        // HYBRID without seed_cases causes buildRequest to fail
        $job = GenerationJob::factory()->create([
            'status' => GenerationJobStatus::Queued,
            'strategy' => GenerationStrategy::Hybrid,
            'params' => [],
        ]);

        $fakeClient = new FakeAiGeneratorClient(reachable: true);
        $recordAction = new RecordLlmCallsAction;
        $executor = new ExecuteAiGenerationAction($fakeClient, $recordAction);

        try {
            $executor->execute($job);
            $this->fail('Expected ApiException to be thrown');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::ValidationFailed, $e->errorCode);
        }

        $job->refresh();
        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertSame(ErrorCode::ValidationFailed, GenerationJobError::code($job->error));
        $this->assertNotNull($job->finished_at);
    }

    public function test_respects_endpoint_ids_filter_in_job_params(): void
    {
        $spec = ApiSpec::factory()->create();
        $endpoint1 = Endpoint::factory()->create([
            'api_spec_id' => $spec->id,
            'path' => '/selected-endpoint',
            'method' => HttpMethod::Get,
        ]);
        $endpoint2 = Endpoint::factory()->create([
            'api_spec_id' => $spec->id,
            'path' => '/ignored-endpoint',
            'method' => HttpMethod::Post,
        ]);

        $job = GenerationJob::factory()->create([
            'api_spec_id' => $spec->id,
            'status' => GenerationJobStatus::Queued,
            'strategy' => GenerationStrategy::Llm,
            'params' => [
                'endpoint_ids' => [$endpoint1->id],
                'options' => ['temperature' => 0.7],
            ],
        ]);

        $spyClient = new class implements AiGeneratorClient
        {
            public ?AiGenerateRequest $captured = null;

            public function status(): AiServiceStatus
            {
                return new AiServiceStatus(reachable: true, provider: 'fake');
            }

            public function generate(AiGenerateRequest $request): AiGenerateResponse
            {
                $this->captured = $request;

                return (new FakeAiGeneratorClient(reachable: true))->generate($request);
            }
        };

        $recordAction = new RecordLlmCallsAction;
        $executor = new ExecuteAiGenerationAction($spyClient, $recordAction);
        $executor->execute($job);

        $this->assertNotNull($spyClient->captured);
        $this->assertCount(1, $spyClient->captured->endpoints);
        $this->assertSame('/selected-endpoint', $spyClient->captured->endpoints[0]['path']);
        // Verify extra options like endpoint_ids are not in request options
        $this->assertArrayNotHasKey('endpoint_ids', $spyClient->captured->options);
    }

    public function test_selected_endpoint_ids_with_non_existent_id_throws_validation_failed_and_marks_job_failed(): void
    {
        $spec = ApiSpec::factory()->create();
        Endpoint::factory()->create([
            'api_spec_id' => $spec->id,
            'path' => '/existing-endpoint',
            'method' => HttpMethod::Get,
        ]);

        $job = GenerationJob::factory()->create([
            'api_spec_id' => $spec->id,
            'status' => GenerationJobStatus::Queued,
            'strategy' => GenerationStrategy::Llm,
            'params' => [
                'endpoint_ids' => [999999],
            ],
        ]);

        $fakeClient = new FakeAiGeneratorClient(reachable: true);
        $recordAction = new RecordLlmCallsAction;
        $executor = new ExecuteAiGenerationAction($fakeClient, $recordAction);

        try {
            $executor->execute($job);
            $this->fail('Expected ApiException to be thrown');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::ValidationFailed, $e->errorCode);
            $this->assertStringContainsString('endpoint_ids', $e->getMessage());
        }

        $job->refresh();
        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertSame(ErrorCode::ValidationFailed, GenerationJobError::code($job->error));
        $this->assertNotNull($job->finished_at);
    }

    public function test_build_request_preserves_empty_schema_objects_and_arrays_from_database(): void
    {
        $spec = ApiSpec::factory()->create();

        $rawRequestBody = [
            'content' => [
                'application/json' => [
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'meta' => (object) [],
                            'tags' => ['a', 'b'],
                        ],
                        'additionalProperties' => (object) [],
                        'enum' => [],
                        'required' => ['meta'],
                    ],
                ],
            ],
        ];

        $rawResponses = [
            '200' => [
                'description' => 'OK',
                'content' => [
                    'application/json' => [
                        'schema' => (object) [],
                    ],
                ],
            ],
        ];

        $rawParameters = [
            [
                'name' => 'id',
                'in' => 'path',
                'schema' => (object) [],
            ],
        ];

        $endpoint = Endpoint::create([
            'api_spec_id' => $spec->id,
            'method' => HttpMethod::Post,
            'path' => '/orders',
            'parameters' => $rawParameters,
            'request_body' => $rawRequestBody,
            'responses' => $rawResponses,
        ]);

        $job = GenerationJob::factory()->create([
            'api_spec_id' => $spec->id,
            'status' => GenerationJobStatus::Queued,
            'strategy' => GenerationStrategy::Llm,
            'params' => [
                'endpoint_ids' => [$endpoint->id],
            ],
        ]);

        /** @var string|null $sentJsonBody */
        $sentJsonBody = null;

        Http::fake([
            'http://ai-service:8001/v1/generate' => function ($request) use (&$sentJsonBody) {
                $sentJsonBody = $request->body();

                return Http::response(AiResponseContractTest::payload(), 200);
            },
        ]);

        $client = new HttpAiGeneratorClient('http://ai-service:8001', 30);
        $recordAction = new RecordLlmCallsAction;
        $executor = new ExecuteAiGenerationAction($client, $recordAction);
        $executor->execute($job);

        $this->assertNotNull($sentJsonBody);

        /** @var object $decodedSent */
        $decodedSent = json_decode($sentJsonBody, false, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue(isset($decodedSent->endpoints));
        $this->assertCount(1, $decodedSent->endpoints);

        $sentEp = $decodedSent->endpoints[0];

        // 1. Assert directly on the actual JSON string sent across the wire:
        $this->assertStringContainsString('"meta":{}', $sentJsonBody);
        $this->assertStringContainsString('"additionalProperties":{}', $sentJsonBody);
        $this->assertStringContainsString('"schema":{}', $sentJsonBody);

        // Assert control arrays remain []
        $this->assertStringContainsString('"enum":[]', $sentJsonBody);
        $this->assertStringContainsString('"tags":["a","b"]', $sentJsonBody);
        $this->assertStringContainsString('"required":["meta"]', $sentJsonBody);

        // 2. Assert object vs array types in decoded payload:
        $schemaObj = $sentEp->request_body->content->{'application/json'}->schema;
        $this->assertTrue(is_object($schemaObj->properties->meta));
        $this->assertTrue(is_object($schemaObj->additionalProperties));
        $this->assertTrue(is_array($schemaObj->properties->tags));
        $this->assertTrue(is_array($schemaObj->enum));
        $this->assertTrue(is_array($schemaObj->required));
        $this->assertTrue(is_object($sentEp->responses->{'200'}->content->{'application/json'}->schema));
        $this->assertTrue(is_object($sentEp->parameters[0]->schema));
    }

    public function test_valid_llm_calls_are_persisted_when_test_cases_fail_contract(): void
    {
        $spec = ApiSpec::factory()->create();
        Endpoint::factory()->create(['api_spec_id' => $spec->id]);

        $job = GenerationJob::factory()->create([
            'api_spec_id' => $spec->id,
            'status' => GenerationJobStatus::Queued,
            'strategy' => GenerationStrategy::Llm,
        ]);

        $payload = [
            'test_cases' => [
                [
                    'dsl' => ['dsl_version' => '1.0'],
                    'status' => 'BROKEN', // Violates contract
                    'validation_errors' => [],
                    'repair_attempts' => 0,
                    'fingerprint' => null,
                ],
            ],
            'llm_calls' => [
                [
                    'purpose' => 'GENERATE',
                    'provider' => 'ollama',
                    'model' => 'qwen2.5-coder:7b',
                    'prompt' => [
                        ['role' => 'system', 'content' => 'System prompt'],
                        ['role' => 'user', 'content' => 'User prompt'],
                    ],
                    'response_raw' => 'raw model response',
                    'temperature' => 0.2,
                    'seed' => null,
                    'prompt_tokens' => 150,
                    'completion_tokens' => 50,
                    'latency_ms' => 450,
                    'error' => null,
                ],
            ],
            'stats' => [
                'generated' => 1,
                'valid' => 0,
                'invalid' => 1,
                'repaired' => 0,
                'duplicates' => 0,
            ],
        ];

        Http::fake([
            'http://ai-service:8001/v1/generate' => Http::response($payload, 200),
        ]);

        $client = new HttpAiGeneratorClient('http://ai-service:8001', 30);
        $recordAction = new RecordLlmCallsAction;
        $executor = new ExecuteAiGenerationAction($client, $recordAction);

        try {
            $executor->execute($job);
            $this->fail('Expected ApiException to be thrown due to contract violation.');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::AiServiceUnavailable, $e->errorCode);
            $this->assertStringContainsString('hợp đồng', $e->getMessage());
        }

        $job->refresh();
        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertNotNull($job->finished_at);

        // Audit requirement D09: valid LLM call was persisted despite test_cases contract error!
        $this->assertDatabaseHas('llm_call_logs', [
            'generation_job_id' => $job->id,
            'provider' => 'ollama',
            'model' => 'qwen2.5-coder:7b',
            'prompt_tokens' => 150,
            'completion_tokens' => 50,
            'latency_ms' => 450,
        ]);
    }

    public function test_failed_generate_call_in_502_details_is_persisted_to_llm_call_logs(): void
    {
        $spec = ApiSpec::factory()->create();
        Endpoint::factory()->create(['api_spec_id' => $spec->id]);
        $job = GenerationJob::factory()->create([
            'api_spec_id' => $spec->id,
            'status' => GenerationJobStatus::Queued,
            'strategy' => GenerationStrategy::Llm,
        ]);

        $failedCall = [
            'purpose' => 'GENERATE',
            'provider' => 'openai_compatible',
            'model' => 'qwen2.5-coder:7b',
            'prompt' => [
                ['role' => 'system', 'content' => 'System prompt'],
                ['role' => 'user', 'content' => 'User prompt'],
            ],
            'response_raw' => '',
            'temperature' => 0.2,
            'seed' => 42,
            'prompt_tokens' => null,
            'completion_tokens' => null,
            'latency_ms' => 1200,
            'error' => 'LLM request timed out',
        ];
        $malformed = $failedCall;
        $malformed['prompt'] = 'not-an-array';

        Http::fake([
            'http://ai-service:8001/v1/generate' => Http::response([
                'error' => [
                    'code' => 'LLM_PROVIDER_ERROR',
                    'message' => 'LLM request timed out',
                    'details' => ['llm_calls' => [$failedCall, $malformed]],
                ],
            ], 502),
        ]);

        $executor = new ExecuteAiGenerationAction(new HttpAiGeneratorClient('http://ai-service:8001', 30), new RecordLlmCallsAction);

        try {
            $executor->execute($job);
            $this->fail('Expected ApiException for HTTP 502.');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::LlmProviderError, $e->errorCode);
            $this->assertCount(1, $e->llmCalls);
        }

        $job->refresh();
        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertSame(ErrorCode::LlmProviderError, GenerationJobError::code($job->error));
        $this->assertDatabaseCount('llm_call_logs', 1);
        $log = LlmCallLog::query()->where('generation_job_id', $job->id)->firstOrFail();
        $this->assertSame(LlmPurpose::Generate, $log->purpose);
        $this->assertSame('LLM request timed out', $log->error);
    }

    public function test_invalid_llm_calls_are_not_persisted_on_failure(): void
    {
        $spec = ApiSpec::factory()->create();
        Endpoint::factory()->create(['api_spec_id' => $spec->id]);

        $job = GenerationJob::factory()->create([
            'api_spec_id' => $spec->id,
            'status' => GenerationJobStatus::Queued,
            'strategy' => GenerationStrategy::Llm,
        ]);

        $payload = [
            'test_cases' => [
                [
                    'dsl' => ['dsl_version' => '1.0'],
                    'status' => 'BROKEN',
                    'validation_errors' => [],
                    'repair_attempts' => 0,
                    'fingerprint' => null,
                ],
            ],
            'llm_calls' => [
                [
                    'purpose' => 'INVALID_PURPOSE', // Invalid metadata
                    'provider' => 'ollama',
                    'model' => 'qwen2.5-coder:7b',
                    'prompt' => [['role' => 'user', 'content' => 'hello']],
                    'response_raw' => 'raw response',
                    'temperature' => 0.2,
                    'seed' => null,
                    'prompt_tokens' => null,
                    'completion_tokens' => null,
                    'latency_ms' => 100,
                    'error' => null,
                ],
            ],
            'stats' => [
                'generated' => 1,
                'valid' => 0,
                'invalid' => 1,
                'repaired' => 0,
                'duplicates' => 0,
            ],
        ];

        Http::fake([
            'http://ai-service:8001/v1/generate' => Http::response($payload, 200),
        ]);

        $client = new HttpAiGeneratorClient('http://ai-service:8001', 30);
        $recordAction = new RecordLlmCallsAction;
        $executor = new ExecuteAiGenerationAction($client, $recordAction);

        try {
            $executor->execute($job);
            $this->fail('Expected ApiException to be thrown.');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::AiServiceUnavailable, $e->errorCode);
        }

        $job->refresh();
        $this->assertSame(GenerationJobStatus::Failed, $job->status);

        // Invalid metadata calls must not be persisted
        $this->assertDatabaseMissing('llm_call_logs', [
            'generation_job_id' => $job->id,
        ]);
    }

    public function test_missing_requested_endpoint_results_in_partial_status_for_omitted_empty_and_explicit_selections(): void
    {
        $selections = [
            'omitted' => [],
            'empty' => ['endpoint_ids' => []],
            'explicit' => 'EXPLICIT',
        ];

        foreach ($selections as $name => $paramValue) {
            Http::swap(new Factory);
            $spec = ApiSpec::factory()->create();
            $ep1 = Endpoint::factory()->create([
                'api_spec_id' => $spec->id,
                'method' => HttpMethod::Post,
                'path' => '/one',
                'responses' => ['201' => ['description' => 'Created']],
            ]);
            $ep2 = Endpoint::factory()->create([
                'api_spec_id' => $spec->id,
                'method' => HttpMethod::Post,
                'path' => '/two',
                'responses' => ['201' => ['description' => 'Created']],
            ]);
            $suite = TestSuite::factory()->create([
                'project_id' => $spec->project_id,
                'api_spec_id' => $spec->id,
            ]);

            $params = ($paramValue === 'EXPLICIT')
                ? ['endpoint_ids' => [$ep1->id, $ep2->id]]
                : $paramValue;

            $job = GenerationJob::factory()->create([
                'project_id' => $spec->project_id,
                'api_spec_id' => $spec->id,
                'test_suite_id' => $suite->id,
                'strategy' => GenerationStrategy::Llm,
                'status' => GenerationJobStatus::Queued,
                'params' => $params,
            ]);

            $client = new HttpAiGeneratorClient('http://ai-service:8001', 30);
            $executor = new ExecuteAiGenerationAction($client, new RecordLlmCallsAction);
            $builtRequest = $executor->buildRequest($job);
            $this->assertCount(2, $builtRequest->endpoints, "Both endpoints should be requested for selection {$name}");

            // Fake AI returning valid case only for /one
            $validDsl1 = [
                'dsl_version' => '1.0',
                'title' => 'Case for /one',
                'endpoint' => ['method' => 'POST', 'path' => '/one'],
                'technique' => 'HAPPY_PATH',
                'source' => 'LLM',
                'steps' => [
                    [
                        'name' => 'Step 1',
                        'request' => ['method' => 'POST', 'path' => '/one'],
                        'assertions' => [['type' => 'status', 'equals' => 201]],
                    ],
                ],
            ];

            $aiPayload = [
                'test_cases' => [
                    [
                        'dsl' => $validDsl1,
                        'status' => 'VALID',
                        'validation_errors' => [],
                        'repair_attempts' => 0,
                        'fingerprint' => null,
                    ],
                ],
                'llm_calls' => [
                    [
                        'purpose' => 'GENERATE',
                        'provider' => 'fake',
                        'model' => 'qwen2.5-coder:7b',
                        'prompt' => [['role' => 'user', 'content' => 'hello']],
                        'response_raw' => 'raw',
                        'temperature' => 0.2,
                        'seed' => 42,
                        'prompt_tokens' => 10,
                        'completion_tokens' => 20,
                        'latency_ms' => 50,
                        'error' => null,
                    ],
                ],
                'stats' => [
                    'generated' => 1,
                    'valid' => 1,
                    'invalid' => 0,
                    'repaired' => 0,
                    'duplicates' => 0,
                ],
            ];

            Http::fake([
                'http://ai-service:8001/v1/generate' => Http::response($aiPayload, 200),
            ]);

            $workerJob = new GenerateTestsJob($job->id);
            $workerJob->handle($executor, app(ProcessGeneratedTestCasesAction::class));

            $job->refresh();
            $this->assertSame(
                GenerationJobStatus::Partial,
                $job->status,
                "Job status must be PARTIAL when endpoint /two is missing for selection {$name}"
            );
            $this->assertIsArray($job->stats);
            $this->assertSame(1, $job->stats['valid']);
            $this->assertSame(1, LlmCallLog::where('generation_job_id', $job->id)->count());
        }
    }

    public function test_complete_coverage_across_endpoints_results_in_succeeded(): void
    {
        $spec = ApiSpec::factory()->create();
        $ep1 = Endpoint::factory()->create([
            'api_spec_id' => $spec->id,
            'method' => HttpMethod::Post,
            'path' => '/one',
            'responses' => ['201' => ['description' => 'Created']],
        ]);
        $ep2 = Endpoint::factory()->create([
            'api_spec_id' => $spec->id,
            'method' => HttpMethod::Post,
            'path' => '/two',
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
            'params' => [],
        ]);

        $dsl1 = [
            'dsl_version' => '1.0',
            'title' => 'Case for /one',
            'endpoint' => ['method' => 'POST', 'path' => '/one'],
            'technique' => 'HAPPY_PATH',
            'source' => 'LLM',
            'steps' => [
                [
                    'name' => 'Step 1',
                    'request' => ['method' => 'POST', 'path' => '/one'],
                    'assertions' => [['type' => 'status', 'equals' => 201]],
                ],
            ],
        ];
        $dsl2 = [
            'dsl_version' => '1.0',
            'title' => 'Case for /two',
            'endpoint' => ['method' => 'POST', 'path' => '/two'],
            'technique' => 'HAPPY_PATH',
            'source' => 'LLM',
            'steps' => [
                [
                    'name' => 'Step 1',
                    'request' => ['method' => 'POST', 'path' => '/two'],
                    'assertions' => [['type' => 'status', 'equals' => 201]],
                ],
            ],
        ];

        $aiPayload = [
            'test_cases' => [
                ['dsl' => $dsl1, 'status' => 'VALID', 'validation_errors' => [], 'repair_attempts' => 0, 'fingerprint' => null],
                ['dsl' => $dsl2, 'status' => 'VALID', 'validation_errors' => [], 'repair_attempts' => 0, 'fingerprint' => null],
            ],
            'llm_calls' => [],
            'stats' => ['generated' => 2, 'valid' => 2, 'invalid' => 0, 'repaired' => 0, 'duplicates' => 0],
        ];

        Http::fake([
            'http://ai-service:8001/v1/generate' => Http::response($aiPayload, 200),
        ]);

        $client = new HttpAiGeneratorClient('http://ai-service:8001', 30);
        $executor = new ExecuteAiGenerationAction($client, new RecordLlmCallsAction);
        $workerJob = new GenerateTestsJob($job->id);
        $workerJob->handle($executor, app(ProcessGeneratedTestCasesAction::class));

        $job->refresh();
        $this->assertSame(GenerationJobStatus::Succeeded, $job->status);
        $this->assertIsArray($job->stats);
        $this->assertSame(2, $job->stats['valid']);
    }

    public function test_invalid_long_or_unicode_title_is_safely_truncated_in_metadata_without_dropping_valid_cases(): void
    {
        $spec = ApiSpec::factory()->create();
        $ep1 = Endpoint::factory()->create([
            'api_spec_id' => $spec->id,
            'method' => HttpMethod::Post,
            'path' => '/one',
            'responses' => ['201' => ['description' => 'Created']],
        ]);
        $ep2 = Endpoint::factory()->create([
            'api_spec_id' => $spec->id,
            'method' => HttpMethod::Post,
            'path' => '/two',
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
            'params' => [],
        ]);

        $isSqlite = DB::getDriverName() === 'sqlite';
        if ($isSqlite) {
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS test_cases_title_width_check BEFORE INSERT ON test_cases WHEN length(NEW.title) > 255 BEGIN SELECT RAISE(ABORT, 'title length > 255'); END;");
        }

        try {
            $longAsciiTitle = str_repeat('a', 300);
            $longUnicodeTitle = mb_substr(str_repeat('Tiêu đề kiểm thử đa byte ', 20), 0, 300, 'UTF-8');
            $border256Title = str_repeat('b', 256);

            $badDsl1 = [
                'dsl_version' => '1.0',
                'title' => $longAsciiTitle,
                'endpoint' => ['method' => 'POST', 'path' => '/one'],
                'technique' => 'HAPPY_PATH',
                'source' => 'LLM',
                'steps' => [
                    [
                        'name' => 'Step 1',
                        'request' => ['method' => 'POST', 'path' => '/one'],
                        'assertions' => [['type' => 'status', 'equals' => 201]],
                    ],
                ],
            ];

            $badDsl2 = [
                'dsl_version' => '1.0',
                'title' => $longUnicodeTitle,
                'endpoint' => ['method' => 'POST', 'path' => '/one'],
                'technique' => 'HAPPY_PATH',
                'source' => 'LLM',
                'steps' => [
                    [
                        'name' => 'Step 1',
                        'request' => ['method' => 'POST', 'path' => '/one'],
                        'assertions' => [['type' => 'status', 'equals' => 201]],
                    ],
                ],
            ];

            $badDsl3 = [
                'dsl_version' => '1.0',
                'title' => $border256Title,
                'endpoint' => ['method' => 'POST', 'path' => '/one'],
                'technique' => 'HAPPY_PATH',
                'source' => 'LLM',
                'steps' => [
                    [
                        'name' => 'Step 1',
                        'request' => ['method' => 'POST', 'path' => '/one'],
                        'assertions' => [['type' => 'status', 'equals' => 201]],
                    ],
                ],
            ];

            $goodDsl = [
                'dsl_version' => '1.0',
                'title' => 'Valid subsequent case',
                'endpoint' => ['method' => 'POST', 'path' => '/two'],
                'technique' => 'HAPPY_PATH',
                'source' => 'LLM',
                'steps' => [
                    [
                        'name' => 'Step 1',
                        'request' => ['method' => 'POST', 'path' => '/two'],
                        'assertions' => [['type' => 'status', 'equals' => 201]],
                    ],
                ],
            ];

            // Ensure validator flags the long titles as INVALID
            $validator = new DslValidator;
            $this->assertFalse($validator->validate($badDsl1, $spec)->isValid);
            $this->assertFalse($validator->validate($badDsl2, $spec)->isValid);
            $this->assertFalse($validator->validate($badDsl3, $spec)->isValid);
            $this->assertTrue($validator->validate($goodDsl, $spec)->isValid);

            $aiPayload = [
                'test_cases' => [
                    ['dsl' => $badDsl1, 'status' => 'VALID', 'validation_errors' => [], 'repair_attempts' => 0, 'fingerprint' => null],
                    ['dsl' => $badDsl2, 'status' => 'VALID', 'validation_errors' => [], 'repair_attempts' => 0, 'fingerprint' => null],
                    ['dsl' => $badDsl3, 'status' => 'VALID', 'validation_errors' => [], 'repair_attempts' => 0, 'fingerprint' => null],
                    ['dsl' => $goodDsl, 'status' => 'VALID', 'validation_errors' => [], 'repair_attempts' => 0, 'fingerprint' => null],
                ],
                'llm_calls' => [
                    [
                        'purpose' => 'GENERATE',
                        'provider' => 'fake',
                        'model' => 'qwen2.5-coder:7b',
                        'prompt' => [['role' => 'user', 'content' => 'hello']],
                        'response_raw' => 'raw',
                        'temperature' => 0.2,
                        'seed' => 42,
                        'prompt_tokens' => 10,
                        'completion_tokens' => 20,
                        'latency_ms' => 50,
                        'error' => null,
                    ],
                ],
                'stats' => ['generated' => 4, 'valid' => 4, 'invalid' => 0, 'repaired' => 0, 'duplicates' => 0],
            ];

            Http::fake([
                'http://ai-service:8001/v1/generate' => Http::response($aiPayload, 200),
            ]);

            $client = new HttpAiGeneratorClient('http://ai-service:8001', 30);
            $executor = new ExecuteAiGenerationAction($client, new RecordLlmCallsAction);
            $workerJob = new GenerateTestsJob($job->id);
            $workerJob->handle($executor, app(ProcessGeneratedTestCasesAction::class));

            $job->refresh();
            $this->assertSame(GenerationJobStatus::Partial, $job->status);
            $this->assertIsArray($job->stats);
            $this->assertSame(1, $job->stats['valid']);
            $this->assertSame(3, $job->stats['invalid']);

            $cases = TestCaseModel::where('generation_job_id', $job->id)->orderBy('id')->get();
            $this->assertCount(4, $cases);

            $case0 = $cases->get(0);
            $case1 = $cases->get(1);
            $case2 = $cases->get(2);
            $case3 = $cases->get(3);
            $this->assertInstanceOf(TestCaseModel::class, $case0);
            $this->assertInstanceOf(TestCaseModel::class, $case1);
            $this->assertInstanceOf(TestCaseModel::class, $case2);
            $this->assertInstanceOf(TestCaseModel::class, $case3);

            // Verify invalid cases have truncated metadata title <= 255 chars, but preserve full raw DSL title
            $this->assertSame(TestCaseStatus::Invalid, $case0->status);
            $this->assertLessThanOrEqual(255, mb_strlen($case0->title));
            $this->assertSame(255, mb_strlen($case0->title));
            $rawDsl0 = json_decode($case0->getRawOriginal('dsl'), true);
            $this->assertSame($longAsciiTitle, $rawDsl0['title']);

            $this->assertSame(TestCaseStatus::Invalid, $case1->status);
            $this->assertLessThanOrEqual(255, mb_strlen($case1->title));
            $this->assertSame(255, mb_strlen($case1->title));
            $rawDsl1 = json_decode($case1->getRawOriginal('dsl'), true);
            $this->assertSame($longUnicodeTitle, $rawDsl1['title']);

            $this->assertSame(TestCaseStatus::Invalid, $case2->status);
            $this->assertLessThanOrEqual(255, mb_strlen($case2->title));
            $this->assertSame(255, mb_strlen($case2->title));
            $rawDsl2 = json_decode($case2->getRawOriginal('dsl'), true);
            $this->assertSame($border256Title, $rawDsl2['title']);

            // Verify subsequent valid case was NOT dropped
            $this->assertSame(TestCaseStatus::Valid, $case3->status);
            $this->assertSame('Valid subsequent case', $case3->title);
        } finally {
            if ($isSqlite) {
                DB::unprepared('DROP TRIGGER IF EXISTS test_cases_title_width_check;');
            }
        }
    }

    /**
     * Regression (S11-02): endpoints were sent to ai-service in physical row order, which
     * PostgreSQL changes after an UPDATE, so the prompt (and FakeAiGeneratorClient's first
     * endpoint) differed between runs and experiment repetitions were not reproducible.
     */
    public function test_request_lists_endpoints_in_id_order_even_after_rows_are_updated(): void
    {
        $spec = ApiSpec::factory()->create();
        $endpoints = [];
        foreach (['/a-draft', '/b', '/c'] as $path) {
            $endpoints[] = Endpoint::factory()->create([
                'api_spec_id' => $spec->id,
                'method' => HttpMethod::Get,
                'path' => $path,
                'responses' => ['200' => ['description' => 'OK']],
            ]);
        }
        // Updating an indexed column (non-HOT update) moves the first row to the end of the
        // heap in PostgreSQL, so an unordered query returns it last.
        $endpoints[0]->forceFill(['path' => '/a'])->save();
        $suite = TestSuite::factory()->create(['project_id' => $spec->project_id, 'api_spec_id' => $spec->id]);
        $job = GenerationJob::factory()->create([
            'project_id' => $spec->project_id,
            'api_spec_id' => $spec->id,
            'test_suite_id' => $suite->id,
            'strategy' => GenerationStrategy::Llm,
            'status' => GenerationJobStatus::Queued,
            'params' => [],
        ]);

        $request = (new ExecuteAiGenerationAction(new FakeAiGeneratorClient, new RecordLlmCallsAction))->buildRequest($job);

        $this->assertSame(['/a', '/b', '/c'], array_column($request->endpoints, 'path'));
    }

    /**
     * Regression (S10-01 r2): a 502 carrying error.details.llm_calls (provider timeout) wrote no
     * llm_call_logs row (D09) and M11 showed only the error code.
     */
    public function test_failed_llm_call_from_a_502_is_logged_and_shown_on_m11(): void
    {
        $spec = ApiSpec::factory()->create();
        Endpoint::factory()->create(['api_spec_id' => $spec->id]);
        $job = GenerationJob::factory()->create([
            'api_spec_id' => $spec->id,
            'status' => GenerationJobStatus::Queued,
            'strategy' => GenerationStrategy::Llm,
        ]);

        Http::fake([
            'http://ai-service:8001/v1/generate' => Http::response([
                'error' => [
                    'code' => 'LLM_PROVIDER_ERROR',
                    'message' => 'LLM provider error: timed out',
                    'details' => [
                        'llm_calls' => [[
                            'purpose' => 'GENERATE',
                            'provider' => 'openai_compatible',
                            'model' => 'qwen2.5-coder:7b',
                            'prompt' => [['role' => 'user', 'content' => 'generate']],
                            'response_raw' => '',
                            'temperature' => 0.2,
                            'seed' => 42,
                            'prompt_tokens' => null,
                            'completion_tokens' => null,
                            'latency_ms' => 100000,
                            'error' => 'timed out',
                        ]],
                    ],
                ],
                'trace_id' => 'trace-502',
            ], 502),
        ]);

        try {
            (new ExecuteAiGenerationAction(new HttpAiGeneratorClient('http://ai-service:8001', 30), new RecordLlmCallsAction))->execute($job);
            $this->fail('Expected LLM_PROVIDER_ERROR.');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::LlmProviderError, $e->errorCode);
        }

        $job->refresh();
        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertDatabaseHas('llm_call_logs', [
            'generation_job_id' => $job->id,
            'model' => 'qwen2.5-coder:7b',
            'latency_ms' => 100000,
            'error' => 'timed out',
        ]);

        $summary = app(SummarizeGenerationJobAction::class)->execute($job);
        $this->assertStringContainsString('timed out', (string) $summary->errorMessage);
        $this->assertSame(1, $summary->llm['failed_calls']);
    }
}
