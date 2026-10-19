<?php

namespace App\Services\Experiments;

use App\Enums\TestVerdict;

/**
 * Decision D14: a seeded bug is detected when at least one test case is PASS on the clean
 * variant and FAIL or ERROR on the bug variant. Cases that are not PASS on the clean variant
 * (false positives, errors) can never count as detecting a bug.
 */
final class BugDetectionEvaluator
{
    /**
     * @param  array<int, TestVerdict>  $cleanVerdicts  test case id => verdict on the clean variant
     * @param  array<int, TestVerdict>  $bugVerdicts  test case id => verdict on the bug variant
     * @return list<int> ids of detecting test cases, in ascending order
     */
    public function detectingCases(array $cleanVerdicts, array $bugVerdicts): array
    {
        $detecting = [];
        foreach ($cleanVerdicts as $caseId => $cleanVerdict) {
            if ($cleanVerdict !== TestVerdict::Pass) {
                continue;
            }

            $bugVerdict = $bugVerdicts[$caseId] ?? null;
            if ($bugVerdict === TestVerdict::Fail || $bugVerdict === TestVerdict::Error) {
                $detecting[] = (int) $caseId;
            }
        }

        sort($detecting);

        return $detecting;
    }

    /**
     * @param  array<int, TestVerdict>  $cleanVerdicts
     * @param  array<int, TestVerdict>  $bugVerdicts
     */
    public function isDetected(array $cleanVerdicts, array $bugVerdicts): bool
    {
        return $this->detectingCases($cleanVerdicts, $bugVerdicts) !== [];
    }
}
