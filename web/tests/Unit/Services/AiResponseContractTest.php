<?php

namespace Tests\Unit\Services;

use App\Data\Ai\AiGenerateRequest;
use App\Data\Ai\AiGenerateResponse;
use App\Enums\ErrorCode;
use App\Enums\GenerationStrategy;
use App\Exceptions\ApiException;
use App\Services\Ai\HttpAiGeneratorClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiResponseContractTest extends TestCase
{
    /** @return array<string, mixed> */
    public static function payload(): array
    {
        return [
            'test_cases' => [['dsl' => ['version' => '1.0'], 'status' => 'VALID', 'validation_errors' => [], 'repair_attempts' => 0, 'fingerprint' => null]],
            'llm_calls' => [[
                'purpose' => 'GENERATE', 'provider' => 'ollama', 'model' => 'model',
                'prompt' => [['role' => 'user', 'content' => 'Generate']], 'response_raw' => '',
                'temperature' => 0.2, 'seed' => null, 'prompt_tokens' => null,
                'completion_tokens' => null, 'latency_ms' => 0, 'error' => null,
            ]],
            'stats' => ['generated' => 1, 'valid' => 1, 'invalid' => 0, 'repaired' => 0, 'duplicates' => 0],
        ];
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidPayloads(): iterable
    {
        $payload = self::payload();
        foreach (array_keys($payload) as $key) {
            $bad = $payload;
            unset($bad[$key]);
            yield "missing {$key}" => [$bad];
        }
        foreach (['test_cases', 'llm_calls', 'stats'] as $section) {
            $fields = $section === 'stats' ? $payload[$section] : $payload[$section][0];
            foreach (array_keys($fields) as $key) {
                $bad = $payload;
                if ($section === 'stats') {
                    unset($bad[$section][$key]);
                } else {
                    unset($bad[$section][0][$key]);
                }
                yield "missing {$section}.{$key}" => [$bad];
            }
        }
        $mutations = [
            'test_cases.0.status' => ['BROKEN', null, 1],
            'test_cases.0.dsl' => [null, 'dsl', [], ['not an object']],
            'test_cases.0.repair_attempts' => [-1, 0.5, '1', null, true],
            'test_cases.0.fingerprint' => ['bad', str_repeat('A', 64), str_repeat('a', 64)."\n", 1],
            'test_cases.0.validation_errors' => ['bad', [1], [null], ['error' => 'bad'], (object) []],
            'llm_calls.0.purpose' => ['UNKNOWN', null, 1],
            'llm_calls.0.provider' => ['', ' ', null, 1],
            'llm_calls.0.model' => ['', ' ', null, 1],
            'llm_calls.0.prompt' => [[], ['message'], [['role' => 'tool', 'content' => 'x']], [['role' => 'user']], [['content' => 'x']], [['role' => 'user', 'content' => 1]]],
            'llm_calls.0.response_raw' => [null, 1],
            'llm_calls.0.temperature' => [-0.1, 2.1, '0.2', null, true],
            'llm_calls.0.seed' => [0.5, '1', false],
            'llm_calls.0.prompt_tokens' => [-1, 0.5, '1', false],
            'llm_calls.0.completion_tokens' => [-1, 0.5, '1', false],
            'llm_calls.0.latency_ms' => [-1, 0.5, '1', null, false],
            'llm_calls.0.error' => [1, []],
            'test_cases' => [['case' => $payload['test_cases'][0]], 'bad', (object) []],
            'llm_calls' => [['call' => $payload['llm_calls'][0]], null, (object) []],
        ];
        foreach (array_keys($payload['stats']) as $key) {
            $mutations["stats.{$key}"] = [-1, 0.5, '1', null, true];
        }
        foreach ($mutations as $path => $values) {
            foreach ($values as $index => $value) {
                $bad = $payload;
                data_set($bad, $path, $value);
                yield "{$path} invalid {$index}" => [$bad];
            }
        }
        foreach (['extra', 'test_cases.0.extra', 'llm_calls.0.extra', 'llm_calls.0.prompt.0.extra', 'stats.extra'] as $path) {
            $bad = $payload;
            data_set($bad, $path, true);
            yield "unexpected {$path}" => [$bad];
        }
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_response_returns_ai_service_unavailable(array $payload): void
    {
        Http::fake(['ai-service/v1/generate' => Http::response($payload)]);
        $request = new AiGenerateRequest(jobId: 1, strategy: GenerationStrategy::Llm, endpoints: [['method' => 'GET', 'path' => '/items']]);
        try {
            (new HttpAiGeneratorClient('http://ai-service', 30))->generate($request);
            $this->fail('Invalid response was accepted.');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::AiServiceUnavailable, $e->errorCode);
        }
    }

    public function test_contract_accepts_nullable_fields_and_boundary_values(): void
    {
        foreach (['VALID', 'INVALID', 'DUPLICATE'] as $status) {
            foreach ([0, 2] as $temperature) {
                $payload = self::payload();
                $payload['test_cases'][0]['status'] = $status;
                $payload['test_cases'][0]['fingerprint'] = str_repeat('a', 64);
                $payload['llm_calls'][0]['purpose'] = 'REPAIR';
                $payload['llm_calls'][0]['temperature'] = $temperature;
                $payload['llm_calls'][0]['seed'] = -1;
                $response = AiGenerateResponse::fromArray($payload);
                $this->assertSame($status, $response->testCases[0]->status);
                $this->assertSame((float) $temperature, $response->llmCalls[0]->temperature);
            }
        }
        $this->assertNull(AiGenerateResponse::fromArray(self::payload())->testCases[0]->fingerprint);
    }
}
