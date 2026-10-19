<?php

namespace Tests\Unit\Support;

use App\Enums\ExperimentStatus;
use App\Enums\TestRunStatus;
use App\Enums\TestVerdict;
use App\Enums\UserRole;
use App\Models\User;
use App\Support\AccentClasses;
use App\Support\LlmDisplay;
use App\Support\VariantLabel;
use App\Support\VerdictLabel;
use PHPUnit\Framework\TestCase;

/**
 * S14-06 QA: display helpers of the UI (status wording, benchmark variant, shell accent classes).
 */
class DisplayLabelsTest extends TestCase
{
    public function test_experiment_status_labels_are_vietnamese_and_match_test_run_wording(): void
    {
        $this->assertSame('Đã lập kế hoạch', ExperimentStatus::Planned->label());
        $this->assertSame('Đang chạy', ExperimentStatus::Running->label());
        $this->assertSame('Hoàn thành', ExperimentStatus::Completed->label());
        $this->assertSame('Thất bại', ExperimentStatus::Failed->label());
        // COMPLETED reads the same for runs and experiments.
        $this->assertSame(TestRunStatus::Completed->label(), ExperimentStatus::Completed->label());
    }

    public function test_variant_label_translates_only_the_clean_variant(): void
    {
        $this->assertSame('Bản sạch (clean)', VariantLabel::display('clean'));
        $this->assertSame('BUG-032', VariantLabel::display('BUG-032'));
        $this->assertSame('—', VariantLabel::display(null));
        $this->assertSame('—', VariantLabel::display(''));
    }

    public function test_accent_classes_follow_the_shell_of_the_role(): void
    {
        $tester = new User(['role' => UserRole::Tester]);
        $admin = new User(['role' => UserRole::Admin]);

        $this->assertStringContainsString('bg-teal-700', AccentClasses::primaryButton($tester));
        $this->assertStringNotContainsString('indigo', AccentClasses::primaryButton($tester));
        $this->assertStringContainsString('bg-indigo-600', AccentClasses::primaryButton($admin));
        $this->assertStringContainsString('bg-teal-700', AccentClasses::headerCta($tester));
        $this->assertStringContainsString('border-teal-400', AccentClasses::activeTab($tester));
        $this->assertStringContainsString('border-indigo-500', AccentClasses::activeTab($admin));
        $this->assertStringContainsString('bg-teal-700', AccentClasses::activeSegment($tester));
        // A guest (no user) gets the tester accent, like RoleLayout::accent().
        $this->assertSame(AccentClasses::primaryButton($tester), AccentClasses::primaryButton(null));
        // The admin page-header CTA keeps the exact S14-04 style shared by the admin screens.
        $this->assertSame(
            'inline-flex shrink-0 items-center justify-center gap-2 self-start whitespace-nowrap rounded-xl bg-indigo-600 px-4 py-2.5 text-xs font-semibold text-white shadow-lg shadow-indigo-600/25 transition hover:bg-indigo-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-900 sm:self-auto',
            AccentClasses::headerCta($admin),
        );
    }

    public function test_secondary_accent_classes_follow_the_shell_of_the_role(): void
    {
        $tester = new User(['role' => UserRole::Tester]);
        $admin = new User(['role' => UserRole::Admin]);

        foreach (['secondaryButton', 'link', 'selectedCard', 'pressedRing', 'checkbox', 'accentText'] as $method) {
            $testerClasses = AccentClasses::$method($tester);
            $this->assertStringContainsString('teal', $testerClasses, $method);
            $this->assertStringNotContainsString('indigo', $testerClasses, $method);
            $this->assertStringContainsString('indigo', AccentClasses::$method($admin), $method);
            $this->assertSame($testerClasses, AccentClasses::$method(null), $method);
        }
    }

    public function test_llm_provider_and_temperature_are_displayed_in_vietnamese(): void
    {
        $this->assertSame('OpenAI-compatible', LlmDisplay::provider('openai_compatible'));
        $this->assertSame('giả lập', LlmDisplay::provider('fake'));
        $this->assertSame('đã tắt', LlmDisplay::provider('disabled'));
        $this->assertSame('ollama', LlmDisplay::provider('ollama'));
        $this->assertNull(LlmDisplay::provider(null));
        $this->assertNull(LlmDisplay::provider(''));

        $this->assertSame('0,2', LlmDisplay::temperature(0.2));
        $this->assertSame('0,2', LlmDisplay::temperature('0.2'));
        $this->assertSame('0,05', LlmDisplay::temperature(0.05));
        $this->assertSame('1', LlmDisplay::temperature(1));
        $this->assertSame('0', LlmDisplay::temperature(0));
        $this->assertSame('1,5', LlmDisplay::temperature(1.5));
        $this->assertSame('—', LlmDisplay::temperature(null));
        $this->assertSame('—', LlmDisplay::temperature('abc'));
    }

    public function test_verdict_labels_put_vietnamese_first(): void
    {
        $this->assertSame('Đạt (PASS)', VerdictLabel::display(TestVerdict::Pass));
        $this->assertSame('Không đạt (FAIL)', VerdictLabel::display('FAIL'));
        $this->assertSame('Lỗi (ERROR)', VerdictLabel::display('ERROR'));
        $this->assertSame('Bỏ qua (SKIPPED)', VerdictLabel::display('SKIPPED'));
        $this->assertSame('Đạt', VerdictLabel::short('PASS'));
        $this->assertSame('OTHER', VerdictLabel::display('OTHER'));
        $this->assertSame('—', VerdictLabel::display(null));
        $this->assertSame(['PASS', 'FAIL', 'ERROR', 'SKIPPED'], array_keys(VerdictLabel::all()));
    }

    public function test_tailwind_scans_the_accent_class_file(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3).'/resources/css/app.css');

        $this->assertStringContainsString("@source '../../app/Support/AccentClasses.php';", $css);
    }
}
