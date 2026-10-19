<?php

namespace App\Actions\Runs;

use App\Enums\TestCaseStatus;
use App\Models\TestRun;

/**
 * Case titles/endpoints shown next to each result on screen M12, plus the number of
 * runnable (VALID/APPROVED) cases used to estimate progress before the run totals are known.
 */
class ListRunCaseSummariesAction
{
    /**
     * @return array{cases: array<int, array{id: int, title: string, technique: string, method: string, path: string}>, runnable: int}
     */
    public function execute(TestRun $run): array
    {
        $cases = [];
        $runnable = 0;

        // Only the columns shown on M12: the DSL jsonb is not needed for titles and counts.
        $testCases = $run->testSuite->testCases()
            ->with('endpoint:id,method,path')
            ->orderBy('id')
            ->get(['id', 'test_suite_id', 'endpoint_id', 'title', 'technique', 'status']);

        foreach ($testCases as $case) {
            $cases[$case->id] = [
                'id' => $case->id,
                'title' => $case->title,
                'technique' => $case->technique->value,
                'method' => $case->endpoint->method->value,
                'path' => $case->endpoint->path,
            ];
            if (in_array($case->status, [TestCaseStatus::Valid, TestCaseStatus::Approved], true)) {
                $runnable++;
            }
        }

        return ['cases' => $cases, 'runnable' => $runnable];
    }
}
