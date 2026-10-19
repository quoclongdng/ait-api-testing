<?php

namespace Tests\Unit\Services\Runs;

use App\Actions\Runs\TransitionTestRunStatusAction;
use App\Enums\ErrorCode;
use App\Enums\TestRunStatus;
use App\Exceptions\ApiException;
use App\Models\TestRun;
use Tests\TestCase;

class TransitionTestRunStatusActionTest extends TestCase
{
    private TransitionTestRunStatusAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new TransitionTestRunStatusAction;
    }

    public function test_it_transitions_from_queued_to_running_and_sets_started_at(): void
    {
        $run = new TestRun([
            'status' => TestRunStatus::Queued,
        ]);

        $this->assertNull($run->started_at);
        $updated = $this->action->execute($run, TestRunStatus::Running);

        $this->assertSame(TestRunStatus::Running, $updated->status);
        $this->assertNotNull($updated->started_at);
        $this->assertNull($updated->finished_at);
    }

    public function test_it_transitions_from_running_to_completed_and_sets_finished_at(): void
    {
        $run = new TestRun([
            'status' => TestRunStatus::Running,
            'started_at' => now()->subMinutes(2),
        ]);

        $this->assertNull($run->finished_at);
        $updated = $this->action->execute($run, TestRunStatus::Completed);

        $this->assertSame(TestRunStatus::Completed, $updated->status);
        $this->assertNotNull($updated->finished_at);
    }

    public function test_it_transitions_from_queued_to_cancelled(): void
    {
        $run = new TestRun([
            'status' => TestRunStatus::Queued,
        ]);

        $updated = $this->action->execute($run, TestRunStatus::Cancelled);

        $this->assertSame(TestRunStatus::Cancelled, $updated->status);
        $this->assertNotNull($updated->finished_at);
    }

    public function test_it_transitions_from_running_to_cancelled(): void
    {
        $run = new TestRun([
            'status' => TestRunStatus::Running,
            'started_at' => now()->subMinute(),
        ]);

        $updated = $this->action->execute($run, TestRunStatus::Cancelled);

        $this->assertSame(TestRunStatus::Cancelled, $updated->status);
        $this->assertNotNull($updated->finished_at);
    }

    public function test_it_transitions_from_running_to_failed(): void
    {
        $run = new TestRun([
            'status' => TestRunStatus::Running,
            'started_at' => now()->subMinute(),
        ]);

        $updated = $this->action->execute($run, TestRunStatus::Failed);

        $this->assertSame(TestRunStatus::Failed, $updated->status);
        $this->assertNotNull($updated->finished_at);
    }

    public function test_it_rejects_transition_from_queued_to_failed(): void
    {
        $run = new TestRun([
            'status' => TestRunStatus::Queued,
        ]);

        $this->expectInvalidTransition($run, TestRunStatus::Failed);
    }

    public function test_it_rejects_transition_to_the_same_status(): void
    {
        foreach (TestRunStatus::cases() as $status) {
            $this->expectInvalidTransition(new TestRun(['status' => $status]), $status);
        }
    }

    public function test_it_rejects_every_transition_out_of_terminal_states(): void
    {
        foreach ([TestRunStatus::Completed, TestRunStatus::Failed, TestRunStatus::Cancelled] as $terminal) {
            foreach (TestRunStatus::cases() as $target) {
                $this->expectInvalidTransition(new TestRun(['status' => $terminal]), $target);
            }
        }
    }

    public function test_it_rejects_transition_from_completed_to_running(): void
    {
        $run = new TestRun([
            'status' => TestRunStatus::Completed,
        ]);

        $this->expectInvalidTransition($run, TestRunStatus::Running);
    }

    public function test_it_rejects_transition_from_cancelled_to_completed(): void
    {
        $run = new TestRun([
            'status' => TestRunStatus::Cancelled,
        ]);

        $this->expectInvalidTransition($run, TestRunStatus::Completed);
    }

    public function test_it_rejects_transition_from_failed_to_completed(): void
    {
        $run = new TestRun([
            'status' => TestRunStatus::Failed,
        ]);

        $this->expectInvalidTransition($run, TestRunStatus::Completed);
    }

    public function test_it_rejects_transition_from_queued_to_completed_directly(): void
    {
        $run = new TestRun([
            'status' => TestRunStatus::Queued,
        ]);

        $this->expectInvalidTransition($run, TestRunStatus::Completed);
    }

    private function expectInvalidTransition(TestRun $run, TestRunStatus $target): void
    {
        try {
            $this->action->execute($run, $target);
            $this->fail("Expected ApiException INVALID_STATE_TRANSITION was not thrown for transition to {$target->value}");
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::InvalidStateTransition, $e->errorCode);
            $this->assertSame('INVALID_STATE_TRANSITION', $e->errorCode->value);
            $this->assertSame(409, $e->errorCode->httpStatus());
            $this->assertSame($run->status->value, $e->details['current_status']);
        }
    }
}
