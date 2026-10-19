<?php

namespace Tests\Feature;

use App\Actions\User\UpdateUserRoleAction;
use App\Enums\ApiSpecStatus;
use App\Enums\GenerationJobStatus;
use App\Enums\TestCaseStatus;
use App\Enums\TestRunStatus;
use App\Enums\TestVerdict;
use App\Enums\UserRole;
use App\Models\AllowedTarget;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\TestCase as TestCaseModel;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Regression tests for the S10-01 web-ui audit (round 1).
 */
class WebUiAuditTest extends TestCase
{
    use RefreshDatabase;

    private const XSS_NAME = "x');alert(document.cookie);('";

    /**
     * @return array{0: User, 1: Project, 2: ApiSpec, 3: TestSuite, 4: Endpoint}
     */
    private function ownedSuite(string $projectName = 'Dự án A', string $suiteName = 'Bộ test A'): array
    {
        $tester = User::factory()->tester()->create();
        $project = Project::factory()->for($tester, 'owner')->create(['name' => $projectName]);
        $spec = ApiSpec::factory()->for($project)->create(['status' => ApiSpecStatus::Parsed]);
        $suite = TestSuite::factory()->create([
            'project_id' => $project->id,
            'api_spec_id' => $spec->id,
            'created_by' => $tester->id,
            'name' => $suiteName,
        ]);
        $endpoint = Endpoint::factory()->create(['api_spec_id' => $spec->id, 'path' => '/items']);

        return [$tester, $project, $spec, $suite, $endpoint];
    }

    // --- CDN fallback ---------------------------------------------------------------

