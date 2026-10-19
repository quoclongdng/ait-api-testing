<?php

namespace Tests\Feature;

use App\Enums\ApiSpecStatus;
use App\Enums\ExperimentStatus;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\TestCaseSource;
use App\Enums\TestCaseStatus;
use App\Enums\TestRunStatus;
use App\Enums\TestVerdict;
use App\Models\ApiSpec;
use App\Models\BusinessRule;
use App\Models\Endpoint;
use App\Models\Experiment;
use App\Models\GenerationJob;
use App\Models\LlmCallLog;
use App\Models\Project;
use App\Models\TestCase as TestCaseModel;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use App\Support\VerdictLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Js;
use Tests\TestCase;

/**
 * Regression tests for the second S14-06 live QA round, group "r3-ui": the M08 filtered empty
 * state, the M10 phone tab label, the remaining English/raw labels, the temperature decimal comma,
 * the shell accent of secondary actions/links/selection states and the empty fingerprint of
 * INVALID cases.
 */
class WebUiQaR3S1406Test extends TestCase
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

    // --- 1. M08 filtered empty state ---------------------------------------------------------------

    public function test_m08_filtered_empty_state_points_at_the_filter(): void
    {
        [$tester, $project, $spec] = $this->ownedSuite();
        $endpoint = Endpoint::factory()->create(['api_spec_id' => $spec->id]);
        BusinessRule::factory()->create(['project_id' => $project->id, 'endpoint_id' => $endpoint->id, 'code' => 'BR-001']);

        foreach (['?search=qa2zzz', '?endpoint_id=global'] as $query) {
            $html = $this->html("/projects/{$project->id}/business-rules{$query}", $tester);
            $this->assertStringContainsString('data-testid="business-rules-empty-filtered"', $html);
            $this->assertStringContainsString('Không có quy tắc nào khớp bộ lọc', $html);
            $this->assertStringContainsString('xóa lọc', $html);
            $this->assertStringNotContainsString('Chưa có quy tắc nghiệp vụ nào', $html);
            $this->assertStringNotContainsString('Thêm quy tắc đầu tiên', $html);
        }

        // Without a filter, an empty project still invites creating the first rule.
        [$other, $emptyProject] = $this->ownedSuite();
        $this->actingAs($other)->get("/projects/{$emptyProject->id}/business-rules")->assertOk()
            ->assertSee('Chưa có quy tắc nghiệp vụ nào')
            ->assertSee('Thêm quy tắc đầu tiên')
            ->assertDontSee('khớp bộ lọc');
    }

    // --- 2. M10 phone tab label --------------------------------------------------------------------

    public function test_m10_phone_tab_label_keeps_the_meaning_of_the_desktop_label(): void
    {
        [$tester, , , $suite] = $this->ownedSuite();
        $case = TestCaseModel::factory()->invalid()->create([
            'test_suite_id' => $suite->id,
            'validation_errors' => ['Đường dẫn không có trong đặc tả.'],
        ]);

        $html = $this->html("/test-cases/{$case->id}", $tester);

        $this->assertStringContainsString('<span class="sm:hidden">Lỗi kiểm tra</span><span class="hidden sm:inline">Tab 2: Lỗi kiểm tra hợp lệ</span>', $html);
        $this->assertStringNotContainsString('Lỗi hợp lệ', $html);
        // The badge is visual only; the accessible name reads "Lỗi kiểm tra (có lỗi)", not "… Lỗi".
        $this->assertStringContainsString('aria-hidden="true"', $this->tagWithTestId($html, 'tc-errors-badge'));
        $this->assertMatchesRegularExpression('/data-testid="tc-errors-badge"[^>]*>!<\/span><span class="sr-only">\(có lỗi\)<\/span>/', $html);
    }

    // --- 3. English and raw enum labels ------------------------------------------------------------

    public function test_remaining_english_and_raw_enum_labels_are_vietnamese(): void
    {
        [$tester, $project, $spec, $suite] = $this->ownedSuite();
        Endpoint::factory()->count(2)->create(['api_spec_id' => $spec->id]);

        // M07 endpoint filter counter and M09 subtitle.
        $endpoints = $this->html("/projects/{$project->id}/specs/{$spec->id}", $tester);
        $this->assertStringContainsString("x-text=\"filteredEndpoints.length + ' / ' + endpoints.length + ' endpoint'\"", $endpoints);
        $this->assertStringNotContainsString("' endpoints'", $endpoints);
        $suiteHtml = $this->html("/suites/{$suite->id}", $tester);
        $this->assertStringContainsString('· 2 endpoint · Tạo bởi', $suiteHtml);
        $this->assertStringNotContainsString('endpoints ·', $suiteHtml);

        // M10 LLM log: provider display name like M02.
        $case = TestCaseModel::factory()->create(['test_suite_id' => $suite->id, 'source' => TestCaseSource::Llm]);
        LlmCallLog::factory()->create([
            'generation_job_id' => $case->generation_job_id,
            'test_case_id' => $case->id,
            'provider' => 'openai_compatible',
            'model' => 'qwen2.5-coder:7b',
        ]);
        $caseHtml = $this->html("/test-cases/{$case->id}", $tester);
        $this->assertMatchesRegularExpression('/data-testid="llm-log-provider-model"[^>]*>\s*OpenAI-compatible \/ qwen2\.5-coder:7b\s*</', $caseHtml);
        $this->assertStringNotContainsString('openai_compatible /', $caseHtml);

        // M13 verdict chart and "Tỉ lệ" spelling.
        $run = $this->finishedRun($suite, $tester);
        $report = $this->html("/runs/{$run->id}/report", $tester);
        $this->assertStringContainsString('Tỉ lệ đạt', $report);
        $this->assertStringNotContainsString('Tỷ lệ', $report);
        foreach (['PASS' => 'Đạt (PASS)', 'FAIL' => 'Không đạt (FAIL)', 'ERROR' => 'Lỗi (ERROR)', 'SKIPPED' => 'Bỏ qua (SKIPPED)'] as $code => $label) {
            $this->assertMatchesRegularExpression('/<span title="'.preg_quote($label, '/').'" class="[^"]*">'.preg_quote($label, '/').'<\/span>/', $report);
            $this->assertDoesNotMatchRegularExpression('/tracking-wider[^"]*">'.$code.'<\/span>/', $report);
        }

        // M12 counters, filter options, live region and per-case badge.
        $show = $this->html("/runs/{$run->id}", $tester);
        $this->assertMatchesRegularExpression('/data-testid="run-count-label-pass">Đạt \(PASS\)<\/span>/', $show);
        $this->assertMatchesRegularExpression('/data-testid="run-count-label-skipped">Bỏ qua \(SKIPPED\)<\/span>/', $show);
        $this->assertStringContainsString('<option value="FAIL">Không đạt (FAIL)</option>', $show);
        $this->assertStringNotContainsString('<option value="FAIL">FAIL</option>', $show);
        $this->assertStringContainsString('x-text="verdictLabels[result.verdict] || result.verdict"', $show);
        // The Alpine config carries the labels, encoded like Js::from() encodes them.
        $this->assertSame('Lỗi (ERROR)', VerdictLabel::all()['ERROR']);
        $encoded = substr(Js::from(VerdictLabel::all())->toHtml(), strlen("JSON.parse('"), -strlen("')"));
        $this->assertStringContainsString($encoded, $show);

        // M09 recent runs.
        $this->assertStringContainsString('1 Đạt</span>', $this->html("/suites/{$suite->id}", $tester));
    }

    public function test_html_export_uses_the_same_spelling_of_ti_le(): void
    {
        [$tester, , , $suite] = $this->ownedSuite();
        $run = $this->finishedRun($suite, $tester);
        TestResult::factory()->create(['test_run_id' => $run->id, 'verdict' => TestVerdict::Pass]);

        $this->actingAs($tester)->get("/api/v1/runs/{$run->id}/export?format=html")->assertOk()
            ->assertSee('Tỉ lệ đạt')
            ->assertDontSee('Tỷ lệ');
    }

    // --- 4. Temperature with a decimal comma -------------------------------------------------------

    public function test_temperature_uses_the_vietnamese_decimal_comma(): void
    {
        [$tester, $project, $spec, $suite] = $this->ownedSuite();
        $job = GenerationJob::factory()->create([
            'project_id' => $project->id,
            'api_spec_id' => $spec->id,
            'test_suite_id' => $suite->id,
            'strategy' => GenerationStrategy::Llm,
            'status' => GenerationJobStatus::Succeeded,
            'params' => ['techniques' => ['HAPPY_PATH'], 'temperature' => 0.2, 'seed' => 42],
            'created_by' => $tester->id,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        $this->assertMatchesRegularExpression('/<dt class="text-slate-400">Nhiệt độ \(temperature\)<\/dt>\s*<dd class="font-mono text-white">0,2<\/dd>/', $this->html("/generation-jobs/{$job->id}", $tester));

        $experiment = Experiment::factory()->create([
            'status' => ExperimentStatus::Completed,
            'config' => ['model' => 'qwen2.5-coder:7b', 'seed' => 42, 'temperature' => 0.2, 'repetitions' => 3],
        ]);
        $this->actingAs($tester)->get("/experiments/{$experiment->id}")->assertOk()
            ->assertSee('· Nhiệt độ 0,2 ·')
            ->assertDontSee('Nhiệt độ 0.2');
        $this->actingAs($tester)->get('/experiments')->assertOk()
            ->assertSee('· Nhiệt độ: 0,2')
            ->assertDontSee('Nhiệt độ: 0.2');

        $case = TestCaseModel::factory()->create(['test_suite_id' => $suite->id, 'source' => TestCaseSource::Llm]);
        LlmCallLog::factory()->create(['generation_job_id' => $case->generation_job_id, 'test_case_id' => $case->id, 'temperature' => 0.2]);
        $this->assertStringContainsString('data-testid="llm-log-temperature">0,2</strong>', $this->html("/test-cases/{$case->id}", $tester));
    }

    // --- 5. Secondary actions, links and selection states follow the shell -------------------------

    public function test_secondary_actions_links_and_selection_states_follow_the_shell_accent(): void
    {
        [$tester, $project, $spec, $suite] = $this->ownedSuite();
        $admin = User::factory()->admin()->create();
        Endpoint::factory()->create(['api_spec_id' => $spec->id]);
        BusinessRule::factory()->create(['project_id' => $project->id]);
        TestCaseModel::factory()->create(['test_suite_id' => $suite->id, 'status' => TestCaseStatus::Valid]);
        $run = $this->finishedRun($suite, $tester);
        $job = GenerationJob::factory()->create([
            'project_id' => $project->id,
            'api_spec_id' => $spec->id,
            'test_suite_id' => $suite->id,
            'status' => GenerationJobStatus::Succeeded,
            'created_by' => $tester->id,
        ]);
        TestCaseModel::factory()->create(['test_suite_id' => $suite->id, 'generation_job_id' => $job->id]);
        Experiment::factory()->create(['status' => ExperimentStatus::Completed]);

        $checks = [
            ['/projects', 'project-edit-button'],
            ["/projects/{$project->id}/business-rules", 'business-rule-edit-button'],
            ["/projects/{$project->id}/suites", 'suite-detail-link'],
            ["/suites/{$suite->id}", 'bulk-approve-button'],
            ["/suites/{$suite->id}", 'test-case-open-link'],
            ["/suites/{$suite->id}", 'suite-run-report-link'],
            ["/runs/{$run->id}", 'run-report-link'],
            ["/suites/{$suite->id}/runs", 'run-results-link'],
            ["/generation-jobs/{$job->id}", 'generated-case-link'],
            ['/experiments', 'experiment-view-link'],
            // Review of r3: M06 "Xem endpoint", M12 "Chi tiết/Thu gọn" toggle label.
            ["/projects/{$project->id}/specs", 'spec-endpoints-link'],
            ["/runs/{$run->id}", 'run-result-toggle-label'],
        ];
        foreach ($checks as [$uri, $testId]) {
            $testerTag = $this->tagWithTestId($this->html($uri, $tester), $testId);
            $this->assertStringContainsString('text-teal-300', $testerTag, "{$testId} on {$uri}");
            $this->assertStringNotContainsString('indigo', $testerTag, "{$testId} on {$uri}");
            $this->assertStringContainsString('indigo', $this->tagWithTestId($this->html($uri, $admin), $testId), "{$testId} on {$uri} (admin)");
        }

        // M10 "Sinh bởi" link.
        $generated = TestCaseModel::query()->where('generation_job_id', $job->id)->firstOrFail();
        $link = fn (User $user): string => preg_match('/<a id="tc-generation-job-link"[^>]*>/', $this->html("/test-cases/{$generated->id}", $user), $m) === 1 ? $m[0] : '';
        $this->assertStringContainsString('text-teal-300', $link($tester));
        $this->assertStringContainsString('text-indigo-400', $link($admin));

        // M11 checkboxes (techniques, endpoint "select all") follow the shell like M09/M15 (review of r3).
        foreach (['technique-checkbox', 'endpoint-select-all'] as $testId) {
            $testerTag = $this->tagWithTestId($this->html("/suites/{$suite->id}/generation-jobs/create", $tester), $testId);
            $this->assertStringContainsString('text-teal-600', $testerTag, $testId);
            $this->assertStringNotContainsString('text-indigo-600', $testerTag, $testId);
            $this->assertStringContainsString('text-indigo-500', $this->tagWithTestId($this->html("/suites/{$suite->id}/generation-jobs/create", $admin), $testId), "{$testId} (admin)");
        }

        // M07 selected endpoint card and M12 pressed verdict filter.
        $endpoints = $this->html("/projects/{$project->id}/specs/{$spec->id}", $tester);
        $this->assertStringContainsString('ring-teal-500\/30', $endpoints);
        $this->assertStringNotContainsString('bg-indigo-600/15 border-indigo-500/50', $endpoints);
        $this->assertStringContainsString('ring-2 ring-teal-400', $this->html("/runs/{$run->id}", $tester));
        $this->assertStringNotContainsString("'ring-2 ring-indigo-400'", $this->html("/runs/{$run->id}", $tester));
        $this->assertStringContainsString('ring-2 ring-indigo-400', $this->html("/runs/{$run->id}", $admin));
    }

    // --- 6. Empty fingerprint of INVALID cases -----------------------------------------------------

    public function test_invalid_case_without_fingerprint_shows_a_placeholder(): void
    {
        [$tester, , , $suite] = $this->ownedSuite();
        $invalid = TestCaseModel::factory()->invalid()->create(['test_suite_id' => $suite->id]);
        $valid = TestCaseModel::factory()->create(['test_suite_id' => $suite->id, 'fingerprint' => str_repeat('ab', 32)]);

        $html = $this->html("/test-cases/{$invalid->id}", $tester);
        $this->assertStringContainsString('Dấu vân tay (fingerprint, SHA-256):', $html);
        $this->assertMatchesRegularExpression('/data-testid="tc-fingerprint-empty"[^>]*>chưa có \(chỉ tính cho test case hợp lệ\)</', $html);

        $this->assertStringNotContainsString('tc-fingerprint-empty', $this->html("/test-cases/{$valid->id}", $tester));

        $list = $this->html("/suites/{$suite->id}", $tester);
        $this->assertStringContainsString('data-testid="tc-row-fingerprint-empty"', $list);
        // The column header uses the same wording as M10 (review of r3).
        $this->assertStringContainsString('Tiêu đề & dấu vân tay (fingerprint)</th>', $list);
        $this->assertStringNotContainsString('Fingerprint</th>', $list);
        $this->assertStringContainsString(substr(str_repeat('ab', 32), 0, 16).'...', $list);
    }
}
