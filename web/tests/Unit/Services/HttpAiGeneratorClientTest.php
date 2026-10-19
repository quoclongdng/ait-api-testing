<?php

namespace Tests\Unit\Services;

use App\Data\Ai\AiGenerateRequest;
use App\Enums\ErrorCode;
use App\Enums\GenerationStrategy;
use App\Exceptions\ApiException;
use App\Services\Ai\HttpAiGeneratorClient;
use App\Services\Dsl\DslValidator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpAiGeneratorClientTest extends TestCase
{
    public function test_valid_dsl_remains_valid_after_http_response_round_trip(): void
    {
        $fixture = file_get_contents(base_path('../docs/contracts/examples/valid-create-item-happy-path.json'));
        $this->assertIsString($fixture);
        $dsl = json_decode($fixture, true, 512, JSON_THROW_ON_ERROR);
        $validator = new DslValidator;
        $this->assertTrue($validator->validate($dsl)->isValid);
        $payload = AiResponseContractTest::payload();
        $payload['test_cases'][0]['dsl'] = $dsl;
        Http::fake(['ai-service/v1/generate' => Http::response($payload)]);

        $response = (new HttpAiGeneratorClient('http://ai-service', 30))->generate($this->sampleRequest());

        $this->assertSame('1.0', $response->testCases[0]->dsl['dsl_version']);
        $this->assertTrue($validator->validate($response->testCases[0]->dsl)->isValid);
    }

    public function test_generate_preserves_json_literal_strings_in_dsl(): void
    {
        foreach (['1.0', 'true', 'null', '{}', '[]', '1e999', '"quoted"', 'Pen'] as $literal) {
            Http::swap(new Factory);
            $payload = AiResponseContractTest::payload();
            $dsl = [
                'dsl_version' => '1.0',
                'steps' => [[
                    'request' => ['body' => ['name' => $literal]],
                    'assertions' => [['type' => 'json_path', 'value' => $literal]],
                ]],
            ];
            $payload['test_cases'][0]['dsl'] = $dsl;
            Http::fake(['ai-service/v1/generate' => Http::response($payload)]);

            $response = (new HttpAiGeneratorClient('http://ai-service', 30))->generate($this->sampleRequest());

            $this->assertSame($dsl, $response->testCases[0]->dsl);
            $this->assertSame($dsl, $response->toArray()['test_cases'][0]['dsl']);
            $this->assertSame('VALID', $response->testCases[0]->status);
        }
    }

    private function sampleRequest(): AiGenerateRequest
    {
        return new AiGenerateRequest(
            jobId: 10,
            strategy: GenerationStrategy::Llm,
            endpoints: [
                [
                    'id' => 1,
                    'method' => 'GET',
                    'path' => '/items',
                    'operation_id' => 'getItems',
                    'summary' => 'List items',
                    'description' => null,
                    'parameters' => null,
                    'request_body' => null,
                    'responses' => ['200' => ['description' => 'OK']],
                    'security' => null,
                ],
            ],
            businessRules: [],
            seedCases: [],
            options: ['temperature' => 0.2],
        );
    }

    public function test_generate_successfully_parses_200_response(): void
    {
        $payload = [
            'test_cases' => [
                [
                    'dsl' => [
                        'version' => '1.0',
                        'metadata' => ['title' => 'Sample test', 'technique' => 'HAPPY_PATH', 'source' => 'LLM'],
                        'endpoint' => ['method' => 'GET', 'path' => '/items'],
                        'steps' => [
                            [
                                'name' => 'get items',
                                'request' => ['method' => 'GET', 'path' => '/items'],
                                'assertions' => [['type' => 'status', 'equals' => 200]],
                            ],
                        ],
                    ],
                    'status' => 'VALID',
                    'validation_errors' => [],
                    'repair_attempts' => 0,
                    'fingerprint' => '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
                ],
            ],
            'llm_calls' => [
                [
                    'purpose' => 'GENERATE',
                    'provider' => 'ollama',
                    'model' => 'qwen2.5-coder:7b',
                    'prompt' => [['role' => 'user', 'content' => 'Generate tests']],
                    'response_raw' => '{"dsl": ...}',
                    'temperature' => 0.2,
                    'seed' => 42,
                    'prompt_tokens' => 120,
                    'completion_tokens' => 85,
                    'latency_ms' => 950,
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
            'http://ai-service:8001/v1/generate' => Http::response($payload, 200),
        ]);

        $client = new HttpAiGeneratorClient('http://ai-service:8001', 30);
        $response = $client->generate($this->sampleRequest());

        $this->assertCount(1, $response->testCases);
        $this->assertSame('VALID', $response->testCases[0]->status);
        $this->assertSame('Sample test', $response->testCases[0]->dsl['metadata']['title']);

        $this->assertCount(1, $response->llmCalls);
        $this->assertSame('qwen2.5-coder:7b', $response->llmCalls[0]->model);
        $this->assertSame(950, $response->llmCalls[0]->latencyMs);

        $this->assertSame(1, $response->stats->valid);
        $this->assertSame(1, $response->stats->generated);
    }

    public function test_generate_throws_ai_service_unavailable_on_connection_failure(): void
    {
        Http::fake([
            'http://ai-service:8001/v1/generate' => fn () => throw new ConnectionException('Connection refused to ai-service:8001'),
        ]);

        $client = new HttpAiGeneratorClient('http://ai-service:8001', 30);

        $this->expectException(ApiException::class);

        try {
            $client->generate($this->sampleRequest());
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::AiServiceUnavailable, $e->errorCode);
            $this->assertStringContainsString('không khả dụng hoặc kết nối bị từ chối', $e->getMessage());
            throw $e;
        }
    }

    public function test_generate_throws_ai_service_unavailable_on_503(): void
    {
        Http::fake([
            'http://ai-service:8001/v1/generate' => Http::response(['error' => 'Service Unavailable'], 503),
        ]);

        $client = new HttpAiGeneratorClient('http://ai-service:8001', 30);

        $this->expectException(ApiException::class);

        try {
            $client->generate($this->sampleRequest());
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::AiServiceUnavailable, $e->errorCode);
            throw $e;
        }
    }

    public function test_generate_throws_ai_service_unavailable_on_500(): void
    {
        Http::fake([
            'http://ai-service:8001/v1/generate' => Http::response(['error' => 'Internal server error'], 500),
        ]);

        $client = new HttpAiGeneratorClient('http://ai-service:8001', 30);

        $this->expectException(ApiException::class);

        try {
            $client->generate($this->sampleRequest());
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::AiServiceUnavailable, $e->errorCode);
            throw $e;
        }
    }

    public function test_generate_throws_llm_provider_error_on_502(): void
    {
        Http::fake([
            'http://ai-service:8001/v1/generate' => Http::response([
                'error' => [
                    'code' => 'LLM_PROVIDER_ERROR',
                    'message' => 'Ollama model timed out',
                ],
            ], 502),
        ]);

        $client = new HttpAiGeneratorClient('http://ai-service:8001', 30);

        $this->expectException(ApiException::class);

        try {
            $client->generate($this->sampleRequest());
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::LlmProviderError, $e->errorCode);
            $this->assertStringContainsString('Ollama model timed out', $e->getMessage());
            throw $e;
        }
    }

    public function test_generate_502_without_llm_calls_details_yields_no_llm_calls(): void
    {
        Http::fake([
            'http://ai-service:8001/v1/generate' => Http::response('upstream down', 502),
        ]);

        try {
            (new HttpAiGeneratorClient('http://ai-service:8001', 30))->generate($this->sampleRequest());
            $this->fail('Expected ApiException.');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::LlmProviderError, $e->errorCode);
            $this->assertSame([], $e->llmCalls);
        }
    }

    public function test_generate_throws_validation_failed_on_422(): void
    {
        Http::fake([
            'http://ai-service:8001/v1/generate' => Http::response([
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'endpoints must not be empty',
                    'details' => ['endpoints' => 'missing'],
                ],
            ], 422),
        ]);

        $client = new HttpAiGeneratorClient('http://ai-service:8001', 30);

        $this->expectException(ApiException::class);

        try {
            $client->generate($this->sampleRequest());
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::ValidationFailed, $e->errorCode);
            $this->assertStringContainsString('endpoints must not be empty', $e->getMessage());
            $this->assertSame(['endpoints' => 'missing'], $e->details);
            throw $e;
        }
    }

    public function test_generate_throws_when_200_response_is_missing_required_fields(): void
    {
        Http::fake([
            'http://ai-service:8001/v1/generate' => Http::response([], 200),
        ]);

        $client = new HttpAiGeneratorClient('http://ai-service:8001', 30);

        $this->expectException(ApiException::class);

        try {
            $client->generate($this->sampleRequest());
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::AiServiceUnavailable, $e->errorCode);
            $this->assertStringContainsString('thiếu trường bắt buộc', $e->getMessage());
            throw $e;
        }
    }

    public function test_generate_filters_out_forbidden_extra_options(): void
    {
        Http::fake([
            'http://ai-service:8001/v1/generate' => Http::response([
                'test_cases' => [],
                'llm_calls' => [],
                'stats' => ['generated' => 0, 'valid' => 0, 'invalid' => 0, 'repaired' => 0, 'duplicates' => 0],
            ], 200),
        ]);

        $requestWithExtraOptions = new AiGenerateRequest(
            jobId: 10,
            strategy: GenerationStrategy::Llm,
            endpoints: [
                ['id' => 1, 'method' => 'GET', 'path' => '/items', 'responses' => []],
            ],
            options: [
                'temperature' => 0.5,
                'endpoint_ids' => [1, 2],
                'seed_cases' => ['foo'],
                'random_extra' => 'forbidden',
            ],
        );

        $client = new HttpAiGeneratorClient('http://ai-service:8001', 30);
        $client->generate($requestWithExtraOptions);

        Http::assertSent(function ($request) {
            $options = $request['options'];

            return $options['temperature'] === 0.5
                && ! array_key_exists('endpoint_ids', $options)
                && ! array_key_exists('seed_cases', $options)
                && ! array_key_exists('random_extra', $options);
        });
    }

    public function test_generate_throws_when_test_case_element_is_missing_required_dsl(): void
    {
        Http::fake([
            'http://ai-service:8001/v1/generate' => Http::response([
                'test_cases' => [
                    ['status' => 'VALID'],
                ],
                'llm_calls' => [],
                'stats' => ['generated' => 1, 'valid' => 1, 'invalid' => 0, 'repaired' => 0, 'duplicates' => 0],
            ], 200),
        ]);

        $client = new HttpAiGeneratorClient('http://ai-service:8001', 30);

        $this->expectException(ApiException::class);

        try {
            $client->generate($this->sampleRequest());
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::AiServiceUnavailable, $e->errorCode);
            $this->assertStringContainsString('dsl', $e->getMessage());
            throw $e;
        }
    }

    public function test_generate_throws_when_llm_call_element_is_missing_required_fields(): void
    {
        Http::fake([
            'http://ai-service:8001/v1/generate' => Http::response([
                'test_cases' => [],
                'llm_calls' => [
                    ['provider' => 'fake'], // missing model, prompt, response_raw, latency_ms
                ],
                'stats' => ['generated' => 0, 'valid' => 0, 'invalid' => 0, 'repaired' => 0, 'duplicates' => 0],
            ], 200),
        ]);

        $client = new HttpAiGeneratorClient('http://ai-service:8001', 30);

        $this->expectException(ApiException::class);

        try {
            $client->generate($this->sampleRequest());
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::AiServiceUnavailable, $e->errorCode);
            $this->assertStringContainsString('LlmCall', $e->getMessage());
            throw $e;
        }
    }

    public function test_generate_preserves_invalid_dsl_empty_object_in_to_array(): void
    {
        $rawPayload = json_encode([
            'test_cases' => [
                [
                    'dsl' => (object) [],
                    'status' => 'INVALID',
                    'validation_errors' => ['Malformed LLM output'],
                    'repair_attempts' => 0,
                    'fingerprint' => null,
                ],
            ],
            'llm_calls' => [],
            'stats' => [
                'generated' => 1,
                'valid' => 0,
                'invalid' => 1,
                'repaired' => 0,
                'duplicates' => 0,
            ],
        ], JSON_THROW_ON_ERROR);

        Http::fake([
            'http://ai-service:8001/v1/generate' => Http::response($rawPayload, 200, ['Content-Type' => 'application/json']),
        ]);

        $client = new HttpAiGeneratorClient('http://ai-service:8001', 30);
        $response = $client->generate($this->sampleRequest());

        $this->assertCount(1, $response->testCases);
        $this->assertSame('INVALID', $response->testCases[0]->status);

        $array = $response->toArray();
        $encoded = json_encode($array['test_cases'][0]['dsl'], JSON_THROW_ON_ERROR);
        $this->assertSame('{}', $encoded);
    }

    public function test_generate_preserves_nested_assertion_value_object_and_array_round_trip(): void
    {
        $rawPayload = json_encode([
            'test_cases' => [
                [
                    'dsl' => [
                        'metadata' => ['title' => 'Assertion test'],
                        'steps' => [
                            [
                                'assertions' => [
                                    [
                                        'type' => 'json_path',
                                        'op' => 'eq',
                                        'value' => (object) [
                                            'object' => (object) [],
                                            'array' => [],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'status' => 'VALID',
                    'validation_errors' => [],
                    'repair_attempts' => 0,
                    'fingerprint' => null,
                ],
            ],
            'llm_calls' => [],
            'stats' => [
                'generated' => 1,
                'valid' => 1,
                'invalid' => 0,
                'repaired' => 0,
                'duplicates' => 0,
            ],
        ], JSON_THROW_ON_ERROR);

        Http::fake([
            'http://ai-service:8001/v1/generate' => Http::response($rawPayload, 200, ['Content-Type' => 'application/json']),
        ]);

        $client = new HttpAiGeneratorClient('http://ai-service:8001', 30);
        $response = $client->generate($this->sampleRequest());

        $this->assertSame('Assertion test', $response->testCases[0]->dsl['metadata']['title']);

        $array = $response->toArray();
        $val = $array['test_cases'][0]['dsl']['steps'][0]['assertions'][0]['value'];
        $encodedVal = json_encode($val, JSON_THROW_ON_ERROR);
        $this->assertSame('{"object":{},"array":[]}', $encodedVal);
    }

    public function test_generate_preserves_nested_request_body_with_numeric_keys(): void
    {
        $rawPayload = json_encode([
            'test_cases' => [
                [
                    'dsl' => [
                        'steps' => [
                            [
                                'request' => [
                                    'body' => (object) [
                                        '0' => 'first',
                                        '123' => 'numeric_val',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'status' => 'VALID',
                    'validation_errors' => [],
                    'repair_attempts' => 0,
                    'fingerprint' => null,
                ],
            ],
            'llm_calls' => [],
            'stats' => [
                'generated' => 1,
                'valid' => 1,
                'invalid' => 0,
                'repaired' => 0,
                'duplicates' => 0,
            ],
        ], JSON_THROW_ON_ERROR);

        Http::fake([
            'http://ai-service:8001/v1/generate' => Http::response($rawPayload, 200, ['Content-Type' => 'application/json']),
        ]);

        $client = new HttpAiGeneratorClient('http://ai-service:8001', 30);
        $response = $client->generate($this->sampleRequest());

        $array = $response->toArray();
        $body = $array['test_cases'][0]['dsl']['steps'][0]['request']['body'];
        $encodedBody = json_encode($body, JSON_THROW_ON_ERROR);
        $this->assertSame('{"0":"first","123":"numeric_val"}', $encodedBody);
    }
}
