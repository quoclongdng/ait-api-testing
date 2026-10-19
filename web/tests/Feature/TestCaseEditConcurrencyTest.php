<?php

namespace Tests\Feature;

use App\Actions\TestCases\UpdateTestCaseDslAction;
use App\Enums\ErrorCode;
use App\Enums\TestCaseStatus;
use App\Exceptions\ApiException;
use App\Models\TestCase as TestCaseModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression (S10-01 audit): the manual DSL edit checked the status of the route-bound (stale)
 * model without a row lock, so an archive committed in between was overwritten (ARCHIVED -> VALID).
 */
class TestCaseEditConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_re_checks_status_on_the_locked_row(): void
    {
        $case = TestCaseModel::factory()->create(['status' => TestCaseStatus::Valid]);
        $stale = TestCaseModel::query()->findOrFail($case->id);

        // A concurrent archive commits after the PATCH loaded the case.
        TestCaseModel::query()->whereKey($case->id)->update(['status' => TestCaseStatus::Archived->value]);

        try {
            app(UpdateTestCaseDslAction::class)->execute($stale, $case->dsl, 'Sửa tay');
            $this->fail('An ARCHIVED case must not be edited back to VALID.');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::InvalidStateTransition, $e->errorCode);
        }

        $this->assertSame(TestCaseStatus::Archived, $case->refresh()->status);
    }
}
