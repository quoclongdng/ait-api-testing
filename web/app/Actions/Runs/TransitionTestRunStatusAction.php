<?php

namespace App\Actions\Runs;

use App\Enums\ErrorCode;
use App\Enums\TestRunStatus;
use App\Exceptions\ApiException;
use App\Models\TestRun;
use Illuminate\Support\Carbon;

/**
 * Enforces the TestRun status state machine (docs/DESIGN.md §4.3).
 *
 * Allowed transitions:
 * - QUEUED  -> RUNNING | CANCELLED
 * - RUNNING -> COMPLETED | FAILED | CANCELLED
 *
 * Any other transition (including a "transition" to the current status) throws
 * ApiException(INVALID_STATE_TRANSITION, HTTP 409). Persisted runs are updated with a
 * compare-and-set on the current status so a concurrent cancel and a worker finishing
 * the run cannot overwrite each other.
 */
class TransitionTestRunStatusAction
{
    /**
     * @var array<string, list<TestRunStatus>>
     */
    private const ALLOWED_TRANSITIONS = [
        'QUEUED' => [TestRunStatus::Running, TestRunStatus::Cancelled],
        'RUNNING' => [TestRunStatus::Completed, TestRunStatus::Failed, TestRunStatus::Cancelled],
    ];

    /**
     * @param  array<string, mixed>  $attributes  Extra columns written atomically with the status (e.g. totals).
     *
     * @throws ApiException
     */
    public function execute(TestRun $testRun, TestRunStatus $newStatus, array $attributes = []): TestRun
    {
        $current = $testRun->status;
        $this->assertAllowed($current, $newStatus);

        $now = Carbon::now();
        $changes = $attributes;
        $changes['status'] = $newStatus;

        if ($newStatus === TestRunStatus::Running && $testRun->started_at === null) {
            $changes['started_at'] = $now;
        }

        if (self::isTerminal($newStatus)) {
            $changes['finished_at'] = $now;
        }

        if (! $testRun->exists) {
            $testRun->forceFill($changes);

            return $testRun;
        }

        // Compare-and-set: only move from the status this instance observed.
        $probe = (new TestRun)->forceFill($changes);
        $dbChanges = array_intersect_key($probe->getAttributes(), $changes);
        $dbChanges['updated_at'] = $probe->fromDateTime($now);

        $updated = TestRun::query()
            ->whereKey($testRun->getKey())
            ->where('status', $current->value)
            ->update($dbChanges);

        if ($updated === 0) {
            $testRun->refresh();
            $this->assertAllowed($testRun->status, $newStatus);

            // The status changed concurrently but the new transition is still legal: retry once.
            return $this->execute($testRun, $newStatus, $attributes);
        }

        return $testRun->refresh();
    }

    public static function isTerminal(TestRunStatus $status): bool
    {
        return in_array($status, [TestRunStatus::Completed, TestRunStatus::Failed, TestRunStatus::Cancelled], true);
    }

    /**
     * @throws ApiException
     */
    private function assertAllowed(TestRunStatus $current, TestRunStatus $newStatus): void
    {
        $allowed = self::ALLOWED_TRANSITIONS[$current->value] ?? [];

        if (in_array($newStatus, $allowed, true)) {
            return;
        }

        throw new ApiException(
            ErrorCode::InvalidStateTransition,
            "Không thể chuyển trạng thái lần chạy từ {$current->value} sang {$newStatus->value}.",
            [
                'current_status' => $current->value,
                'target_status' => $newStatus->value,
                'allowed_next_statuses' => array_map(fn (TestRunStatus $s): string => $s->value, $allowed),
            ]
        );
    }
}