    public function test_pages_never_load_unpinned_third_party_cdn_scripts(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertDontSee('cdn.tailwindcss.com', false)
            ->assertDontSee('cdn.jsdelivr.net', false);

        $this->actingAs(User::factory()->tester()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('cdn.tailwindcss.com', false)
            ->assertDontSee('alpinejs@3.x.x', false);
    }

    // --- Stored XSS in inline confirm() -----------------------------------------------

    public function test_project_name_with_quote_cannot_break_out_of_delete_confirm_for_admin(): void
    {
        $tester = User::factory()->tester()->create();
        Project::factory()->for($tester, 'owner')->create(['name' => self::XSS_NAME]);
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/projects')->assertOk();

        // Before the fix Blade rendered x&#039;);alert(...) which the browser decodes back to a quote.
        $response->assertDontSee("confirm('Bạn có chắc muốn xóa dự án x&#039;", false);
        // The name lives only in an HTML-escaped data-confirm attribute, never inside the JS handler.
        $response->assertSee('data-confirm="Bạn có chắc muốn xóa dự án '.e(self::XSS_NAME).'?"', false);
        // ADR-0007 CSP: no inline handler at all; resources/js/app.js reads data-confirm on submit.
        $response->assertDontSee('onclick=', false);
    }

    public function test_suite_name_with_quote_cannot_break_out_of_delete_confirm(): void
    {
        [$tester, $project] = $this->ownedSuite('Dự án', self::XSS_NAME);

        $response = $this->actingAs($tester)->get("/projects/{$project->id}/suites")->assertOk();

        $response->assertDontSee("confirm('Bạn có chắc muốn xóa bộ kiểm thử x&#039;", false);
        // The name lives only in an HTML-escaped data-confirm attribute, never inside the JS handler.
        $response->assertSee('data-confirm="Bạn có chắc muốn xóa bộ kiểm thử '.e(self::XSS_NAME).'?"', false);
        // ADR-0007 CSP: no inline handler at all; resources/js/app.js reads data-confirm on submit.
        $response->assertDontSee('onclick=', false);
    }

    // --- M02 dashboard ----------------------------------------------------------------

    public function test_dashboard_shows_real_recent_runs_counts_and_pass_rate(): void
    {
        [$tester, , , $suite, $endpoint] = $this->ownedSuite('Dự án', 'Suite Dashboard');
        $case = TestCaseModel::factory()->create([
            'test_suite_id' => $suite->id,
            'endpoint_id' => $endpoint->id,
            'status' => TestCaseStatus::Approved,
        ]);
        $run = TestRun::factory()->create([
            'test_suite_id' => $suite->id,
            'triggered_by' => $tester->id,
            'status' => TestRunStatus::Completed,
            'target_base_url' => 'http://benchmark:8000',
            'totals' => ['total' => 4, 'pass' => 3, 'fail' => 1, 'error' => 0, 'skipped' => 0],
        ]);
        TestResult::factory()->create(['test_run_id' => $run->id, 'test_case_id' => $case->id, 'verdict' => TestVerdict::Pass]);
        foreach ([TestVerdict::Pass, TestVerdict::Pass, TestVerdict::Fail, TestVerdict::Skipped] as $verdict) {
            $other = TestCaseModel::factory()->create(['test_suite_id' => $suite->id, 'endpoint_id' => $endpoint->id]);
            TestResult::factory()->create(['test_run_id' => $run->id, 'test_case_id' => $other->id, 'verdict' => $verdict]);
        }

        // A run of another tester must not leak into this dashboard.
        [, , , $otherSuite] = $this->ownedSuite('Khác', 'Suite Người Khác');
        TestRun::factory()->create(['test_suite_id' => $otherSuite->id]);

        $this->actingAs($tester)->get('/dashboard')
            ->assertOk()
            ->assertSee('Suite Dashboard')
            ->assertDontSee('Suite Người Khác')
            ->assertSee('75,0%')
            ->assertSee(route('runs.show', $run), false)
            ->assertSee(route('runs.report', $run), false)
            ->assertSee('Hoàn thành')
            ->assertDontSee('Sprint S4')
            ->assertDontSee('href="#"', false)
            ->assertSee('href="'.route('experiments.index').'"', false)
            ->assertViewHas('stats', fn (array $stats) => $stats['approved_test_cases'] === 1);
    }

    public function test_dashboard_pass_rate_is_na_without_results(): void
    {
        $this->actingAs(User::factory()->tester()->create())->get('/dashboard')
            ->assertOk()
            ->assertViewHas('stats', fn (array $stats) => $stats['pass_rate_7d'] === 'N/A' && $stats['approved_test_cases'] === 0);
    }

    // --- Vietnamese framework strings ---------------------------------------------------

    public function test_login_failure_and_validation_messages_are_vietnamese(): void
    {
        app()->setLocale('vi');
        $user = User::factory()->create(['password' => 'correct-password']);

        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['email' => 'Email hoặc mật khẩu không đúng.']);

        $tester = User::factory()->tester()->create();
        $this->actingAs($tester)->from('/projects')->post('/projects', ['_form' => 'create-project', 'name' => ''])
            ->assertSessionHasErrors('name');
        $errors = session('errors')->getBag('default')->get('name');
        $this->assertStringContainsString('là bắt buộc', $errors[0]);
        $this->assertStringNotContainsString('field is required', $errors[0]);
    }

    public function test_pagination_is_vietnamese(): void
    {
        app()->setLocale('vi');
        $admin = User::factory()->admin()->create();
        User::factory()->count(12)->create();

        $this->actingAs($admin)->get('/admin/users')
            ->assertOk()
            ->assertSee('Hiển thị')
            ->assertSee('kết quả')
            ->assertDontSee('Showing');
    }

    // --- M14/M15 error details --------------------------------------------------------

    public function test_m14_and_m15_render_error_details_from_envelope(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/benchmarks')
            ->assertOk()
            ->assertSee('data-testid="benchmark-error"', false)
            ->assertSee('Object.values(json.error.details)', false);

        $this->actingAs($admin)->get('/experiments')
            ->assertOk()
            ->assertSee('Object.values(json.error.details)', false);
    }

    // --- M03 role change --------------------------------------------------------------

