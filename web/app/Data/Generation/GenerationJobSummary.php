<?php

namespace App\Data\Generation;

/**
 * Read model for M11 progress & statistics (GET /api/v1/generation-jobs/{id} → data.summary).
 *
 * @phpstan-type Counts array{generated: int, valid: int, invalid: int, duplicates: int, repaired: int}
 * @phpstan-type EndpointRow array{endpoint_id: int, method: string, path: string, generated: int, valid: int, invalid: int, duplicates: int, repaired: int, has_error: bool, errors: list<string>, note: string|null}
 * @phpstan-type LlmUsage array{calls: int, generate_calls: int, repair_calls: int, failed_calls: int, prompt_tokens: int, completion_tokens: int, total_tokens: int, total_latency_ms: int, avg_latency_ms: int, models: list<string>}
 */
final readonly class GenerationJobSummary
{
    /**
     * @param  Counts  $counts
     * @param  list<EndpointRow>  $endpoints
     * @param  LlmUsage  $llm
     * @param  list<string>  $jobErrors
     */
    public function __construct(
        public string $status,
        public string $strategy,
        public bool $isTerminal,
        public int $progressPercent,
        public string $progressLabel,
        public array $counts,
        public array $endpoints,
        public array $llm,
        public ?string $errorMessage,
        public array $jobErrors,
        public ?int $durationSeconds,
        public bool $replayed = false,
    ) {}

    /**
     * @return array{status: string, strategy: string, is_terminal: bool, progress_percent: int, progress_label: string, counts: Counts, endpoints: list<EndpointRow>, llm: LlmUsage, error_message: string|null, job_errors: list<string>, duration_seconds: int|null, replayed: bool}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'strategy' => $this->strategy,
            'is_terminal' => $this->isTerminal,
            'progress_percent' => $this->progressPercent,
            'progress_label' => $this->progressLabel,
            'counts' => $this->counts,
            'endpoints' => $this->endpoints,
            'llm' => $this->llm,
            'error_message' => $this->errorMessage,
            'job_errors' => $this->jobErrors,
            'duration_seconds' => $this->durationSeconds,
            'replayed' => $this->replayed,
        ];
    }
}
