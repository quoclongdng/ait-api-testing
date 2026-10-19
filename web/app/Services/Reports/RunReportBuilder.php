<?php

namespace App\Services\Reports;

use App\Data\Reports\EndpointBreakdown;
use App\Data\Reports\FailedCaseDetail;
use App\Data\Reports\RunReport;
use App\Data\Reports\RunReportSummary;
use App\Enums\TestVerdict;
use App\Models\TestResult;
use App\Models\TestRun;
use Illuminate\Support\Collection;

/**
 * Aggregates the stored test_results of a run into the M13 report (S8-01).
 * Read-only: verdicts come from the AssertionEngine (D03), nothing is recomputed here.
 */
class RunReportBuilder
{
    /**
     * Shortest wall time shown for a totals-only (imported) run: below it the whole-second
     * timestamps of the record (a gap of 0 or 1 s) cannot tell the duration (QA S14-06).
     */
    public const TOTALS_ONLY_MIN_WALL_MS = 2000;

    public function build(TestRun $run): RunReport
    {
        $run->loadMissing(['testSuite.project', 'triggerer']);

        /** @var Collection<int, TestResult> $results */
        $results = $run->testResults()
            ->with('testCase.endpoint')
            ->orderBy('id')
            ->get();

        return new RunReport(
            run: $run,
            results: $results,
            summary: $this->summarise($run, $results),
            endpoints: $this->breakdownByEndpoint($results),
            failures: $this->failures($results),
        );
    }