    public function test_admin_can_change_another_users_role_from_m03(): void
    {
        $admin = User::factory()->admin()->create();
        $tester = User::factory()->tester()->create();

        $this->actingAs($admin)->get('/admin/users')
            ->assertOk()
            ->assertSee(route('admin.users.update-role', $tester), false)
            ->assertSee('Đổi vai trò');

        $this->actingAs($admin)->patch("/admin/users/{$tester->id}/role", ['_form' => 'update-role', 'role' => 'ADMIN'])
            ->assertRedirect('/admin/users')
            ->assertSessionHas('success');
        $this->assertSame(UserRole::Admin, $tester->refresh()->role);

        $this->actingAs($admin)->patch("/admin/users/{$tester->id}/role", ['role' => 'TESTER'])
            ->assertRedirect('/admin/users');
        $this->assertSame(UserRole::Tester, $tester->refresh()->role);
    }

    public function test_admin_cannot_change_own_role_or_demote_last_admin(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->from('/admin/users')->patch("/admin/users/{$admin->id}/role", ['role' => 'TESTER'])
            ->assertSessionHasErrors('role');
        $this->assertSame(UserRole::Admin, $admin->refresh()->role);

        $this->actingAs($admin)->from('/admin/users')->patch("/admin/users/{$admin->id}/role", ['role' => 'NOPE'])
            ->assertSessionHasErrors('role');
    }

    public function test_last_admin_cannot_be_demoted_by_another_admin_once_alone(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();

        // Demoting $other leaves $admin as the only ADMIN: allowed.
        $this->actingAs($admin)->patch("/admin/users/{$other->id}/role", ['role' => 'TESTER'])->assertSessionHasNoErrors();
        $this->assertSame(UserRole::Tester, $other->refresh()->role);

        // The action itself refuses to demote the last ADMIN.
        $this->expectException(ValidationException::class);
        app(UpdateUserRoleAction::class)->execute($other, $admin, UserRole::Tester);
    }

    public function test_tester_cannot_change_roles(): void
    {
        $tester = User::factory()->tester()->create();
        $victim = User::factory()->tester()->create();

        $this->actingAs($tester)->patch("/admin/users/{$victim->id}/role", ['role' => 'ADMIN'])->assertForbidden();
        $this->assertSame(UserRole::Tester, $victim->refresh()->role);
    }

    // --- M11 / M15 model override ------------------------------------------------------

    public function test_m11_and_m15_do_not_prefill_web_side_model_guess(): void
    {
        [$tester, , , $suite] = $this->ownedSuite();

        $this->actingAs($tester)->get("/suites/{$suite->id}/generation-jobs/create")
            ->assertOk()
            ->assertSee('x-model="model" :disabled="!usesLlm" value=""', false)
            ->assertSee('placeholder="Mặc định của hệ thống"', false);

        $this->actingAs($tester)->get('/experiments')
            ->assertOk()
            ->assertSee("model: '',", false)
            ->assertDontSee('model: options.defaults.model', false);
    }

    // --- M09 bulk archive confirm ------------------------------------------------------

    public function test_m09_bulk_archive_asks_for_confirmation(): void
    {
        [$tester, , , $suite] = $this->ownedSuite();

        $this->actingAs($tester)->get("/suites/{$suite->id}")
            ->assertOk()
            ->assertSee('data-testid="bulk-archive"', false)
            ->assertSee("window.confirm('Lưu trữ ' + selectedIds.length", false);
    }

    // --- M07 → M09 link and create-suite modal -----------------------------------------

