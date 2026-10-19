<?php

namespace App\Services\Ai;

use App\Contracts\AiGeneratorClient;
use App\Data\Ai\AiGenerateRequest;
use App\Data\Ai\AiGenerateResponse;
use App\Data\Ai\AiLlmCall;
use App\Data\Ai\ResponseContract;
use App\Data\AiServiceStatus;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Services\Support\GenerationJobError;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class HttpAiGeneratorClient implements AiGeneratorClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds,
    ) {}

    public function status(): AiServiceStatus
    {
        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout(min($this->timeoutSeconds, 5))
                ->acceptJson()
                ->get('/health');
        } catch (ConnectionException) {
            return new AiServiceStatus(reachable: false);
        }

        if (! $response->successful()) {
            return new AiServiceStatus(reachable: false);
        }

        $provider = $response->json('provider');
        $version = $response->json('version');
        $model = $response->json('model');
        $temperature = $response->json('temperature');
        $seed = $response->json('seed');
        $budget = $response->json('generate_budget_seconds');

        return new AiServiceStatus(
            reachable: true,
            provider: is_string($provider) ? $provider : null,
            version: is_string($version) ? $version : null,
            model: is_string($model) && $model !== '' ? $model : null,
            temperature: is_int($temperature) || is_float($temperature) ? (float) $temperature : null,
            seed: is_int($seed) ? $seed : null,
            generateBudgetSeconds: (is_int($budget) || is_float($budget)) && $budget > 0 ? (float) $budget : null,
        );
    }

    public function generate(AiGenerateRequest $request): AiGenerateResponse
    {
        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->asJson()
                ->post('/v1/generate', $request->toArray());
        } catch (ConnectionException $e) {
            throw new ApiException(
                ErrorCode::AiServiceUnavailable,
                AiGeneratorClient::UNAVAILABLE_MESSAGE.': '.$e->getMessage(),
                ['url' => $this->baseUrl]
            );
        } catch (\Throwable $e) {
            throw new ApiException(
                ErrorCode::AiServiceUnavailable,
                'Lỗi kết nối tới AI service: '.$e->getMessage(),
                ['url' => $this->baseUrl]
            );
        }

        if ($response->status() === 503 || $response->status() === 500) {
            throw new ApiException(
                ErrorCode::AiServiceUnavailable,
                'AI service trả về lỗi hệ thống (HTTP '.$response->status().').',
                ['response' => $response->json() ?? $response->body()]
            );
        }

        if ($response->status() === 502) {
            // Contract: error.details.llm_calls carries the failed GENERATE call, which must still
            // be written to llm_call_logs (D09). Same validated collector as for 200 responses.
            /** @var mixed $errorDecoded */
            $errorDecoded = json_decode($response->body(), false);
            $failedCalls = $errorDecoded instanceof \stdClass
                && ($errorDecoded->error ?? null) instanceof \stdClass
                && ($errorDecoded->error->details ?? null) instanceof \stdClass
                ? self::extractValidLlmCalls($errorDecoded->error->details)
                : [];

            throw new ApiException(
                ErrorCode::LlmProviderError,
                GenerationJobError::PROVIDER_ERROR_PREFIX.($response->json('error.message') ?? $response->body()),
                ['response' => $response->json() ?? $response->body()],
                $failedCalls
            );
        }

        if ($response->status() === 422) {
            /** @var array<string, mixed> $details */
            $details = is_array($response->json('error.details')) ? $response->json('error.details') : [];

            throw new ApiException(
                ErrorCode::ValidationFailed,
                'Yêu cầu sinh test không hợp lệ: '.($response->json('error.message') ?? $response->body()),
                $details
            );
        }

        if (! $response->successful()) {
            throw new ApiException(
                ErrorCode::AiServiceUnavailable,
                'AI service thất bại với mã lỗi HTTP '.$response->status().'.',
                ['response' => $response->json() ?? $response->body()]
            );
        }

        $rawBody = $response->body();
        /** @var mixed $rawDecoded */
        $rawDecoded = json_decode($rawBody, false);

        // Tách validate/collect llm_calls hợp lệ độc lập với việc parse test_cases
        $validLlmCalls = self::extractValidLlmCalls($rawDecoded);

        try {
            ResponseContract::jsonStructure($rawBody);

            return AiGenerateResponse::fromJsonString($rawBody);
        } catch (\InvalidArgumentException $e) {
            /** @var array<string, mixed> $jsonDetails */
            $jsonDetails = is_array($response->json()) ? $response->json() : ['raw' => $rawBody];

            throw new ApiException(
                ErrorCode::AiServiceUnavailable,
                'AI service trả về phản hồi 200 thiếu trường bắt buộc theo hợp đồng: '.$e->getMessage(),
                ['response' => $jsonDetails],
                $validLlmCalls
            );
        }
    }

    /**
     * @return list<AiLlmCall>
     */
    public static function extractValidLlmCalls(mixed $data): array
    {
        $validCalls = [];
        $rawCalls = null;

        if ($data instanceof \stdClass && isset($data->llm_calls) && is_array($data->llm_calls)) {
            $rawCalls = $data->llm_calls;
        } elseif (is_array($data) && isset($data['llm_calls']) && is_array($data['llm_calls'])) {
            $rawCalls = $data['llm_calls'];
        }

        if ($rawCalls === null) {
            return [];
        }

        foreach ($rawCalls as $call) {
            try {
                ResponseContract::llmCallJsonStructure($call);
                $callArray = is_object($call) ? json_decode((string) json_encode($call), true) : $call;
                if (is_array($callArray)) {
                    $validCalls[] = AiLlmCall::fromArray($callArray);
                }
            } catch (\Throwable) {
                // Không lưu call có metadata log không hợp lệ
            }
        }

        return $validCalls;
    }
}
