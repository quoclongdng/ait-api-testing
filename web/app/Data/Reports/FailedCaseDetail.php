<?php

namespace App\Data\Reports;

/**
 * A FAIL / ERROR result with the assertions that did not hold (M13).
 * Every string here may come from the target or from an LLM: render escaped only.
 */
final readonly class FailedCaseDetail
{
    /**
     * @param  list<array{type: string, expected: string, actual: string, message: string|null}>  $failedAssertions
     */
    public function __construct(
        public int $resultId,
        public int $testCaseId,
        public string $title,
        public string $method,
        public string $path,
        public string $verdict,
        public int $durationMs,
        public ?string $requestMethod,
        public ?string $requestUrl,
        public ?int $responseStatus,
        public ?string $errorMessage,
        public array $failedAssertions,
    ) {}

    /**
     * @return array{
     *     result_id: int, test_case_id: int, title: string, method: string, path: string, verdict: string,
     *     duration_ms: int, request_method: string|null, request_url: string|null, response_status: int|null,
     *     error_message: string|null,
     *     failed_assertions: list<array{type: string, expected: string, actual: string, message: string|null}>
     * }
     */
    public function toArray(): array
    {
        return [
            'result_id' => $this->resultId,
            'test_case_id' => $this->testCaseId,
            'title' => $this->title,
            'method' => $this->method,
            'path' => $this->path,
            'verdict' => $this->verdict,
            'duration_ms' => $this->durationMs,
            'request_method' => $this->requestMethod,
            'request_url' => $this->requestUrl,
            'response_status' => $this->responseStatus,
            'error_message' => $this->errorMessage,
            'failed_assertions' => $this->failedAssertions,
        ];
    }
}