    /**
     * Formats an assertion value (expected/actual) for humans and CSV/HTML output.
     */
    public static function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            default => (string) json_encode(
                $value,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
            ),
        };
    }

    public static function responseStatus(TestResult $result): ?int
    {
        $status = $result->response_log['status'] ?? null;

        return is_int($status) ? $status : null;
    }

    /**
     * @param  Collection<int, TestResult>  $results
     */
    private function summarise(TestRun $run, Collection $results): RunReportSummary
    {
        if ($results->isEmpty()) {
            $totalsOnly = self::totalsOnlySummary($run);
            if ($totalsOnly instanceof RunReportSummary) {
                return $totalsOnly;
            }
        }

        $counts = $this->countVerdicts($results);
        $executed = $results->filter(fn (TestResult $r): bool => $r->verdict !== TestVerdict::Skipped);
        $totalDuration = (int) $executed->sum('duration_ms');
        $executedCount = $counts['total'] - $counts['skipped'];

        $wall = null;
        if ($run->started_at !== null && $run->finished_at !== null) {
            // Cases run one after another, so the wall time is never below their summed durations;
            // runs recorded before started_at/finished_at kept microseconds showed "0 ms" (QA S13-03).
            $wall = max($totalDuration, (int) round($run->started_at->diffInMilliseconds($run->finished_at)));
        }

        $buckets = ['lt_50' => 0, 'between_50_200' => 0, 'gt_200' => 0];
        foreach ($executed as $result) {
            $bucket = match (true) {
                $result->duration_ms < 50 => 'lt_50',
                $result->duration_ms <= 200 => 'between_50_200',
                default => 'gt_200',
            };
            $buckets[$bucket]++;
        }

        return new RunReportSummary(
            total: $counts['total'],
            pass: $counts['pass'],
            fail: $counts['fail'],
            error: $counts['error'],
            skipped: $counts['skipped'],
            passRate: $counts['total'] === 0 ? null : round($counts['pass'] * 100 / $counts['total'], 1),
            wallDurationMs: $wall,
            totalDurationMs: $totalDuration,
            averageDurationMs: $executedCount === 0 ? null : (int) round($totalDuration / $executedCount),
            responseTimeBuckets: $buckets,
        );
    }

    /**
     * Summary of a finished run that stores only its totals and no per-case result: the official
     * experiment records of the demo showcase (ADR-0007 addendum 2026-10-13). The numbers are those
     * of run.totals, the same as the suite run list; per-case durations are unknown. Null when the
     * run has no usable totals (a run that simply has no result yet).
     */
    public static function totalsOnlySummary(TestRun $run): ?RunReportSummary
    {
        $totals = $run->totals;
        if (! is_array($totals) || ! is_numeric($totals['total'] ?? null) || (int) $totals['total'] <= 0) {
            return null;
        }

        $count = static fn (string $key): int => is_numeric($totals[$key] ?? null) ? max(0, (int) $totals[$key]) : 0;
        $total = (int) $totals['total'];
        $wall = $run->started_at !== null && $run->finished_at !== null
            ? (int) round($run->started_at->diffInMilliseconds($run->finished_at))
            : null;
        // The timestamps of an imported record are whole seconds, so a gap of 0 or 1 s says nothing
        // about the real duration (anything from 0 to about 2 s): both are "không có số liệu"
        // instead of "0 ms" for one and a seemingly measured "1,0 giây" for the other (QA S14-06).
        if ($wall !== null && $wall < self::TOTALS_ONLY_MIN_WALL_MS) {
            $wall = null;
        }

        return new RunReportSummary(
            total: $total,
            pass: $count('pass'),
            fail: $count('fail'),
            error: $count('error'),
            skipped: $count('skipped'),
            passRate: round($count('pass') * 100 / $total, 1),
            wallDurationMs: $wall,
            totalDurationMs: 0,
            averageDurationMs: null,
            responseTimeBuckets: ['lt_50' => 0, 'between_50_200' => 0, 'gt_200' => 0],
            totalsOnly: true,
        );
    }

    /**
     * @param  Collection<int, TestResult>  $results
     * @return array{total: int, pass: int, fail: int, error: int, skipped: int}
     */
    private function countVerdicts(Collection $results): array
    {
        $counts = ['total' => $results->count(), 'pass' => 0, 'fail' => 0, 'error' => 0, 'skipped' => 0];
        foreach ($results as $result) {
            $counts[strtolower($result->verdict->value)]++;
        }

        return $counts;
    }

    /**
     * @param  Collection<int, TestResult>  $results
     * @return list<EndpointBreakdown>
     */
    private function breakdownByEndpoint(Collection $results): array
    {
        $breakdown = [];
        foreach ($results->groupBy(fn (TestResult $r): int => $r->testCase->endpoint_id) as $group) {
            /** @var Collection<int, TestResult> $group */
            $endpoint = $group->first()?->testCase->endpoint;
            if ($endpoint === null) {
                continue;
            }

            $counts = $this->countVerdicts($group);
            $statuses = $group
                ->map(fn (TestResult $r): ?int => self::responseStatus($r))
                ->filter(fn (?int $s): bool => $s !== null)
                ->unique()
                ->sort()
                ->values()
                ->all();

            /** @var list<int> $statuses */
            $breakdown[] = new EndpointBreakdown(
                endpointId: $endpoint->id,
                method: $endpoint->method->value,
                path: $endpoint->path,
                total: $counts['total'],
                pass: $counts['pass'],
                fail: $counts['fail'],
                error: $counts['error'],
                skipped: $counts['skipped'],
                statusCodes: $statuses,
            );
        }

        usort($breakdown, fn (EndpointBreakdown $a, EndpointBreakdown $b): int => [$a->path, $a->method] <=> [$b->path, $b->method]);

        return $breakdown;
    }

    /**
     * @param  Collection<int, TestResult>  $results
     * @return list<FailedCaseDetail>
     */
    private function failures(Collection $results): array
    {
        $failures = [];
        foreach ($results as $result) {
            if (! in_array($result->verdict, [TestVerdict::Fail, TestVerdict::Error], true)) {
                continue;
            }

            $failedAssertions = [];
            foreach ($result->assertion_results as $assertion) {
                if (! is_array($assertion) || ($assertion['passed'] ?? false) === true) {
                    continue;
                }
                $message = $assertion['message'] ?? null;
                $failedAssertions[] = [
                    'type' => self::stringify($assertion['type'] ?? 'unknown'),
                    'expected' => self::stringify($assertion['expected'] ?? null),
                    'actual' => self::stringify($assertion['actual'] ?? null),
                    'message' => $message === null ? null : self::stringify($message),
                ];
            }

            $method = $result->request_log['method'] ?? null;
            $url = $result->request_log['url'] ?? null;

            $failures[] = new FailedCaseDetail(
                resultId: $result->id,
                testCaseId: $result->test_case_id,
                title: $result->testCase->title,
                method: $result->testCase->endpoint->method->value,
                path: $result->testCase->endpoint->path,
                verdict: $result->verdict->value,
                durationMs: $result->duration_ms,
                requestMethod: is_string($method) ? $method : null,
                requestUrl: is_string($url) ? $url : null,
                responseStatus: self::responseStatus($result),
                errorMessage: $result->error_message,
                failedAssertions: $failedAssertions,
            );
        }

        return $failures;
    }
}
