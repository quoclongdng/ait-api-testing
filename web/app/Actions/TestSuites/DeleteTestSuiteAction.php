<?php

namespace App\Actions\TestSuites;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\TestSuite;

class DeleteTestSuiteAction
{
    /**
     * test_results are insert-only and reference test_cases with ON DELETE RESTRICT,
     * so a suite that has already been run cannot be deleted.
     *
     * @throws ApiException
     */
    public function execute(TestSuite $suite): void
    {
        if ($suite->testRuns()->exists()) {
            throw new ApiException(
                ErrorCode::Conflict,
                'Không thể xóa bộ kiểm thử đã có lần chạy (test run).'
            );
        }

        $suite->delete();
    }
}
