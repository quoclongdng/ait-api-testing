<?php

namespace Tests\Unit\Support;

use App\Enums\TestCaseStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Support\AccentClasses;
use App\Support\EnumTooltip;
use PHPUnit\Framework\TestCase;

/**
 * S14-06 live QA r4 (group r5-ui): accent classes of pagination, breadcrumbs, focus rings and
 * accent labels, and the Vietnamese tooltip of enum badges.
 */
class AccentAndTooltipRound5Test extends TestCase
{
    public function test_new_accent_classes_follow_the_shell_of_the_role(): void
    {
        $tester = new User(['role' => UserRole::Tester]);
        $admin = new User(['role' => UserRole::Admin]);

        $methods = ['focusRing', 'focusBorder', 'focusVisibleRing', 'currentPage', 'label', 'breadcrumbLink',
            'codeBadge', 'iconTile', 'statCard', 'statCardLabel', 'statCardValue'];
        foreach ($methods as $method) {
            $testerClasses = AccentClasses::$method($tester);
            $this->assertStringContainsString('teal', $testerClasses, $method);
            $this->assertStringNotContainsString('indigo', $testerClasses, $method);
            $this->assertStringContainsString('indigo', AccentClasses::$method($admin), $method);
            $this->assertStringNotContainsString('teal', AccentClasses::$method($admin), $method);
        }

        $this->assertSame('focus:ring-teal-500', AccentClasses::focusRing($tester));
        $this->assertSame('focus-visible:ring-indigo-500', AccentClasses::focusVisibleRing($admin));
        // The ADMIN pagination keeps its former current-page colours.
        $this->assertSame('bg-indigo-600 border-indigo-500 text-white', AccentClasses::currentPage($admin));
        $this->assertSame('bg-teal-700 border-teal-500 text-white', AccentClasses::currentPage($tester));
    }

    public function test_enum_tooltip_puts_the_raw_value_in_parentheses(): void
    {
        $this->assertSame('Hoàn thành (COMPLETED)', EnumTooltip::for('Hoàn thành', 'COMPLETED'));
        $this->assertSame('Hợp lệ (VALID)', EnumTooltip::for(TestCaseStatus::Valid->label(), TestCaseStatus::Valid));
        // Already "label (VALUE)": not repeated.
        $this->assertSame('Đạt (PASS)', EnumTooltip::for('Đạt (PASS)', 'PASS'));
        // No distinct label: the raw value alone; no value: the label alone.
        $this->assertSame('CUSTOM', EnumTooltip::for('CUSTOM', 'CUSTOM'));
        $this->assertSame('CUSTOM', EnumTooltip::for(null, 'CUSTOM'));
        $this->assertSame('Không xác định', EnumTooltip::for('Không xác định', null));
    }
}
