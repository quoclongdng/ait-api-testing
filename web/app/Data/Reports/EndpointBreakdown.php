<?php

namespace App\Data\Reports;

/**
 * Verdict counts of one endpoint inside a run report (M13).
 */
final readonly class EndpointBreakdown
{
    /**
     * @param  list<int>  $statusCodes  HTTP status codes actually received from the target, sorted
     */
    public function __construct(
        public int $endpointId,
        public string $method,
        public string $path,
        public int $total,
        public int $pass,
        public int $fail,
        public int $error,
        public int $skipped,
        public array $statusCodes,
    ) {}

    /**
     * @return array{endpoint_id: int, method: string, path: string, total: int, pass: int, fail: int, error: int, skipped: int, status_codes: list<int>}
     */
    public function toArray(): array
    {
        return [
            'endpoint_id' => $this->endpointId,
            'method' => $this->method,
            'path' => $this->path,
            'total' => $this->total,
            'pass' => $this->pass,
            'fail' => $this->fail,
            'error' => $this->error,
            'skipped' => $this->skipped,
            'status_codes' => $this->statusCodes,
        ];
    }
}
