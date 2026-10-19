<?php

namespace App\Actions\TestCases;

use App\Enums\ErrorCode;
use App\Enums\TestCaseStatus;
use App\Exceptions\ApiException;
use App\Models\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * Approve / archive a test case following the state machine in docs/DESIGN.md §4.2.
 */
class TransitionTestCaseStatusAction
{
    public static function canTransition(TestCaseStatus $from, TestCaseStatus $to): bool
    {
        $allowedFrom = match ($to) {
            TestCaseStatus::Approved => [TestCaseStatus::Valid],
            TestCaseStatus::Archived => [TestCaseStatus::Valid, TestCaseStatus::Approved],
            default => [],
        };

        return in_array($from, $allowedFrom, true);
    }

    /**
     * @throws ApiException
     */
    public function execute(TestCase $testCase, TestCaseStatus $target): TestCase
    {
        return DB::transaction(function () use ($testCase, $target): TestCase {
            // Re-read under a row lock so concurrent approve/archive/edit cannot bypass §4.2.
            $locked = TestCase::query()->lockForUpdate()->findOrFail($testCase->id);

            if (! self::canTransition($locked->status, $target)) {
                throw new ApiException(
                    ErrorCode::InvalidStateTransition,
                    "Không thể chuyển test case từ {$locked->status->value} sang {$target->value}."
                );
            }

            $locked->update(['status' => $target]);

            return $locked->refresh();
        });
    }
}