    public function test_m07_links_to_suite_creation_instead_of_stub_alert(): void
    {
        [$tester, $project, $spec] = $this->ownedSuite();

        $this->actingAs($tester)->get("/projects/{$project->id}/specs/{$spec->id}")
            ->assertOk()
            ->assertDontSee('sẽ được kích hoạt ở Sprint 3')
            ->assertSee('data-testid="m07-create-suite-link"', false)
            ->assertSee(e(route('projects.suites.index', ['project' => $project, 'create' => 1, 'api_spec_id' => $spec->id])), false)
            ->assertSee('<title>Danh sách endpoint', false)
            ->assertSee('href="'.route('projects.index').'" class="transition-colors hover:text-teal-300', false);

        $this->actingAs($tester)->get("/projects/{$project->id}/suites?create=1&api_spec_id={$spec->id}")
            ->assertOk()
            ->assertSee('createModalOpen: true', false)
            ->assertSee('value="'.$spec->id.'" selected', false);
    }

    public function test_create_suite_modal_keeps_input_after_validation_failure(): void
    {
        [$tester, $project, $spec] = $this->ownedSuite();

        $this->actingAs($tester)
            ->from("/projects/{$project->id}/suites")
            ->post("/projects/{$project->id}/suites", [
                '_form' => 'create-suite',
                'api_spec_id' => $spec->id,
                'name' => '   ',
                'description' => 'Mô tả đã nhập',
            ])
            ->assertRedirect("/projects/{$project->id}/suites")
            ->assertSessionHasErrors('name');

        $this->actingAs($tester)->get("/projects/{$project->id}/suites")
            ->assertSee('createModalOpen: true', false)
            ->assertSee('Mô tả đã nhập')
            ->assertSee('value="'.$spec->id.'" selected', false);
    }

    // --- M06 copy and text mode -------------------------------------------------------

    public function test_m06_copy_and_text_mode_is_restored_after_failed_paste(): void
    {
        [$tester, $project] = $this->ownedSuite();

        $this->actingAs($tester)->get("/projects/{$project->id}/specs")
            ->assertOk()
            ->assertDontSee('Swagger 2.0')
            ->assertDontSee('\text{MB}', false)
            ->assertSee('<title>Đặc tả OpenAPI', false)
            ->assertSee("inputMethod: 'file'", false)
            ->assertSee(':disabled="submitting"', false);

        $this->actingAs($tester)
            ->withSession(['_old_input' => ['input_method' => 'text', 'spec_text' => 'openapi: broken']])
            ->get("/projects/{$project->id}/specs")
            ->assertSee("inputMethod: 'text'", false)
            ->assertSee('openapi: broken');
    }

    // --- Vietnamese status labels -----------------------------------------------------

    public function test_run_status_is_shown_with_vietnamese_label_in_suite_and_export(): void
    {
        [$tester, , , $suite] = $this->ownedSuite();
        $run = TestRun::factory()->create([
            'test_suite_id' => $suite->id,
            'triggered_by' => $tester->id,
            'status' => TestRunStatus::Completed,
            'totals' => ['total' => 0, 'pass' => 0, 'fail' => 0, 'error' => 0, 'skipped' => 0],
        ]);

        $this->assertSame('Hoàn thành', TestRunStatus::Completed->label());

        $this->actingAs($tester)->get("/suites/{$suite->id}")
            ->assertOk()
            ->assertSee('Hoàn thành');

        $export = $this->actingAs($tester)->get("/api/v1/runs/{$run->id}/export?format=html")->assertOk();
        $this->assertStringContainsString('Trạng thái: Hoàn thành', (string) $export->getContent());
        $this->assertStringNotContainsString('Trạng thái: COMPLETED', (string) $export->getContent());
    }

    public function test_generation_status_is_shown_with_vietnamese_label(): void
    {
        [$tester, $project, $spec, $suite] = $this->ownedSuite();
        $job = GenerationJob::factory()->create([
            'project_id' => $project->id,
            'api_spec_id' => $spec->id,
            'test_suite_id' => $suite->id,
            'created_by' => $tester->id,
            'status' => GenerationJobStatus::Queued,
        ]);

        $this->actingAs($tester)->get("/generation-jobs/{$job->id}")
            ->assertOk()
            ->assertSee('Đang chờ')
            // Cancel button follows polled status (§4.1: cancel only from QUEUED).
            ->assertSee('data-testid="cancel-generation-form"', false)
            ->assertSee('x-show="status === \'QUEUED\'"', false)
            // Polling stops on non-retryable 4xx and redirects to login on 401.
            ->assertSee('this.stopPolling();', false)
            ->assertSee('res.status === 401', false);
    }

