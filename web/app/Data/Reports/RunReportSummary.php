<?php

namespace App\Data\Reports;

/**
 * Aggregated numbers of one test run (M13).
 */
final readonly class RunReportSummary
{
    /** Note shown with a totals-only run (M12, M13 and the HTML/CSV export). */
    public const TOTALS_ONLY_NOTE = 'Lần chạy này chỉ lưu số liệu tổng (bản ghi thực nghiệm chính thức), không có kết quả từng test case.';

    /** Shown instead of the response-time distribution of a totals-only run, whose durations are unknown. */
    public const TOTALS_ONLY_TIMING_NOTE = 'Không có số liệu thời gian: bản ghi chỉ lưu số liệu tổng nên không biết thời gian phản hồi của từng ca.';

    /**
     * @param  array{lt_50: int, between_50_200: int, gt_200: int}  $responseTimeBuckets
     * @param  bool  $totalsOnly  Built from run.totals because the run stores no per-case result
     *                            (imported experiment record): counts are real, durations unknown.
     */
    public function __construct(
        public int $total,
        public int $pass,
        public int $fail,
        public int $error,
        public int $skipped,
        public ?float $passRate,
        public ?int $wallDurationMs,
        public int $totalDurationMs,
        public ?int $averageDurationMs,
        public array $responseTimeBuckets,
        public bool $totalsOnly = false,
    ) {}

    /** Number of cases that were actually executed (not SKIPPED). */
    public function executed(): int
    {
        return $this->total - $this->skipped;
    }

    public function percentOfTotal(int $count): float
    {
        return $this->total === 0 ? 0.0 : round($count * 100 / $this->total, 1);
    }

    /**
     * @return array{
     *     total: int, pass: int, fail: int, error: int, skipped: int, executed: int,
     *     pass_rate: float|null, wall_duration_ms: int|null, total_duration_ms: int,
     *     average_duration_ms: int|null,
     *     response_time_buckets: array{lt_50: int, between_50_200: int, gt_200: int},
     *     results_available: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'pass' => $this->pass,
            'fail' => $this->fail,
            'error' => $this->error,
            'skipped' => $this->skipped,
            'executed' => $this->executed(),
            'pass_rate' => $this->passRate,
            'wall_duration_ms' => $this->wallDurationMs,
            'total_duration_ms' => $this->totalDurationMs,
            'average_duration_ms' => $this->averageDurationMs,
            'response_time_buckets' => $this->responseTimeBuckets,
            'results_available' => ! $this->totalsOnly,
        ];
    }
}
