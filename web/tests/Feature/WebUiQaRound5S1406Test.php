<?php

namespace Tests\Feature;

use App\Enums\ApiSpecStatus;
use App\Enums\ExperimentStatus;
use App\Enums\TestCaseSource;
use App\Enums\TestCaseStatus;
use App\Enums\TestRunStatus;
use App\Enums\TestVerdict;
use App\Models\ApiSpec;
use App\Models\BusinessRule;
use App\Models\Experiment;
use App\Models\LlmCallLog;
use App\Models\Project;
use App\Models\TestCase as TestCaseModel;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression tests for the fourth S14-06 live QA round, group "r5-ui": the M08/M03 filtered empty
 * state outside the scrolling table, the M06 spec history filename column, the TESTER shell accent
 * of pagination, breadcrumbs, focus rings and accent labels, and the remaining English/raw-enum
 * labels of M12, M02, M04 and the badge tooltips.
 */
class WebUiQaRound5S1406Test extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Project, 2: ApiSpec, 3: TestSuite}
     */
    private function ownedSuite(?User $owner = null): array
    {
        $owner ??= User::factory()->tester()->create();
        $project = Project::factory()->for($owner, 'owner')->create();
        $spec = ApiSpec::factory()->for($project)->create(['status' => ApiSpecStatus::Parsed]);
        $suite = TestSuite::factory()->create([
            'project_id' => $project->id,
            'api_spec_id' => $spec->id,
            'created_by' => $owner->id,
        ]);

        return [$owner, $project, $spec, $suite];
    }

    private function html(string $uri, User $user): string
    {
        return (string) $this->actingAs($user)->get($uri)->assertOk()->getContent();
    }

    /** Opening tag of the element carrying data-testid="$testId". */
    private function tagWithTestId(string $html, string $testId): string
    {
        if (preg_match('/<[a-z0-9]+\b[^>]*data-testid="'.preg_quote($testId, '/').'"[^>]*>/s', $html, $match) !== 1) {
            $this->fail("{$testId} not found");
        }

        return $match[0];
    }

    /** Markup of the horizontally scrolling table region(s) of a page. */
    private function scrollRegions(string $html): string
    {
        preg_match_all('/<div tabindex="0" role="region" aria-label="Bảng dữ liệu, cuộn ngang để xem thêm".*?<\/table>/s', $html, $matches);

        return implode("\n", $matches[0]);
    }

    private function finishedRun(TestSuite $suite, User $user): TestRun
    {
        return TestRun::factory()->create([
            'test_suite_id' => $suite->id,
            'triggered_by' => $user->id,
            'status' => TestRunStatus::Completed,
            'totals' => ['total' => 2, 'pass' => 1, 'fail' => 1, 'error' => 0, 'skipped' => 0],
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    // --- 1. M08 and M03 filtered empty state outside the scrolling table ---------------------------

    public function test_m08_filtered_empty_state_is_a_full_width_card_outside_the_table(): void
    {
        [$tester, $project] = $this->ownedSuite();
        BusinessRule::factory()->create(['project_id' => $project->id, 'code' => 'BR-001']);

        $html = $this->html("/projects/{$project->id}/business-rules?search=qa2zzz", $tester);

        $this->assertStringContainsString('data-testid="business-rules-empty-state"', $html);
        $this->assertStringContainsString('data-testid="business-rules-empty-filtered"', $html);
        // No table and no colspan cell: the message cannot be clipped by the table's sideways scroll.
        $this->assertStringNotContainsString('data-testid="business-rules-table"', $html);
        $this->assertStringNotContainsString('colspan="4"', $html);
        $this->assertSame('', $this->scrollRegions($html));
        $this->assertMatchesRegularExpression('/Hãy đổi điều kiện lọc hoặc\s*<a href="[^"]*business-rules"[^>]*>xóa lọc<\/a>/', $html);

        // Rules exist and match: the table is shown, without the empty card.
        $listed = $this->html("/projects/{$project->id}/business-rules", $tester);
        $this->assertStringContainsString('data-testid="business-rules-table"', $listed);
        $this->assertStringNotContainsString('business-rules-empty-state', $listed);

        // An empty project still invites creating the first rule, in the same card.
        [$other, $emptyProject] = $this->ownedSuite();
        $empty = $this->html("/projects/{$emptyProject->id}/business-rules", $other);
        $this->assertStringContainsString('data-testid="business-rules-empty-state"', $empty);
        $this->assertStringContainsString('Thêm quy tắc đầu tiên', $empty);
    }

    public function test_m03_filtered_empty_state_is_a_full_width_card_outside_the_table(): void
    {
        $admin = User::factory()->admin()->create();

        $html = $this->html('/admin/users?search=qa4zzz', $admin);

        $this->assertStringContainsString('data-testid="users-empty-state"', $html);
        $this->assertStringContainsString('data-testid="users-empty-filtered"', $html);
        $this->assertStringContainsString('Không tìm thấy người dùng nào phù hợp với bộ lọc', $html);
        $this->assertMatchesRegularExpression('/Hãy đổi điều kiện lọc hoặc\s*<a href="[^"]*\/admin\/users"[^>]*>xóa lọc<\/a>/', $html);
        $this->assertStringNotContainsString('colspan="6"', $html);
        $this->assertSame('', $this->scrollRegions($html));

        $listed = $this->html('/admin/users', $admin);
        $this->assertStringNotContainsString('users-empty-state', $listed);
        $this->assertStringContainsString('data-testid="user-email-cell"', $listed);
    }

    // --- 2. M06 spec history filename column -------------------------------------------------------

    public function test_m06_filename_keeps_a_fixed_width_with_ellipsis_and_tooltip(): void
    {
        [$tester, $project] = $this->ownedSuite();
        ApiSpec::factory()->for($project)->create([
            'status' => ApiSpecStatus::ParseFailed,
            'source_filename' => 'specification.yaml',
            'parse_errors' => [['message' => 'bad']],
            'checksum' => str_repeat('c', 64),
        ]);
        ApiSpec::factory()->for($project)->create([
            'status' => ApiSpecStatus::Parsed,
            'version_label' => 'v1.1.0-release-candidate',
        ]);

        $html = $this->html("/projects/{$project->id}/specs", $tester);

        $tag = $this->tagWithTestId($html, 'spec-source-filename');
        $this->assertStringContainsString('w-40', $tag);
        $this->assertStringContainsString('truncate', $tag);
        $this->assertStringContainsString('title="', $tag);
        // overflow-wrap:anywhere let the auto table layout squeeze the column to ~3 characters.
        $this->assertDoesNotMatchRegularExpression('/<td[^>]*\[overflow-wrap:anywhere\][^>]*>\s*<span data-testid="spec-source-filename"/', $html);
        $this->assertStringContainsString('title="specification.yaml">specification.yaml</span>', $html);
        // Narrower cells and a short checksum (full value in the tooltip) leave room for "Thao tác".
        $this->assertStringContainsString('title="'.str_repeat('c', 64).'"', $html);
        $this->assertStringContainsString(str_repeat('c', 8).'…', $html);
        $this->assertStringNotContainsString(str_repeat('c', 8).'...', $html);
        // The table fits the 960 px content area at 1280 px (sidebar 16rem, padding 2 x 2rem): a 60rem
        // minimum forced a sideways scroll that hid "Thao tác", and a long version label kept on one
        // line widened the first column the same way.
        $table = $this->tagWithTestId($html, 'spec-versions-table');
        $this->assertStringContainsString('min-w-[52rem]', $table);
        $this->assertStringNotContainsString('min-w-[60rem]', $table);
        $label = $this->tagWithTestId($html, 'spec-version-label');
        $this->assertStringContainsString('max-w-[10rem]', $label);
        $this->assertStringContainsString('break-words', $label);
        $this->assertDoesNotMatchRegularExpression('/<td class="[^"]*whitespace-nowrap[^"]*">\s*<div data-testid="spec-version-label"/', $html);
        $this->assertStringContainsString('v1.1.0-release-candidate', $html);
    }

    // --- 3. TESTER shell accent --------------------------------------------------------------------

    public function test_pagination_current_page_follows_the_shell_accent(): void
    {
        [$tester, $project] = $this->ownedSuite();
        $admin = User::factory()->admin()->create();
        BusinessRule::factory()->count(25)->create(['project_id' => $project->id]);

        $current = function (User $user) use ($project): string {
            $html = $this->html("/projects/{$project->id}/business-rules", $user);
            if (preg_match('/<span aria-current="page" class="([^"]*)"/', $html, $match) !== 1) {
                $this->fail('current page not found');
            }

            return $match[1];
        };

        $testerClass = $current($tester);
        $this->assertStringContainsString('bg-teal-700', $testerClass);
        $this->assertStringNotContainsString('indigo', $testerClass);
        $this->assertStringContainsString('bg-indigo-600', $current($admin));

        $testerHtml = $this->html("/projects/{$project->id}/business-rules", $tester);
        $this->assertMatchesRegularExpression('/aria-label="Đến trang 2" class="[^"]*focus:ring-teal-500/', $testerHtml);
    }

    public function test_breadcrumbs_badges_stat_cards_and_labels_follow_the_shell_accent(): void
    {
        [$tester, $project, $spec, $suite] = $this->ownedSuite();
        $admin = User::factory()->admin()->create();
        BusinessRule::factory()->create(['project_id' => $project->id, 'code' => 'BR-001']);
        $case = TestCaseModel::factory()->create(['test_suite_id' => $suite->id, 'status' => TestCaseStatus::Approved]);
        $run = $this->finishedRun($suite, $tester);
        TestResult::factory()->create(['test_run_id' => $run->id, 'test_case_id' => $case->id, 'verdict' => TestVerdict::Pass]);

        $checks = [
            ["/projects/{$project->id}/business-rules", 'breadcrumb-current', 'text-teal-300', 'text-indigo-300'],
            ["/projects/{$project->id}/specs", 'breadcrumb-current', 'text-teal-300', 'text-indigo-300'],
            ["/projects/{$project->id}/specs/{$spec->id}", 'breadcrumb-current', 'text-teal-300', 'text-indigo-300'],
            ["/suites/{$suite->id}", 'suite-stat-approved', 'border-teal-500/30', 'border-indigo-500/30'],
            ["/runs/{$run->id}/report", 'report-stat-pass-rate', 'border-teal-500/30', 'border-indigo-500/30'],
            ["/runs/{$run->id}/report", 'report-endpoint-method', 'text-teal-300', 'text-indigo-300'],
            ["/test-cases/{$case->id}", 'dsl-step-title', 'text-teal-300', 'text-indigo-300'],
            ["/runs/{$run->id}", 'run-step-request-heading', 'text-teal-300', 'text-indigo-300'],
        ];
        foreach ($checks as [$uri, $testId, $teal, $indigo]) {
            $testerTag = $this->tagWithTestId($this->html($uri, $tester), $testId);
            $this->assertStringContainsString($teal, $testerTag, "{$testId} on {$uri}");
            $this->assertStringNotContainsString('indigo', $testerTag, "{$testId} on {$uri}");
            $this->assertStringContainsString($indigo, $this->tagWithTestId($this->html($uri, $admin), $testId), "{$testId} on {$uri} (admin)");
        }

        // M08 rule-code badge.
        $badge = fn (User $user): string => preg_match('/data-testid="business-rule-code"[^>]*>\s*<span class="([^"]*)"/', $this->html("/projects/{$project->id}/business-rules", $user), $m) === 1 ? $m[1] : '';
        $this->assertStringContainsString('text-teal-300', $badge($tester));
        $this->assertStringNotContainsString('indigo', $badge($tester));
        $this->assertStringContainsString('text-indigo-300', $badge($admin));
    }

    public function test_focus_rings_of_shared_screens_follow_the_shell_accent(): void
    {
        [$tester, $project, $spec, $suite] = $this->ownedSuite();
        $admin = User::factory()->admin()->create();
        BusinessRule::factory()->create(['project_id' => $project->id]);
        $case = TestCaseModel::factory()->create(['test_suite_id' => $suite->id]);
        $run = $this->finishedRun($suite, $tester);
        Experiment::factory()->create(['status' => ExperimentStatus::Completed]);

        $uris = [
            '/projects',
            "/projects/{$project->id}/business-rules",
            "/projects/{$project->id}/specs",
            "/projects/{$project->id}/specs/{$spec->id}",
            "/projects/{$project->id}/suites",
            "/suites/{$suite->id}",
            "/suites/{$suite->id}/runs",
            "/suites/{$suite->id}/generation-jobs/create",
            "/test-cases/{$case->id}",
            "/runs/{$run->id}",
            "/runs/{$run->id}/report",
            '/experiments',
        ];
        foreach ($uris as $uri) {
            $testerHtml = $this->html($uri, $tester);
            foreach (['focus:ring-indigo-500', 'focus-visible:ring-indigo-500', 'focus:border-indigo-500'] as $class) {
                $this->assertStringNotContainsString($class, $testerHtml, "{$class} on {$uri}");
            }
            $this->assertMatchesRegularExpression('/focus(-visible)?:ring-teal-500/', $testerHtml, $uri);
            $this->assertMatchesRegularExpression('/focus(-visible)?:ring-indigo-500/', $this->html($uri, $admin), "{$uri} (admin)");
        }
    }

    // --- 4. Remaining English and raw-enum labels --------------------------------------------------

    public function test_m12_run_panel_uses_vietnamese_status_names(): void
    {
        [$tester, , , $suite] = $this->ownedSuite();

        $html = $this->html("/suites/{$suite->id}/runs", $tester);

        $this->assertStringContainsString('Hợp lệ (VALID)</span> hoặc <span', $html);
        $this->assertStringContainsString('Đã duyệt (APPROVED)</span> được thực thi', $html);
        $this->assertStringContainsString('Bộ kiểm thử chưa có test case hợp lệ hoặc đã duyệt để chạy.', $html);
        $this->assertStringNotContainsString('VALID/APPROVED', $html);
        $this->assertStringNotContainsString('">VALID</span>', $html);
    }

    public function test_m02_dashboards_use_vietnamese_pass_rate_labels(): void
    {
        $tester = User::factory()->tester()->create();
        $admin = User::factory()->admin()->create();

        $testerHtml = $this->html('/dashboard', $tester);
        $this->assertStringContainsString('Tỉ lệ đạt 7 ngày', $testerHtml);
        $this->assertStringContainsString('Do bộ kiểm tra assertion tất định quyết định', $testerHtml);
        $this->assertStringNotContainsString('Tỉ lệ PASS', $testerHtml);
        $this->assertStringNotContainsString('Assertion Engine', $testerHtml);

        $adminHtml = $this->html('/dashboard', $admin);
        $this->assertStringContainsString('lần chạy · tỉ lệ đạt', $adminHtml);
        $this->assertStringNotContainsString('tỉ lệ PASS', $adminHtml);
    }

    public function test_m04_llm_labels_are_vietnamese(): void
    {
        $admin = User::factory()->admin()->create();

        $html = $this->html('/admin/allowed-targets', $admin);

        foreach (['Nhà cung cấp LLM (provider)', 'Mô hình (model)', 'Nhiệt độ (temperature) / seed mặc định', 'Thời gian chờ (timeout)'] as $label) {
            $this->assertStringContainsString('<span class="text-slate-400">'.$label.'</span>', $html);
        }
        foreach (['Model', 'Temperature / Seed mặc định', 'LLM provider', 'Timeout'] as $old) {
            $this->assertStringNotContainsString('<span class="text-slate-400">'.$old.'</span>', $html);
        }
    }

    public function test_badge_tooltips_show_the_vietnamese_label_with_the_raw_value(): void
    {
        [$tester, , , $suite] = $this->ownedSuite();
        $run = $this->finishedRun($suite, $tester);
        $case = TestCaseModel::factory()->create([
            'test_suite_id' => $suite->id,
            'status' => TestCaseStatus::Valid,
            'source' => TestCaseSource::Llm,
        ]);
        LlmCallLog::factory()->create([
            'generation_job_id' => $case->generation_job_id,
            'test_case_id' => $case->id,
            'provider' => 'openai_compatible',
        ]);
        TestResult::factory()->create(['test_run_id' => $run->id, 'test_case_id' => $case->id, 'verdict' => TestVerdict::Pass]);

        // M12 run list badge (x-run-status-badge) and M02 recent runs (x-status-badge).
        $this->assertStringContainsString('title="Hoàn thành (COMPLETED)"', $this->html("/suites/{$suite->id}/runs", $tester));
        $this->assertStringNotContainsString('title="COMPLETED"', $this->html('/dashboard', $tester));

        // M09 case status, M10 status and provider, M13 verdict.
        $suiteHtml = $this->html("/suites/{$suite->id}", $tester);
        $this->assertStringContainsString('title="Hợp lệ (VALID)"', $suiteHtml);
        $this->assertStringNotContainsString('title="VALID"', $suiteHtml);
        $caseHtml = $this->html("/test-cases/{$case->id}", $tester);
        $this->assertStringContainsString('title="Nhà cung cấp LLM: OpenAI-compatible (openai_compatible)"', $caseHtml);
        $this->assertStringNotContainsString('title="openai_compatible"', $caseHtml);
        $this->assertStringContainsString('title="Đạt (PASS)"', $this->html("/runs/{$run->id}/report", $tester));

        // M12 run detail: the live badge keeps the label in its Alpine tooltip too.
        $show = $this->html("/runs/{$run->id}", $tester);
        $this->assertStringContainsString("statusLabels[run.status] + ' (' + run.status + ')'", $show);
        $this->assertStringNotContainsString(':title="run.status"', $show);
    }
}