    // --- Accessibility & layout -------------------------------------------------------

    public function test_layout_and_modals_expose_aria_state(): void
    {
        [$tester, $project] = $this->ownedSuite();

        $this->actingAs($tester)->get('/projects')
            ->assertOk()
            ->assertSee('aria-label="Mở/đóng menu điều hướng"', false)
            ->assertSee(':aria-expanded="mobileOpen.toString()"', false)
            ->assertSee('aria-controls="account-menu"', false)
            ->assertSee('role="dialog" aria-modal="true"', false)
            ->assertSee('aria-label="Đóng hộp thoại"', false)
            ->assertSee('flex flex-wrap items-center justify-between gap-2 border-t', false);
    }

    public function test_run_screen_has_aria_state_and_bounded_cancel_polling(): void
    {
        [$tester, , , $suite] = $this->ownedSuite();
        $run = TestRun::factory()->create(['test_suite_id' => $suite->id, 'triggered_by' => $tester->id]);

        $this->actingAs($tester)->get("/runs/{$run->id}")
            ->assertOk()
            ->assertSee(':aria-pressed="(verdictFilter === \'PASS\').toString()"', false)
            ->assertSee(':aria-expanded="(!!expanded[result.id]).toString()"', false)
            ->assertSee('MAX_CANCEL_SETTLE_POLLS', false)
            ->assertSee('flex flex-wrap items-center gap-x-2', false);
    }

    // --- M12 default target -----------------------------------------------------------

    public function test_m12_prefills_project_default_target(): void
    {
        [$tester, $project, , $suite] = $this->ownedSuite();
        $project->update(['default_target_url' => 'http://benchmark:8000/v2']);
        AllowedTarget::factory()->create(['host' => 'benchmark']);

        $this->actingAs($tester)->get("/suites/{$suite->id}/runs")
            ->assertOk()
            ->assertSee('<option value="http://benchmark:8000/v2">Target mặc định của dự án</option>', false)
            ->assertSee('config.defaultTarget', false);
    }

    // --- M11 duplicate errors and M10 lost edit ------------------------------------------

    public function test_m11_no_js_validation_errors_are_rendered_once(): void
    {
        [$tester, , , $suite] = $this->ownedSuite();

        $this->actingAs($tester)
            ->from("/suites/{$suite->id}/generation-jobs/create")
            ->post("/suites/{$suite->id}/generation-jobs", ['strategy' => 'BOGUS'])
            ->assertSessionHasErrors('strategy');

        $html = (string) $this->actingAs($tester)->get("/suites/{$suite->id}/generation-jobs/create")->getContent();
        $this->assertSame(1, substr_count($html, 'Chiến lược sinh test phải là RULE, LLM hoặc HYBRID.'));
    }

    public function test_m10_keeps_edited_dsl_after_validation_redirect(): void
    {
        [$tester, , , $suite, $endpoint] = $this->ownedSuite();
        $case = TestCaseModel::factory()->create(['test_suite_id' => $suite->id, 'endpoint_id' => $endpoint->id]);

        $this->actingAs($tester)
            ->withSession(['_old_input' => ['title' => 'Sửa', 'dsl' => '{"broken": ']])
            ->get("/test-cases/{$case->id}")
            ->assertOk()
            ->assertSee('{"broken": ');

        $this->actingAs($tester)
            ->withSession(['_old_input' => ['title' => 'Sửa', 'dsl' => ['dsl_version' => '1.0', 'edited_marker' => true]]])
            ->get("/test-cases/{$case->id}")
            ->assertOk()
            ->assertSee('edited_marker');
    }
}
