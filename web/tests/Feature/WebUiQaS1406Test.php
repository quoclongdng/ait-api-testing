<?php

namespace Tests\Feature;

use App\Enums\ApiSpecStatus;
use App\Enums\ExperimentStatus;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\HttpMethod;
use App\Enums\TestCaseSource;
use App\Enums\TestCaseStatus;
use App\Enums\TestRunStatus;
use App\Enums\TestVerdict;
use App\Models\ApiSpec;
use App\Models\BenchmarkBug;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Regression tests for the S14-06 live QA findings of group "web-ui-views-a11y" on the production
 * site: 375 px overflow, ARIA tabs, account menu focus, empty states, wide tables, Vietnamese
 * labels and number formats, run start times, status wording, screen titles and the shell accent.
 */
class WebUiQaS1406Test extends TestCase
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

    /** A case whose step has a long path and a json_schema assertion (most RULE HAPPY_PATH cases). */
    private function caseWithSchemaAssertion(TestSuite $suite, TestCaseStatus $status = TestCaseStatus::Valid): TestCaseModel
    {
        $endpoint = Endpoint::factory()->create([
            'api_spec_id' => $suite->api_spec_id,
            'method' => HttpMethod::Get,
            'path' => '/appointments/{appointment_id}',
        ]);

        return TestCaseModel::factory()->create([
            'test_suite_id' => $suite->id,
            'endpoint_id' => $endpoint->id,
            'status' => $status,
            'dsl' => [
                'dsl_version' => '1.0',
                'title' => 'Xem lịch hẹn',
                'endpoint' => ['method' => 'GET', 'path' => '/appointments/{appointment_id}'],
                'technique' => 'HAPPY_PATH',
                'source' => 'RULE',
                'steps' => [[
                    'name' => 'Execute GET /appointments/{appointment_id}',
                    'request' => ['method' => 'GET', 'path' => '/appointments/appointment-booking-0000000001'],
                    'assertions' => [
                        ['type' => 'status', 'equals' => 200],
                        ['type' => 'json_schema', 'schema' => [
                            'type' => 'object',
                            'required' => ['appointment_id', 'customer_name', 'scheduled_at', 'status'],
                            'properties' => ['appointment_id' => ['type' => 'string'], 'status' => ['type' => 'string', 'enum' => ['BOOKED', 'CANCELLED']]],
                        ]],
                    ],
                ]],
            ],
        ]);
    }

    // --- 1. M10 does not scroll horizontally at 375 px ----------------------------------------

    public function test_m10_step_header_path_and_assertions_wrap_instead_of_widening_the_page(): void
    {
        [$tester, , , $suite] = $this->ownedSuite();
        $case = $this->caseWithSchemaAssertion($suite);

        $html = $this->html("/test-cases/{$case->id}", $tester);

        $this->assertStringContainsString('flex-wrap', $this->tagWithTestId($html, 'tc-step-header'));
        $this->assertStringContainsString('min-w-0', $this->tagWithTestId($html, 'tc-step-header'));
        $request = $this->tagWithTestId($html, 'tc-step-request');
        $this->assertStringContainsString('max-w-full', $request);
        $this->assertStringContainsString('break-all', $request);
        // The status chip wraps; the json_schema object is its own horizontally scrollable block.
        $chip = $this->tagWithTestId($html, 'tc-assertion-chip');
        $this->assertStringContainsString('break-all', $chip);
        $this->assertStringContainsString('max-w-full', $chip);
        $this->assertMatchesRegularExpression('/data-testid="tc-assertion-block"[^>]*>\s*<span>json_schema:<\/span>\s*<pre tabindex="0" class="[^"]*max-w-full overflow-x-auto/s', $html);
        $this->assertStringContainsString('Tiêu đề yêu cầu (headers)', (string) file_get_contents(resource_path('views/test-cases/show.blade.php')));
        $this->assertStringNotContainsString('Request Body:', $html);
    }

    /**
     * Review of S14-06: below sm the three tabs (short labels, plus the "Lỗi" and log-count badges of an
     * LLM INVALID case) must not exceed 343 px, so the icons are hidden below sm and the tablist wraps
     * instead of widening the page; the page title and the endpoint path of the header also wrap.
     */
    public function test_m10_tablist_header_and_labels_never_widen_the_page(): void
    {
        [$tester, , , $suite] = $this->ownedSuite();
        $case = $this->caseWithSchemaAssertion($suite, TestCaseStatus::Invalid);
        $case->update([
            'source' => TestCaseSource::Llm,
            'title' => 'HAPPY_PATH GET /appointments/{appointment_id}/reschedule-requests/{request_id}',
            'validation_errors' => ['Đường dẫn không có trong đặc tả.'],
        ]);
        LlmCallLog::factory()->create(['generation_job_id' => $case->generation_job_id, 'test_case_id' => $case->id]);

        $html = $this->html("/test-cases/{$case->id}", $tester);

        $this->assertMatchesRegularExpression('/role="tablist"[^>]*class="flex flex-wrap gap-1 [^"]*"/s', $html);
        $this->assertDoesNotMatchRegularExpression('/role="tablist"[^>]*class="[^"]*space-x-/s', $html);
        foreach (['dsl', 'errors', 'logs'] as $tab) {
            $this->assertMatchesRegularExpression('/id="tab-'.$tab.'"[^>]*>\s*<svg aria-hidden="true" class="hidden h-4 w-4 sm:block"/s', $html);
        }
        $this->assertStringContainsString('[overflow-wrap:anywhere]', $this->tagWithTestId($html, 'tc-title'));
        $this->assertStringContainsString('break-all', $this->tagWithTestId($html, 'tc-endpoint-path'));
        $this->assertMatchesRegularExpression('/<div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-800 pb-3">\s*<h2[^>]*>TRẠNG THÁI KIỂM TRA/', $html);
        // Vietnamese first, technical term in parentheses (§8), like the headers and body labels.
        $this->assertStringContainsString('Kiểm tra kỳ vọng (assertions):', $html);
        $this->assertStringNotContainsString('Assertions (Kiểm tra kỳ vọng)', $html);
    }

    // --- 2. M10 tabs follow the WAI-ARIA tabs pattern ------------------------------------------

    public function test_m10_tabs_have_roving_tabindex_and_arrow_key_navigation(): void
    {
        [$tester, , , $suite] = $this->ownedSuite();
        $case = $this->caseWithSchemaAssertion($suite);

        $html = $this->html("/test-cases/{$case->id}", $tester);

        $this->assertMatchesRegularExpression('/role="tablist"[^>]*@keydown\.arrow-right\.prevent="moveTab\(1\)"[^>]*@keydown\.arrow-left\.prevent="moveTab\(-1\)"[^>]*@keydown\.home\.prevent="moveTab\(\'first\'\)"[^>]*@keydown\.end\.prevent="moveTab\(\'last\'\)"/s', $html);
        foreach (['dsl', 'errors', 'logs'] as $tab) {
            $this->assertMatchesRegularExpression('/id="tab-'.$tab.'"[^>]*:tabindex="activeTab === \''.$tab.'\' \? 0 : -1"/s', $html);
        }
        $this->assertStringContainsString("tabs: ['dsl', 'errors', 'logs']", $html);
        $this->assertStringContainsString("document.getElementById('tab-' + this.activeTab)", $html);
    }

    // --- 3. M10 edit hint is said once ----------------------------------------------------------

    public function test_m10_edit_hint_mentions_re_approval_once(): void
    {
        [$tester, , , $suite] = $this->ownedSuite();
        $case = $this->caseWithSchemaAssertion($suite);

        $html = $this->html("/test-cases/{$case->id}", $tester);

        $this->assertSame(1, substr_count($html, 'được duyệt lại'));
        $this->assertStringContainsString('test case đã duyệt (APPROVED) sau khi sửa phải được duyệt lại.', $html);
        $this->assertStringContainsString('— Chi tiết test case — ', $html);
        $this->assertStringNotContainsString('Chi tiết Test Case', $html);
    }

    // --- 4 + 15. M10 LLM log card shows the seed and formatted counts --------------------------

    public function test_m10_llm_log_shows_seed_and_vietnamese_number_format(): void
    {
        [$tester, , , $suite] = $this->ownedSuite();
        $case = $this->caseWithSchemaAssertion($suite);
        $case->update(['source' => TestCaseSource::Llm]);
        LlmCallLog::factory()->create([
            'generation_job_id' => $case->generation_job_id,
            'test_case_id' => $case->id,
            'seed' => 1024,
            'latency_ms' => 22855,
            'prompt_tokens' => 11230,
            'completion_tokens' => 1500,
        ]);

        $html = $this->html("/test-cases/{$case->id}", $tester);

        $this->assertStringContainsString('Seed: <strong class="text-slate-200" data-testid="llm-log-seed">1024</strong>', $html);
        $this->assertStringContainsString('22.855 ms', $html);
        $this->assertStringContainsString('Tokens: <strong class="text-slate-200">12.730</strong> (in: 11.230, out: 1.500)', $html);
    }

    // --- 5. Account menu closes when focus leaves it ---------------------------------------------

    public function test_account_menu_closes_on_focusout_in_both_shells(): void
    {
        foreach ([User::factory()->tester()->create(), User::factory()->admin()->create()] as $user) {
            $wrapper = $this->tagWithTestId($this->html('/dashboard', $user), 'account-menu-wrapper');

            $this->assertStringContainsString('@focusout="if (open && $event.relatedTarget && !$el.contains($event.relatedTarget)) open = false"', $wrapper);
        }
    }

    // --- 6. Filtered empty states point at the filter -------------------------------------------

    public function test_m05_filtered_empty_state_offers_clearing_the_search(): void
    {
        $tester = User::factory()->tester()->create();
        Project::factory()->for($tester, 'owner')->create(['name' => 'Cửa hàng']);

        $this->actingAs($tester)->get('/projects?search=zzz')->assertOk()
            ->assertSee('Không có dự án nào khớp với “zzz”')
            ->assertSee('data-testid="projects-empty-filtered"', false)
            ->assertDontSee('Tạo dự án đầu tiên');

        $empty = User::factory()->tester()->create();
        $this->actingAs($empty)->get('/projects')->assertOk()
            ->assertSee('Chưa có dự án nào')
            ->assertSee('Tạo dự án đầu tiên')
            ->assertDontSee('Không có dự án nào khớp');
    }

    public function test_m09_filtered_empty_state_offers_clearing_the_filter(): void
    {
        [$tester, , , $suite] = $this->ownedSuite();
        $this->caseWithSchemaAssertion($suite);

        $this->actingAs($tester)->get("/suites/{$suite->id}?source=LLM")->assertOk()
            ->assertSee('Không có test case nào khớp bộ lọc')
            ->assertDontSee('để tự động sinh test cases');

        [$other, , , $emptySuite] = $this->ownedSuite();
        $this->actingAs($other)->get("/suites/{$emptySuite->id}")->assertOk()
            ->assertSee('Chưa có test case nào')
            ->assertSee('để tự động sinh test cases')
            ->assertDontSee('khớp bộ lọc');
    }

    // --- 7. M03 long names wrap inside the cell ---------------------------------------------------

    public function test_m03_long_user_name_and_email_wrap_inside_a_bounded_cell(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->tester()->create(['name' => str_repeat('L', 255)]);

        $html = $this->html('/admin/users', $admin);

        foreach (['user-name-cell', 'user-email-cell'] as $cell) {
            $tag = $this->tagWithTestId($html, $cell);
            $this->assertStringContainsString('max-w-xs', $tag);
            $this->assertStringContainsString('[overflow-wrap:anywhere]', $tag);
        }
    }

    // --- 8 + 9. M14 dialog: 409 details, close button, backdrop ------------------------------------

    public function test_m14_dialog_lists_details_only_for_validation_errors_and_has_close_button(): void
    {
        $html = $this->html('/admin/benchmarks', User::factory()->admin()->create());

        $this->assertStringContainsString("json.error.code === 'VALIDATION_FAILED'", $html);
        $start = strpos($html, 'aria-labelledby="benchmark-modal-title"');
        $this->assertNotFalse($start);
        $dialog = substr($html, $start, (int) strpos($html, '</form>', $start) - $start);
        $this->assertStringContainsString('bg-slate-950/80 backdrop-blur-sm', $dialog);
        $this->assertMatchesRegularExpression('/<button type="button" @click="modalOpen = false" aria-label="Đóng hộp thoại"/', $dialog);

        // Other screens that list error.details apply the same guard.
        [$tester, , , $suite] = $this->ownedSuite();
        $case = $this->caseWithSchemaAssertion($suite);
        $this->assertStringContainsString("if (code !== 'VALIDATION_FAILED' && code !== 'DSL_INVALID') return [];", $this->html("/test-cases/{$case->id}", $tester));
        $this->assertStringContainsString("body.error.code === 'VALIDATION_FAILED'", $this->html("/suites/{$suite->id}/runs", $tester));
    }

    // --- 10 + 12. Codes and badges do not break mid-token; wide tables scroll -------------------

    public function test_codes_and_badges_stay_on_one_line_and_wide_tables_keep_a_minimum_width(): void
    {
        $admin = User::factory()->admin()->create();
        [$tester, $project, , $suite] = $this->ownedSuite();
        TestRun::factory()->create([
            'test_suite_id' => $suite->id,
            'triggered_by' => $tester->id,
            'status' => TestRunStatus::Completed,
            'benchmark_variant' => 'BUG-032',
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        BusinessRule::factory()->create(['project_id' => $project->id, 'code' => 'BR-AB-010']);
        $bug = BenchmarkBug::factory()->create(['code' => 'BUG-032']);

        // M02 (dashboard): variant badge and the header row.
        $dashboard = $this->html('/dashboard', $admin);
        $this->assertStringContainsString('whitespace-nowrap', $this->tagWithTestId($dashboard, 'run-variant-badge'));
        $this->assertStringContainsString('flex-wrap', $this->tagWithTestId($dashboard, 'recent-runs-header'));

        // M08: rule code cell, table min width, nowrap action column.
        $rules = $this->html("/projects/{$project->id}/business-rules", $tester);
        $this->assertStringContainsString('whitespace-nowrap', $this->tagWithTestId($rules, 'business-rule-code'));
        $this->assertStringContainsString('min-w-[48rem]', $this->tagWithTestId($rules, 'business-rules-table'));
        $this->assertStringContainsString('<th class="whitespace-nowrap px-5 py-3.5 font-bold text-right w-36">Thao tác</th>', $rules);

        // M14: bug code cell and table min width.
        $benchmarks = $this->html('/admin/benchmarks', $admin);
        $this->assertStringContainsString('whitespace-nowrap', $this->tagWithTestId($benchmarks, 'benchmark-bug-code'));
        $this->assertStringContainsString('min-w-[56rem]', $this->tagWithTestId($benchmarks, 'benchmark-bugs-table'));

        // M15 detail: bug matrix code cell.
        $experiment = Experiment::factory()->create(['benchmark_id' => $bug->benchmark_id, 'status' => ExperimentStatus::Completed]);
        $detail = $this->html("/experiments/{$experiment->id}", $tester);
        $this->assertStringContainsString('whitespace-nowrap', $this->tagWithTestId($detail, 'matrix-bug-code'));

        // M09: test case table at 768 px.
        $this->caseWithSchemaAssertion($suite);
        $this->assertStringContainsString('min-w-[56rem]', $this->tagWithTestId($this->html("/suites/{$suite->id}", $tester), 'test-cases-table'));

        // M06: spec version table.
        $this->assertStringContainsString('min-w-[52rem]', $this->tagWithTestId($this->html("/projects/{$project->id}/specs", $tester), 'spec-versions-table'));
    }

    public function test_m11_tables_keep_a_minimum_width_and_format_llm_usage(): void
    {
        [$tester, $project, $spec, $suite] = $this->ownedSuite();
        $job = GenerationJob::factory()->create([
            'project_id' => $project->id,
            'api_spec_id' => $spec->id,
            'test_suite_id' => $suite->id,
            'strategy' => GenerationStrategy::Llm,
            'status' => GenerationJobStatus::Succeeded,
            'params' => ['techniques' => ['HAPPY_PATH'], 'temperature' => 0.2, 'seed' => 1024],
            'created_by' => $tester->id,
            'started_at' => now()->subMinutes(20),
            'finished_at' => now(),
        ]);
        LlmCallLog::factory()->create(['generation_job_id' => $job->id, 'prompt_tokens' => 116395, 'completion_tokens' => 1000, 'latency_ms' => 1263528]);
        TestCaseModel::factory()->create(['test_suite_id' => $suite->id, 'generation_job_id' => $job->id, 'source' => TestCaseSource::Llm]);

        $html = $this->html("/generation-jobs/{$job->id}", $tester);

        $this->assertStringContainsString('id="llm-prompt-tokens" x-text="fmt(summary.llm.prompt_tokens)">116.395<', $html);
        $this->assertStringContainsString('id="llm-total-tokens" x-text="fmt(summary.llm.total_tokens)">117.395<', $html);
        $this->assertStringContainsString('id="llm-total-latency" x-text="fmt(summary.llm.total_latency_ms)">1.263.528</span> ms', $html);
        $this->assertStringContainsString("toLocaleString('vi-VN', { maximumFractionDigits: 0 })", $html);
        // Vietnamese labels for the job parameters and the per-endpoint headers.
        $this->assertStringContainsString('Mô hình (model)', $html);
        $this->assertStringContainsString('Nhiệt độ (temperature)', $html);
        $this->assertStringContainsString('title="Không hợp lệ (INVALID)">Không hợp lệ</th>', $html);
        $this->assertStringContainsString('min-w-[48rem]', $this->tagWithTestId($html, 'generation-endpoints-table'));
        $this->assertStringContainsString('min-w-[44rem]', $this->tagWithTestId($html, 'generated-cases-table'));
        $this->assertStringContainsString('<th class="whitespace-nowrap px-3 py-2.5 text-right">Chi tiết</th>', $html);
        $this->assertStringNotContainsString('<dt class="text-slate-400">Temperature</dt>', $html);
        // M11 title and h1 agree.
        $this->assertStringContainsString('<title>Tiến độ sinh test #'.$job->id, $html);
        $this->assertMatchesRegularExpression('/<h1[^>]*>Tiến độ sinh test #'.$job->id.'<\/h1>/', $html);
    }

    // --- 11. M12 assertion table ------------------------------------------------------------------

    public function test_m12_assertion_table_scrolls_as_a_whole_without_splitting_words(): void
    {
        [$tester, , , $suite] = $this->ownedSuite();
        $run = TestRun::factory()->create([
            'test_suite_id' => $suite->id,
            'triggered_by' => $tester->id,
            'status' => TestRunStatus::Completed,
            'benchmark_variant' => 'clean',
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $html = $this->html("/runs/{$run->id}", $tester);

        $this->assertStringContainsString('min-w-[36rem]', $this->tagWithTestId($html, 'assertion-table'));
        $this->assertStringContainsString('whitespace-nowrap', $this->tagWithTestId($html, 'assertion-verdict'));
        foreach (['assertion-expected', 'assertion-actual'] as $cell) {
            $tag = $this->tagWithTestId($html, $cell);
            $this->assertStringNotContainsString('break-all', $tag);
            $this->assertStringContainsString('[overflow-wrap:anywhere]', $tag);
        }
        // 18: the clean variant is shown in Vietnamese.
        $this->assertStringContainsString('Bản sạch (clean)', $html);
    }

    // --- 13. Exported HTML report on a phone ---------------------------------------------------------

    public function test_html_export_wraps_tables_in_scroll_containers(): void
    {
        [$tester, , , $suite] = $this->ownedSuite();
        $run = TestRun::factory()->create([
            'test_suite_id' => $suite->id,
            'triggered_by' => $tester->id,
            'status' => TestRunStatus::Completed,
            'benchmark_variant' => 'clean',
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $html = (string) $this->actingAs($tester)->get("/api/v1/runs/{$run->id}/export?format=html")->assertOk()->getContent();

        $this->assertStringContainsString('.table-wrap { overflow-x: auto;', $html);
        $this->assertStringContainsString('@media (max-width: 600px)', $html);
        $this->assertSame(substr_count($html, '<table>'), substr_count($html, '<div class="table-wrap"><table>'));
        $this->assertGreaterThan(0, substr_count($html, '<div class="table-wrap"><table>'));
        $this->assertStringContainsString('<h1>Báo cáo lần chạy #'.$run->id.'</h1>', $html);
        $this->assertStringContainsString('Biến thể: Bản sạch (clean)', $html);
        $this->assertStringContainsString("default-src 'none'; style-src 'unsafe-inline'", $html);
    }

    public function test_html_export_wraps_long_failure_messages_and_titles(): void
    {
        [$tester, , , $suite] = $this->ownedSuite();
        $run = TestRun::factory()->create([
            'test_suite_id' => $suite->id,
            'triggered_by' => $tester->id,
            'status' => TestRunStatus::Completed,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        $token = str_repeat('x', 200);
        TestResult::factory()->create([
            'test_run_id' => $run->id,
            'verdict' => TestVerdict::Error,
            'error_message' => 'Không gửi được yêu cầu: '.$token,
            'assertion_results' => [['type' => 'status', 'expected' => 200, 'actual' => 500, 'passed' => false, 'message' => $token]],
        ]);

        $html = (string) $this->actingAs($tester)->get("/api/v1/runs/{$run->id}/export?format=html")->assertOk()->getContent();

        // The .break rule is defined once and used by every free-text cell that can hold a long token.
        $this->assertStringContainsString('.break { word-break: break-word; overflow-wrap: anywhere; }', $html);
        $this->assertStringContainsString('<div class="error-box break">Không gửi được yêu cầu: '.$token.'</div>', $html);
        $this->assertStringContainsString('<td class="break">'.$token.'</td>', $html);
        $this->assertMatchesRegularExpression('/<strong class="break">#\d+ /', $html);
        $this->assertMatchesRegularExpression('/<td class="break">#\d+ /', $html);
    }

    // --- 14. Vietnamese labels -----------------------------------------------------------------------

    public function test_english_only_labels_are_replaced_with_vietnamese(): void
    {
        [$tester, $project, $spec, $suite] = $this->ownedSuite();
        $run = TestRun::factory()->create([
            'test_suite_id' => $suite->id,
            'triggered_by' => $tester->id,
            'status' => TestRunStatus::Completed,
            'totals' => ['total' => 23, 'pass' => 19, 'fail' => 4, 'error' => 0, 'skipped' => 0],
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $this->actingAs($tester)->get('/dashboard')->assertOk()
            ->assertSee('Ứng dụng web:')
            ->assertSee('Cơ sở dữ liệu:')
            ->assertSee('Dịch vụ AI:')
            ->assertSee('Đạt 19/23')
            ->assertDontSee('Web App:')
            ->assertDontSee('AI Service:')
            ->assertDontSee('19 PASS / 23');

        Endpoint::factory()->create(['api_spec_id' => $spec->id, 'operation_id' => 'getItem']);
        $this->actingAs($tester)->get("/projects/{$project->id}/specs/{$spec->id}")->assertOk()
            ->assertSee('Mã thao tác (operationId):')
            ->assertSee('Thân yêu cầu (request body, application/json)')
            ->assertSee('+ Gắn quy tắc nghiệp vụ')
            ->assertSee('Tổng cộng 1 endpoint')
            ->assertDontSee('OperationId:')
            ->assertDontSee('Gắn Business Rule');

        $this->actingAs($tester)->get("/runs/{$run->id}/report")->assertOk()
            ->assertSee('<th class="px-5 py-3 font-medium text-right">Bỏ qua</th>', false)
            ->assertDontSee('<th class="px-5 py-3 font-medium text-right">Pass</th>', false)
            ->assertDontSee('(Run #');

        $this->actingAs($tester)->get("/suites/{$suite->id}/runs")->assertOk()
            ->assertSee('Đạt / Không đạt / Lỗi / Bỏ qua')
            ->assertDontSee('PASS / FAIL / ERROR / SKIPPED');

        $experiment = Experiment::factory()->create([
            'status' => ExperimentStatus::Completed,
            'metrics' => ['bug_detection_rate' => 0.8, 'bugs_total' => 10, 'bugs_detected_mean' => 5.6667],
        ]);
        $this->actingAs($tester)->get('/experiments')->assertOk()
            ->assertSee('Tỉ lệ phát hiện bug (BDR)')
            ->assertSee('Tỉ lệ hợp lệ (Validity Rate)');
        $this->actingAs($tester)->get("/experiments/{$experiment->id}")->assertOk()
            ->assertSee('Số bug cài sẵn:')
            ->assertSee('Tỉ lệ dương tính giả (FPR)')
            ->assertSee('Độ phủ mã trạng thái')
            ->assertDontSee('Seeded bug:')
            ->assertDontSee('Status Code Cov.');
    }

    // --- 16. M15 mean detections ------------------------------------------------------------------------

    public function test_m15_mean_detections_use_two_decimals_with_a_comma(): void
    {
        $experiment = Experiment::factory()->create([
            'status' => ExperimentStatus::Completed,
            'metrics' => ['bugs_total' => 10, 'bugs_detected_mean' => 5.6667],
        ]);

        $this->actingAs(User::factory()->tester()->create())->get("/experiments/{$experiment->id}")->assertOk()
            ->assertSee('data-testid="bugs-detected-mean" class="font-mono text-slate-100">5,67</span>', false)
            ->assertDontSee('5.6667');
    }

    // --- 17. Run lists show the start time -----------------------------------------------------------------

    public function test_run_lists_and_dashboard_show_the_start_time_not_the_import_time(): void
    {
        [$tester, , , $suite] = $this->ownedSuite();
        $run = TestRun::factory()->create([
            'test_suite_id' => $suite->id,
            'triggered_by' => $tester->id,
            'status' => TestRunStatus::Completed,
            'started_at' => Carbon::parse('2026-10-12 12:44:16', 'UTC'),
            'finished_at' => Carbon::parse('2026-10-12 12:50:00', 'UTC'),
        ]);
        $run->forceFill(['created_at' => Carbon::parse('2026-10-13 15:58:00', 'UTC')])->save();

        $this->actingAs($tester)->get('/dashboard')->assertOk()
            ->assertSee('12/10/2026 19:44')
            ->assertDontSee('22:58');
        $this->actingAs($tester)->get("/suites/{$suite->id}/runs")->assertOk()
            ->assertSee('19:44 12/10/2026')
            ->assertDontSee('22:58');
        $this->actingAs($tester)->get("/suites/{$suite->id}")->assertOk()
            ->assertSee('12/10/2026 19:44')
            ->assertDontSee('22:58');
    }

    // --- 18. Status wording ------------------------------------------------------------------------------

    public function test_status_wording_is_consistent_across_runs_experiments_and_suites(): void
    {
        [$tester, , , $suite] = $this->ownedSuite();
        Experiment::factory()->create(['status' => ExperimentStatus::Completed]);

        $this->actingAs($tester)->get('/experiments')->assertOk()
            ->assertSee('title="Hoàn thành (COMPLETED)">Hoàn thành</span>', false)
            ->assertDontSee('Hoàn tất');

        $this->actingAs($tester)->get("/suites/{$suite->id}")->assertOk()
            ->assertSee('Không hợp lệ (INVALID)')
            ->assertDontSee('Lỗi (INVALID)');
    }

    // --- 19. Titles, h1 and breadcrumbs agree ---------------------------------------------------------------

    public function test_titles_headings_and_breadcrumbs_agree(): void
    {
        [$tester, $project, $spec, $suite] = $this->ownedSuite();
        $run = TestRun::factory()->create([
            'test_suite_id' => $suite->id,
            'triggered_by' => $tester->id,
            'status' => TestRunStatus::Completed,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $report = $this->html("/runs/{$run->id}/report", $tester);
        $this->assertStringContainsString('<title>Báo cáo lần chạy #'.$run->id, $report);
        $this->assertMatchesRegularExpression('/<h1[^>]*>\s*<span>Báo cáo lần chạy #'.$run->id.'<\/span>/', $report);

        $specs = $this->html("/projects/{$project->id}/specs", $tester);
        $this->assertStringContainsString('<title>Đặc tả OpenAPI', $specs);
        $this->assertMatchesRegularExpression('/<\/span>\s*Đặc tả OpenAPI\s*<\/h1>/', $specs);

        $endpoints = $this->html("/projects/{$project->id}/specs/{$spec->id}", $tester);
        $this->assertStringContainsString('<title>Danh sách endpoint — ', $endpoints);
        $this->assertMatchesRegularExpression('/<\/span>\s*Danh sách endpoint\s*<\/h1>/', $endpoints);

        $experiment = Experiment::factory()->create(['name' => 'Đợt A', 'status' => ExperimentStatus::Completed]);
        $detail = $this->html("/experiments/{$experiment->id}", $tester);
        // The status badge is not part of the heading's accessible name.
        $this->assertMatchesRegularExpression('/<h1[^>]*>Thực nghiệm #'.$experiment->id.': Đợt A<\/h1>/', $detail);

        $rules = $this->html("/projects/{$project->id}/business-rules", $tester);
        $this->assertMatchesRegularExpression('/<nav aria-label="Đường dẫn"[^>]*>\s*<a href="'.preg_quote(route('projects.index'), '/').'"[^>]*>Dự án<\/a>/', $rules);
    }

    // --- 20. Shared screens follow the shell accent ----------------------------------------------------------

    public function test_shared_screens_use_the_teal_accent_for_testers_and_indigo_for_admins(): void
    {
        [$tester, , , $suite] = $this->ownedSuite();
        $case = $this->caseWithSchemaAssertion($suite);
        $admin = User::factory()->admin()->create();

        $create = fn (User $user): string => $this->html('/projects', $user);
        $this->assertMatchesRegularExpression('/id="btn-create-project"[^>]*class="[^"]*bg-teal-700[^"]*"/s', $create($tester));
        $this->assertDoesNotMatchRegularExpression('/id="btn-create-project"[^>]*class="[^"]*bg-indigo-600[^"]*"/s', $create($tester));
        $this->assertMatchesRegularExpression('/id="btn-create-project"[^>]*class="[^"]*bg-indigo-600[^"]*"/s', $create($admin));

        $approve = fn (User $user): string => $this->tagWithTestId($this->html("/test-cases/{$case->id}", $user), 'tc-approve-button');
        $this->assertStringContainsString('bg-teal-700', $approve($tester));
        $this->assertStringNotContainsString('bg-indigo-600', $approve($tester));
        $this->assertStringContainsString('bg-indigo-600', $approve($admin));

        $tabs = $this->html("/test-cases/{$case->id}", $tester);
        $this->assertStringContainsString('border-teal-400 text-teal-300 font-semibold bg-teal-500\/10', $tabs);
        $this->assertStringNotContainsString('border-indigo-500 text-indigo-400 font-semibold', $tabs);

        $this->assertStringContainsString('bg-teal-700', $this->tagWithTestId($this->html("/suites/{$suite->id}/runs", $tester), 'btn-start-run'));
    }
}
