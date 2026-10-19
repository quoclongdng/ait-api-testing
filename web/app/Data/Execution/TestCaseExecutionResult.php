<?php

namespace App\Data\Execution;

use App\Data\TargetResponse;
use App\Support\SensitiveDataMasker;

/**
 * Aggregated execution result of a complete Test DSL case.
 */
final readonly class TestCaseExecutionResult
{
    /** @var array<string, mixed> */
    public array $variables;

    public int $durationMs;

    /**
     * @param  list<StepExecutionResult>  $stepResults
     * @param  array<string, mixed>  $initialVariables
     * @param  array<string, mixed>  $finalVariables
     * @param  array<string, mixed>  $dsl
     */
    public function __construct(
        public bool $isSuccess,
        public array $stepResults,
        public array $initialVariables = [],
        public array $finalVariables = [],
        public int $totalDurationMs = 0,
        public ?string $errorMessage = null,
        public array $dsl = [],
        public string $baseUrl = '',
    ) {
        $this->variables = $finalVariables;
        $this->durationMs = $totalDurationMs;
    }

    public function lastStep(): ?StepExecutionResult
    {
        return $this->stepResults === [] ? null : $this->stepResults[array_key_last($this->stepResults)];
    }

    public function lastResponse(): ?TargetResponse
    {
        return $this->lastStep()?->response;
    }

    public function stepCount(): int
    {
        return count($this->stepResults);
    }

    /**
     * Convert step requests to JSON log array.
     *
     * @return list<array<string, mixed>>
     */
    public function requestLogs(): array
    {
        return array_map(fn (StepExecutionResult $step) => [
            'step_index' => $step->stepIndex,
            'step_name' => $step->stepName,
            'method' => $step->request->method,
            'url' => SensitiveDataMasker::maskUrl($step->request->url),
            'headers' => StepExecutionResult::maskHeaders($step->request->headers),
            'query' => SensitiveDataMasker::maskQuery($step->request->query),
            'body' => $step->request->body,
        ], $this->stepResults);
    }

    /**
     * Convert step responses to JSON log array.
     *
     * @return list<array<string, mixed>|null>
     */
    public function responseLogs(): array
    {
        return array_map(fn (StepExecutionResult $step) => $step->response !== null ? [
            'step_index' => $step->stepIndex,
            'step_name' => $step->stepName,
            'status' => $step->response->status,
            'headers' => StepExecutionResult::maskHeaders($step->response->headers),
            'body' => StepExecutionResult::truncateBody($step->response->body),
            'duration_ms' => $step->response->durationMs,
        ] : null, $this->stepResults);
    }
}
