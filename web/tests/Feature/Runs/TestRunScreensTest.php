<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\ListRunTargetOptionsAction;
use App\Enums\TestCaseStatus;
use App\Enums\TestRunStatus;
use App\Enums\TestVerdict;
use App\Models\AllowedTarget;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Models\Project;
use App\Models\TestCase as TestCaseModel;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TestRunScreensTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: TestSuite, 2: TestCaseModel}
     */
    private function suiteOwnedByTester(): array
    {
        $tester = User::factory()->tester()->create(['name' => 'Tester Một']);
        $project = Project::factory()->for($tester, 'owner')->create(['name' => 'Dự án Đơn hàng']);
        $spec = ApiSpec::factory()->for($project)->create();
        $suite = TestSuite::factory()->create([
            'project_id' => $project->id,
            'api_spec_id' => $spec->id,
            'created_by' => $tester->id,
            'name' => 'Smoke Test v1.0',
        ]);
        $endpoint = Endpoint::factory()->create(['api_spec_id' => $spec->id, 'path' => '/orders']);
        $case = TestCaseModel::factory()->create([
            'test_suite_id' => $suite->id,
            'endpoint_id' => $endpoint->id,
            'title' => 'Tạo đơn hàng hợp lệ nhận 201',
            'status' => TestCaseStatus::Valid,
        ]);

        return [$tester, $suite, $case];
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $run = TestRun::factory()->create();

        $this->get("/suites/{$run->test_suite_id}/runs")->assertRedirect('/login');
        $this->get("/runs/{$run->id}")->assertRedirect('/login');
    }

    public function test_tester_sees_run_screen_of_own_suite_with_allowlisted_targets(): void
    {
        [$tester, $suite] = $this->suiteOwnedByTester();
        config(['ait.allowed_target_hosts' => ['localhost', 'benchmark']]);
        AllowedTarget::factory()->create(['host' => 'sandbox.test', 'port' => 9000, 'note' => 'Sandbox nội bộ']);
        $run = TestRun::factory()->create([
            'test_suite_id' => $suite->id,
            'triggered_by' => $tester->id,
            'target_base_url' => 'http://benchmark',
            'status' => TestRunStatus::Completed,
            'totals' => ['total' => 1, 'pass' => 1, 'fail' => 0, 'error' => 0, 'skipped' => 0],
        ]);

        $this->actingAs($tester)
            ->get("/suites/{$suite->id}/runs")
            ->assertOk()
            ->assertSee('<span>Lần chạy</span>', false)
            ->assertSee('Tạo lần chạy mới')
            ->assertSee('Lịch sử lần chạy')
            ->assertSee('Bắt đầu chạy')
            ->assertSee('http://localhost')
            ->assertSee('http://benchmark')
            ->assertSee('http://sandbox.test:9000')
            ->assertSee('Sandbox nội bộ')
            ->assertSee("#{$run->id}")
            ->assertSee('COMPLETED')
            ->assertSee(route('runs.show', $run), false);
    }

    public function test_run_screen_warns_when_allowlist_is_empty(): void
    {
        [$tester, $suite] = $this->suiteOwnedByTester();
        config(['ait.allowed_target_hosts' => []]);

        $this->actingAs($tester)
            ->get("/suites/{$suite->id}/runs")
            ->assertOk()
            ->assertSee('Chưa có hệ thống đích nào trong danh sách cho phép')
            ->assertSee('Chưa có lần chạy nào cho bộ kiểm thử này.');
    }

    public function test_tester_sees_run_detail_page(): void
    {
        [$tester, $suite, $case] = $this->suiteOwnedByTester();
        $run = TestRun::factory()->create([
            'test_suite_id' => $suite->id,
            'triggered_by' => $tester->id,
            'target_base_url' => 'http://localhost:8080',
            'status' => TestRunStatus::Running,
        ]);

        $this->actingAs($tester)
            ->get("/runs/{$run->id}")
            ->assertOk()
            ->assertSee("<span>Lần chạy #{$run->id}</span>", false)
            ->assertSee('TIẾN ĐỘ THỰC THI')
            ->assertSee('Kết quả từng ca kiểm thử')
            ->assertSee('Hủy lần chạy')
            ->assertSee('Kiểm tra (assertion)')
            ->assertSee('http://localhost:8080')
            ->assertSee('Smoke Test v1.0');
    }

    public function test_run_progress_is_a_polite_live_region(): void
    {
        // Regression (S13-03 QA): the polled progress text and counters had no live region, unlike the
        // generation screen (#job-status role=status aria-live=polite).
        [$tester, $suite] = $this->suiteOwnedByTester();
        $run = TestRun::factory()->create([
            'test_suite_id' => $suite->id,
            'triggered_by' => $tester->id,
            'status' => TestRunStatus::Running,
        ]);

        $html = (string) $this->actingAs($tester)->get("/runs/{$run->id}")->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<div[^>]*role="status"[^>]*aria-live="polite"[^>]*aria-atomic="true"[^>]*data-testid="run-progress-status"[^>]*>/',
            $html,
        );
        $start = (int) strpos($html, 'data-testid="run-progress-status"');
        $region = substr($html, $start, (int) strpos($html, 'Bắt đầu:', $start) - $start);
        $this->assertStringContainsString('TIẾN ĐỘ THỰC THI', $region);
        $this->assertStringContainsString('x-text="statusLabels[run.status] || run.status"', $region);
        $this->assertStringContainsString("'Đạt ' + counts.PASS", $region);
    }

    public function test_finished_run_links_to_report_from_detail_and_list(): void
    {
        [$tester, $suite] = $this->suiteOwnedByTester();
        $finished = TestRun::factory()->create([
            'test_suite_id' => $suite->id,
            'triggered_by' => $tester->id,
            'status' => TestRunStatus::Completed,
        ]);
        $running = TestRun::factory()->create([
            'test_suite_id' => $suite->id,
            'triggered_by' => $tester->id,
            'status' => TestRunStatus::Running,
        ]);
        $finishedReport = route('runs.report', $finished);
        $runningReport = route('runs.report', $running);

        $finishedLink = $this->reportLinkTag($this->actingAs($tester)->get("/runs/{$finished->id}")->assertOk()->getContent());
        $this->assertStringContainsString('href="'.$finishedReport.'"', $finishedLink);
        $this->assertStringNotContainsString('x-cloak', $finishedLink);

        // A running run keeps the link hidden until the screen sees a terminal status.
        $runningLink = $this->reportLinkTag($this->actingAs($tester)->get("/runs/{$running->id}")->assertOk()->getContent());
        $this->assertStringContainsString('href="'.$runningReport.'"', $runningLink);
        $this->assertStringContainsString('x-show="isTerminal"', $runningLink);
        $this->assertStringContainsString('x-cloak', $runningLink);

        $this->actingAs($tester)
            ->get("/suites/{$suite->id}/runs")
            ->assertOk()
            ->assertSee('Xem báo cáo')
            ->assertSee($finishedReport, false)
            ->assertDontSee($runningReport, false);

        $this->actingAs($tester)->get($finishedReport)->assertOk();
    }

    private function reportLinkTag(string|false $html): string
    {
        $this->assertIsString($html);
        if (preg_match('/<a\b[^>]*data-testid="run-report-link"[^>]*>/', $html, $m) !== 1) {
            $this->fail('Run detail page has no report link.');
        }

        return $m[0];
    }

    public function test_admin_can_view_any_users_run_screens(): void
    {
        [, $suite] = $this->suiteOwnedByTester();
        $admin = User::factory()->admin()->create();
        $run = TestRun::factory()->create(['test_suite_id' => $suite->id]);

        $this->actingAs($admin)->get("/suites/{$suite->id}/runs")->assertOk()->assertSee('Lịch sử lần chạy');
        $this->actingAs($admin)->get("/runs/{$run->id}")->assertOk()->assertSee('TIẾN ĐỘ THỰC THI');
    }

    public function test_tester_cannot_view_other_users_runs(): void
    {
        [, $suite] = $this->suiteOwnedByTester();
        $other = User::factory()->tester()->create();
        $run = TestRun::factory()->create(['test_suite_id' => $suite->id]);

        $this->actingAs($other)->get("/suites/{$suite->id}/runs")->assertForbidden();
        $this->actingAs($other)->get("/runs/{$run->id}")->assertForbidden();
    }

    public function test_suite_page_links_to_run_screen(): void
    {
        [$tester, $suite] = $this->suiteOwnedByTester();

        $this->actingAs($tester)
            ->get("/suites/{$suite->id}")
            ->assertOk()
            ->assertSee(route('suites.runs.index', $suite), false)
            ->assertSee('Chạy test suite');
    }

    public function test_results_api_used_by_screen_exposes_request_response_and_assertions(): void
    {
        [$tester, $suite, $case] = $this->suiteOwnedByTester();
        $run = TestRun::factory()->create(['test_suite_id' => $suite->id, 'status' => TestRunStatus::Completed]);
        TestResult::factory()->create([
            'test_run_id' => $run->id,
            'test_case_id' => $case->id,
            'verdict' => TestVerdict::Fail,
            'request_log' => ['method' => 'POST', 'url' => 'http://localhost:8080/orders', 'headers' => ['Authorization' => '***'], 'body' => ['quantity' => 999999]],
            'response_log' => ['status' => 201, 'headers' => [], 'body' => '{"id":104}', 'duration_ms' => 35],
            'assertion_results' => [['type' => 'status', 'expected' => 422, 'actual' => 201, 'passed' => false, 'message' => 'Expected status 422']],
        ]);

        $this->actingAs($tester)
            ->getJson("/api/v1/runs/{$run->id}/results?per_page=100")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.verdict', 'FAIL')
            ->assertJsonPath('data.0.request_log.url', 'http://localhost:8080/orders')
            ->assertJsonPath('data.0.response_log.status', 201)
            ->assertJsonPath('data.0.assertion_results.0.expected', 422)
            ->assertJsonPath('data.0.assertion_results.0.actual', 201)
            ->assertJsonPath('data.0.assertion_results.0.passed', false);
    }

    public function test_target_options_merge_config_and_database_allowlist(): void
    {
        config(['ait.allowed_target_hosts' => ['localhost', '127.0.0.1:8080', '::1']]);
        AllowedTarget::factory()->create(['host' => 'benchmark', 'port' => null, 'note' => null]);
        AllowedTarget::factory()->create(['host' => 'localhost', 'port' => null, 'note' => 'trùng']);

        $options = app(ListRunTargetOptionsAction::class)->execute();
        $urls = array_map(fn ($o) => $o->url, $options);

        $this->assertSame([
            'http://localhost',
            'http://127.0.0.1:8080',
            'http://[::1]',
            'http://benchmark:8000',
            'http://benchmark',
        ], $urls);
    }

    public function test_target_field_is_editable_with_allowlist_suggestions(): void
    {
        [$tester, $suite] = $this->suiteOwnedByTester();
        config(['ait.allowed_target_hosts' => ['localhost', '127.0.0.1', 'benchmark']]);

        $this->actingAs($tester)
            ->get("/suites/{$suite->id}/runs")
            ->assertOk()
            ->assertSee('list="run-target-suggestions"', false)
            ->assertSee('<datalist id="run-target-suggestions">', false)
            ->assertDontSee('<select x-model="target"', false)
            ->assertSee('value="http://benchmark:8000"', false)
            ->assertSee('Host không kèm cổng trong allowlist cho phép mọi cổng.');
    }

    public function test_m12_explains_that_shared_benchmarks_are_reset_before_every_case(): void
    {
        // QA S13-03 (ADR-0006 addendum 2026-10-13).
        [$tester, $suite] = $this->suiteOwnedByTester();
        config(['ait.benchmark.m12_reset_hosts' => ['benchmark', 'simple-store']]);

        $this->actingAs($tester)
            ->get("/suites/{$suite->id}/runs")
            ->assertOk()
            ->assertSee('id="benchmark-reset-note"', false)
            ->assertSee('benchmark, simple-store')
            ->assertSee('trước từng test case, dữ liệu benchmark được đặt lại về dữ liệu mẫu');

        config(['ait.benchmark.m12_reset_hosts' => []]);

        $this->actingAs($tester)
            ->get("/suites/{$suite->id}/runs")
            ->assertOk()
            ->assertDontSee('id="benchmark-reset-note"', false);
    }

    public function test_run_on_explicit_port_of_portless_allowlisted_host_can_be_started(): void
    {
        Queue::fake();
        [$tester, $suite] = $this->suiteOwnedByTester();
        config(['ait.allowed_target_hosts' => ['benchmark']]);

        // Same request the M12 form sends after the user picks/types http://benchmark:8000.
        $this->actingAs($tester)
            ->postJson("/api/v1/suites/{$suite->id}/runs", ['target_base_url' => 'http://benchmark:8000'])
            ->assertStatus(202)
            ->assertJsonPath('data.target_base_url', 'http://benchmark:8000')
            ->assertJsonPath('data.status', 'QUEUED');

        $this->actingAs($tester)
            ->postJson("/api/v1/suites/{$suite->id}/runs", ['target_base_url' => 'http://evil.example:8000'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TARGET_NOT_ALLOWED');
    }

    public function test_run_cancelled_while_running_exposes_fields_the_screen_waits_for(): void
    {
        // runs/show keeps polling a CANCELLED run while started_at is set and totals is null
        // (the worker still stores SKIPPED results and totals after the in-flight case).
        [$tester, $suite] = $this->suiteOwnedByTester();
        $run = TestRun::factory()->create([
            'test_suite_id' => $suite->id,
            'triggered_by' => $tester->id,
            'status' => TestRunStatus::Running,
            'started_at' => now(),
            'totals' => null,
        ]);

        $this->actingAs($tester)
            ->postJson("/api/v1/runs/{$run->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.totals', null);
        $this->assertNotNull($run->fresh()?->started_at);

        $this->actingAs($tester)
            ->get("/runs/{$run->id}")
            ->assertOk()
            ->assertSee("this.run.status === 'CANCELLED' && this.run.started_at && !this.run.totals", false)
            ->assertSee('if (!this.isSettled) {', false);
    }

    public function test_run_apis_polled_by_screen_answer_envelope_with_status_on_errors(): void
    {
        // runs/show shows error.message from these answers and stops polling on 401/403/404.
        [, $suite] = $this->suiteOwnedByTester();
        $other = User::factory()->tester()->create();
        $run = TestRun::factory()->create(['test_suite_id' => $suite->id]);

        $this->getJson("/api/v1/runs/{$run->id}")
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->getJson("/api/v1/runs/{$run->id}/results?per_page=100&page=1")
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        foreach (["/api/v1/runs/{$run->id}", "/api/v1/runs/{$run->id}/results?per_page=100&page=1"] as $url) {
            $response = $this->actingAs($other)->getJson($url)->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
            $this->assertIsString($response->json('error.message'));
            $this->assertNotSame('', $response->json('error.message'));
        }

        $missing = $run->id + 1000;
        $this->actingAs($other)->getJson("/api/v1/runs/{$missing}")->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        $this->actingAs($other)->getJson("/api/v1/runs/{$missing}/results")->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_run_detail_screen_shows_api_error_message_and_stops_polling_on_client_errors(): void
    {
        [$tester, $suite] = $this->suiteOwnedByTester();
        $run = TestRun::factory()->create(['test_suite_id' => $suite->id, 'status' => TestRunStatus::Running]);

        $this->actingAs($tester)
            ->get("/runs/{$run->id}")
            ->assertOk()
            // A failed results page throws the envelope's message with its HTTP status…
            ->assertSee("throw new ApiError(apiErrorMessage(body, 'Không tải được kết quả.'), res.ok ? null : res.status);", false)
            // …which refresh() shows instead of the generic network-loss text.
            ->assertSee('if (e instanceof ApiError) {', false)
            ->assertSee('this.errorMessage = e.message;', false)
            ->assertSee('Mất kết nối tới máy chủ, đang thử lại…')
            // 401 goes to login; other non-retryable 4xx stop the polling loop.
            ->assertSee('if (failedStatus === 401) {', false)
            ->assertSee('window.location.href = config.loginUrl;', false)
            ->assertSee('if (isNonRetryable(failedStatus)) {', false)
            ->assertSee('status >= 400 && status < 500 && status !== 408 && status !== 429', false)
            ->assertSee('loginUrl', false);
    }
}
