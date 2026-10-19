<?php

namespace App\Data\Execution;

use App\Data\TargetRequest;
use App\Data\TargetResponse;
use App\Support\SensitiveDataMasker;

/**
 * Result of executing a single step of a Test DSL case.
 */
final readonly class StepExecutionResult
{
    /**
     * @param  array<string, mixed>  $extractedVariables
     * @param  list<AssertionResult>  $assertionResults
     */
    public function __construct(
        public int $stepIndex,
        public string $stepName,
        public TargetRequest $request,
        public ?TargetResponse $response = null,
        public array $extractedVariables = [],
        public int $durationMs = 0,
        public ?string $errorMessage = null,
        public bool $isSuccess = true,
        public array $assertionResults = [],
    ) {}

    public const MAX_RESPONSE_LOG_BYTES = 65536; // 64 KB

    public function isSuccess(): bool
    {
        if (! $this->isSuccess || $this->errorMessage !== null || $this->response === null) {
            return false;
        }

        foreach ($this->assertionResults as $result) {
            if (! $result->passed) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function requestLog(): array
    {
        return [
            'method' => $this->request->method,
            'url' => SensitiveDataMasker::maskUrl($this->request->url),
            'headers' => self::maskHeaders($this->request->headers),
            'query' => SensitiveDataMasker::maskQuery($this->request->query),
            'body' => $this->request->body,
        ];
    }

    /** @return array<string, mixed>|null */
    public function responseLog(): ?array
    {
        if ($this->response === null) {
            return null;
        }

        return [
            'status' => $this->response->status,
            'headers' => self::maskHeaders($this->response->headers),
            'body' => self::truncateBody($this->response->body),
            'duration_ms' => $this->response->durationMs,
        ];
    }

    /**
     * Mask credential headers (Authorization, Cookie, Set-Cookie, API keys, tokens...) with '***'.
     *
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    public static function maskHeaders(array $headers): array
    {
        /** @var array<string, mixed> */
        return SensitiveDataMasker::maskHeaders($headers);
    }

    /**
     * Truncate response body at 64 KB for logging purposes while preserving valid UTF-8 character boundaries.
     */
    public static function truncateBody(string $body, int $maxBytes = self::MAX_RESPONSE_LOG_BYTES): string
    {
        if ($maxBytes < 0) {
            throw new \InvalidArgumentException('Response log byte limit must be nonnegative.');
        }
        // JSONB cannot store NUL, and JSON cannot encode malformed UTF-8.
        // Base64 makes binary responses safe to store without changing the raw response.
        if (! mb_check_encoding($body, 'UTF-8') || str_contains($body, "\0")) {
            $body = '[base64] '.base64_encode($body);
        }
        if (strlen($body) > $maxBytes) {
            return mb_strcut($body, 0, $maxBytes, 'UTF-8');
        }

        return $body;
    }

    /**
     * Convert to an array for JSON logging in test_results.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'step_index' => $this->stepIndex,
            'step_name' => $this->stepName,
            'request' => [
                'method' => $this->request->method,
                'url' => SensitiveDataMasker::maskUrl($this->request->url),
                'headers' => self::maskHeaders($this->request->headers),
                'query' => SensitiveDataMasker::maskQuery($this->request->query),
                'body' => $this->request->body,
                'timeout_ms' => $this->request->timeoutMs,
            ],
            'response' => $this->response !== null ? [
                'status' => $this->response->status,
                'headers' => self::maskHeaders($this->response->headers),
                'body' => self::truncateBody($this->response->body),
                'duration_ms' => $this->response->durationMs,
            ] : null,
            'extracted_variables' => $this->extractedVariables,
            'duration_ms' => $this->durationMs,
            'error_message' => $this->errorMessage,
            'is_success' => $this->isSuccess,
            'assertion_results' => array_map(
                fn (AssertionResult $r) => $r->toArray(),
                $this->assertionResults
            ),
        ];
    }
}
