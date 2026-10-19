<?php

namespace App\Services\Ai;

use App\Contracts\AiGeneratorClient;
use App\Data\Ai\AiGeneratedTestCase;
use App\Data\Ai\AiGenerateRequest;
use App\Data\Ai\AiGenerateResponse;
use App\Data\Ai\AiGenerationStats;
use App\Data\Ai\AiLlmCall;
use App\Data\AiServiceStatus;
use App\Enums\ErrorCode;
use App\Enums\LlmPurpose;
use App\Exceptions\ApiException;

/**
 * Deterministic stand-in for the ai-service. Used in tests, CI and when the
 * Python service is not running (AI_SERVICE_DRIVER=fake).
 */
final class FakeAiGeneratorClient implements AiGeneratorClient
{
    /** @var list<AiGenerateRequest> */
    private array $recordedRequests = [];

    private ?AiGenerateResponse $cannedResponse = null;

    private ?\Throwable $cannedException = null;

    private string $provider = 'fake';

    public function __construct(private bool $reachable = true) {}

    public function setReachable(bool $reachable): void
    {
        $this->reachable = $reachable;
    }

    /** Provider reported by status(), e.g. 'disabled' to simulate a server without LLM (ADR-0007). */
    public function setProvider(string $provider): void
    {
        $this->provider = $provider;
    }

    public function setGenerateResponse(?AiGenerateResponse $response): void
    {
        $this->cannedResponse = $response;
    }

    public function setGenerateException(?\Throwable $exception): void
    {
        $this->cannedException = $exception;
    }

    /**
     * @return list<AiGenerateRequest>
     */
    public function recordedRequests(): array
    {
        return $this->recordedRequests;
    }

    public function lastRequest(): ?AiGenerateRequest
    {
        return $this->recordedRequests === [] ? null : end($this->recordedRequests);
    }

    public function status(): AiServiceStatus
    {
        return $this->reachable
            ? new AiServiceStatus(reachable: true, provider: $this->provider, version: 'fake', model: 'fake', temperature: 0.2, seed: 42)
            : new AiServiceStatus(reachable: false);
    }

    public function generate(AiGenerateRequest $request): AiGenerateResponse
    {
        $this->recordedRequests[] = $request;

        if (! $this->reachable) {
            throw new ApiException(
                ErrorCode::AiServiceUnavailable,
                AiGeneratorClient::UNAVAILABLE_MESSAGE.'.'
            );
        }

        if ($this->cannedException !== null) {
            throw $this->cannedException;
        }

        if ($this->cannedResponse !== null) {
            return $this->cannedResponse;
        }

        return $this->defaultResponse($request);
    }

    private function defaultResponse(AiGenerateRequest $request): AiGenerateResponse
    {
        $endpoint = $request->endpoints[0] ?? ['method' => 'GET', 'path' => '/items'];
        $method = (string) ($endpoint['method'] ?? 'GET');
        $path = (string) ($endpoint['path'] ?? '/items');

        $dsl = [
            'dsl_version' => '1.0',
            'title' => "Auto generated test for {$method} {$path}",
            'endpoint' => [
                'method' => $method,
                'path' => $path,
            ],
            'technique' => 'HAPPY_PATH',
            'source' => 'LLM',
            'steps' => [
                [
                    'name' => 'execute request',
                    'request' => [
                        'method' => $method,
                        'path' => $path,
                    ],
                    'assertions' => [
                        ['type' => 'status', 'equals' => 200],
                    ],
                ],
            ],
        ];

        $llmCall = new AiLlmCall(
            purpose: LlmPurpose::Generate,
            provider: 'fake',
            model: 'fake-model',
            prompt: [
                ['role' => 'user', 'content' => "Generate test for {$method} {$path}"],
            ],
            responseRaw: json_encode($dsl) ?: '{}',
            temperature: (float) ($request->options['temperature'] ?? 0.2),
            seed: isset($request->options['seed']) ? (int) $request->options['seed'] : 42,
            promptTokens: 100,
            completionTokens: 80,
            latencyMs: 150,
            error: null,
        );

        $testCase = new AiGeneratedTestCase(
            dsl: $dsl,
            status: 'VALID',
            validationErrors: [],
            repairAttempts: 0,
            fingerprint: hash('sha256', json_encode($dsl) ?: ''),
        );

        return new AiGenerateResponse(
            testCases: [$testCase],
            llmCalls: [$llmCall],
            stats: new AiGenerationStats(
                generated: 1,
                valid: 1,
                invalid: 0,
                repaired: 0,
                duplicates: 0,
            ),
        );
    }
}
