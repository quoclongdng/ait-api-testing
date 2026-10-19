<?php

namespace Tests\Unit\Actions;

use App\Actions\TestCases\TransitionTestCaseStatusAction;
use App\Enums\TestCaseStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exhaustive check of the approve/archive part of the §4.2 state machine.
 */
class TestCaseTransitionMatrixTest extends TestCase
{
    /**
     * @return array<string, array{TestCaseStatus, TestCaseStatus, bool}>
     */
    public static function transitions(): array
    {
        $allowed = [
            'VALID->APPROVED',
            'VALID->ARCHIVED',
            'APPROVED->ARCHIVED',
        ];

        $cases = [];
        foreach (TestCaseStatus::cases() as $from) {
            foreach (TestCaseStatus::cases() as $to) {
                $key = "{$from->value}->{$to->value}";
                $cases[$key] = [$from, $to, in_array($key, $allowed, true)];
            }
        }

        return $cases;
    }

    #[DataProvider('transitions')]
    public function test_can_transition_matches_state_machine(TestCaseStatus $from, TestCaseStatus $to, bool $expected): void
    {
        $this->assertSame($expected, TransitionTestCaseStatusAction::canTransition($from, $to));
    }
}
