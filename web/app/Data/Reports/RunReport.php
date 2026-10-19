<?php

namespace App\Data\Reports;

use App\Models\TestResult;
use App\Models\TestRun;
use Illuminate\Support\Collection;

/**
 * Everything needed to render or export the report of a test run (M13, S8-01).
 */
final readonly class RunReport
{
    /**
     * @param  Collection<int, TestResult>  $results  ordered by id, with testCase.endpoint loaded
     * @param  list<EndpointBreakdown>  $endpoints
     * @param  list<FailedCaseDetail>  $failures
     */
    public function __construct(
        public TestRun $run,
        public Collection $results,
        public RunReportSummary $summary,
        public array $endpoints,
        public array $failures,
    ) {}

    /**
     * Number of FAIL and ERROR cases shown in the failures header. A totals-only run has no per-case
     * detail, so the count comes from its stored totals instead of the empty list (QA S14-06 round 3:
     * "Ca kiểm thử không đạt (0)" next to FAIL 2).
     */
    public function failureCount(): int
    {
        return $this->summary->totalsOnly
            ? $this->summary->fail + $this->summary->error
            : count($this->failures);
    }
}
