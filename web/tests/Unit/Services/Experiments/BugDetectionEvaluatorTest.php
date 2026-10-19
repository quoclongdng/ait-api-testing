<?php

namespace Tests\Unit\Services\Experiments;

use App\Enums\TestVerdict;
use App\Services\Experiments\BugDetectionEvaluator;
use PHPUnit\Framework\TestCase;

class BugDetectionEvaluatorTest extends TestCase
{
    public function test_case_passing_on_clean_and_failing_on_bug_detects_the_bug(): void
    {
        $evaluator = new BugDetectionEvaluator;

        $detecting = $evaluator->detectingCases(
            [3 => TestVerdict::Pass, 1 => TestVerdict::Pass, 2 => TestVerdict::Pass],
            [3 => TestVerdict::Fail, 1 => TestVerdict::Error, 2 => TestVerdict::Pass],
        );

        $this->assertSame([1, 3], $detecting);
    }

    public function test_false_positive_on_clean_never_counts_as_detection(): void
    {
        $evaluator = new BugDetectionEvaluator;

        $this->assertFalse($evaluator->isDetected(
            [1 => TestVerdict::Fail, 2 => TestVerdict::Error],
            [1 => TestVerdict::Fail, 2 => TestVerdict::Error],
        ));
    }

    public function test_missing_bug_verdict_or_skipped_does_not_detect(): void
    {
        $evaluator = new BugDetectionEvaluator;

        $this->assertFalse($evaluator->isDetected(
            [1 => TestVerdict::Pass, 2 => TestVerdict::Pass],
            [2 => TestVerdict::Skipped],
        ));
        $this->assertFalse($evaluator->isDetected([], []));
    }
}
