<?php

namespace Tests\Feature;

use App\Actions\Generation\SummarizeGenerationJobAction;
use App\Enums\ApiSpecStatus;
use App\Enums\BenchmarkBugCategory;
use App\Enums\ExperimentStatus;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\LlmPurpose;
use App\Enums\TestCaseStatus;
use App\Enums\TestRunStatus;
use App\Enums\TestTechnique;
use App\Models\ApiSpec;
use App\Models\Benchmark;
use App\Models\BenchmarkBug;
use App\Models\Endpoint;
use App\Models\Experiment;
use App\Models\ExperimentBugDetection;
use App\Models\GenerationJob;
use App\Models\LlmCallLog;
use App\Models\Project;
use App\Models\TestCase as TestCaseModel;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Regression tests for the S10-01 web-ui audit (round 2).
 */
class WebUiAuditRound2Test extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Project, 2: ApiSpec, 3: TestSuite, 4: Endpoint}
     */
    private function ownedSuite(string $projectName = 'Dự án A'): array
    {
        $tester = User::factory()->tester()->create();
        $project = Project::factory()->for($tester, 'owner')->create(['name' => $projectName]);
        $spec = ApiSpec::factory()->for($project)->create(['status' => ApiSpecStatus::Parsed]);
        $suite = TestSuite::factory()->create([
            'project_id' => $project->id,
            'api_spec_id' => $spec->id,
            'created_by' => $tester->id,
        ]);
        $endpoint = Endpoint::factory()->create(['api_spec_id' => $spec->id, 'path' => '/items']);

        return [$tester, $project, $spec, $suite, $endpoint];
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function html(TestResponse $response): string
    {
        $response->assertOk();

        return (string) $response->getContent();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function job(TestSuite $suite, User $user, array $attributes = []): GenerationJob
    {
        return GenerationJob::factory()->create(array_merge([
            'project_id' => $suite->project_id,
            'api_spec_id' => $suite->api_spec_id,
            'test_suite_id' => $suite->id,
            'created_by' => $user->id,
        ], $attributes));
    }

    // --- M11 reachable from M09 / M10 ------------------------------------------------------

    public function test_m09_lists_recent_generation_jobs_linking_to_m11(): void
    {
        [$tester, , , $suite] = $this->ownedSuite();
        $job = $this->job($suite, $tester, ['strategy' => GenerationStrategy::Llm, 'status' => GenerationJobStatus::Running]);

        $this->actingAs($tester)->get(route('suites.show', $suite))
            ->assertOk()
            ->assertSee('Tiến trình sinh test gần đây')
            ->assertSee('href="'.route('generation-jobs.show', $job).'"', false)
            ->assertSee('Đang chạy');
    }

    public function test_m10_links_the_generation_job_of_the_test_case(): void
    {
        [$tester, , , $suite, $endpoint] = $this->ownedSuite();
        $job = $this->job($suite, $tester);
        $case = TestCaseModel::factory()->create([
            'test_suite_id' => $suite->id,
            'endpoint_id' => $endpoint->id,
            'generation_job_id' => $job->id,
        ]);

        $this->actingAs($tester)->get(route('test-cases.show', $case))
            ->assertOk()
            ->assertSee('href="'.route('generation-jobs.show', $job).'"', false)
            ->assertSee('Tiến trình sinh #'.$job->id);
    }

    // --- Global nav never targets an arbitrary project ---------------------------------------

    public function test_top_nav_spec_and_rule_links_do_not_leak_the_m05_loop_project(): void
    {
        $tester = User::factory()->tester()->create();
        $first = Project::factory()->for($tester, 'owner')->create(['name' => 'Đầu tiên']);
        $last = Project::factory()->for($tester, 'owner')->create(['name' => 'Cuối cùng']);

        $html = $this->html($this->actingAs($tester)->get('/projects'));

        // Each card may link its own M06/M08, but the global nav must point to the project list.
        $nav = substr($html, 0, (int) strpos($html, '<main'));
        $this->assertStringNotContainsString(route('projects.specs.index', $last), $nav);
        $this->assertStringNotContainsString(route('projects.specs.index', $first), $nav);
        $this->assertStringNotContainsString(route('projects.business-rules.index', $last), $nav);
        $this->assertStringContainsString(route('projects.index'), $nav);
    }

    public function test_top_nav_points_to_the_route_project_on_project_scoped_screens(): void
    {
        [$tester, $project] = $this->ownedSuite();

        $html = $this->html($this->actingAs($tester)->get(route('projects.specs.index', $project)));
        $nav = substr($html, 0, (int) strpos($html, '<main'));

        $this->assertStringContainsString(route('projects.business-rules.index', $project), $nav);
    }

    public function test_admin_nav_collapses_into_menu_below_xl(): void
    {
        $html = $this->html($this->actingAs(User::factory()->admin()->create())->get('/dashboard'));

        // S14-04: the sidebar is fixed from xl and an off-canvas drawer behind the menu button below.
        $this->assertMatchesRegularExpression('/<aside id="app-sidebar"[^>]*class="invisible -translate-x-full[^"]*xl:visible[^"]*xl:translate-x-0/', $html);
        $this->assertStringContainsString('class="flex xl:hidden"', $html);
        $this->assertStringNotContainsString('hidden md:flex', $html);
    }

    // --- Pagination styled for the dark UI ----------------------------------------------------

    public function test_pagination_uses_dark_app_view_not_light_vendor_view(): void
    {
        [$tester, , , $suite, $endpoint] = $this->ownedSuite();
        TestCaseModel::factory()->count(16)->create(['test_suite_id' => $suite->id, 'endpoint_id' => $endpoint->id]);

        $this->actingAs($tester)->get(route('suites.show', $suite))
            ->assertOk()
            ->assertSee('ait-pagination', false)
            ->assertSee('aria-label="Phân trang"', false)
            ->assertSee('kết quả')
            ->assertDontSee('bg-white', false)
            ->assertDontSee('text-gray-700', false);
    }

    // --- Vietnamese enum labels -----------------------------------------------------------------

    public function test_every_displayed_enum_has_a_vietnamese_label(): void
    {
        foreach ([TestCaseStatus::cases(), TestTechnique::cases(), BenchmarkBugCategory::cases(), LlmPurpose::cases(), GenerationJobStatus::cases()] as $cases) {
            foreach ($cases as $case) {
                $this->assertNotSame($case->value, $case->label());
                $this->assertNotSame('', $case->label());
            }
        }
    }

    public function test_m09_and_m10_show_test_case_status_labels(): void
    {
        [$tester, , , $suite, $endpoint] = $this->ownedSuite();
        $case = TestCaseModel::factory()->create([
            'test_suite_id' => $suite->id,
            'endpoint_id' => $endpoint->id,
            'status' => TestCaseStatus::Approved,
        ]);

        $this->actingAs($tester)->get(route('suites.show', $suite))
            ->assertOk()
            ->assertSee('Đã duyệt (APPROVED)')
            ->assertSee('title="Đã duyệt (APPROVED)">', false)
            ->assertSee('Đã duyệt');

        $this->actingAs($tester)->get(route('test-cases.show', $case))
            ->assertOk()
            ->assertSee('Đã duyệt')
            ->assertSee('statusLabels', false);
    }

    public function test_m09_m10_m11_show_test_technique_labels_with_code_in_title(): void
    {
        [$tester, , , $suite, $endpoint] = $this->ownedSuite();
        $job = $this->job($suite, $tester);
        $case = TestCaseModel::factory()->create([
            'test_suite_id' => $suite->id,
            'endpoint_id' => $endpoint->id,
            'generation_job_id' => $job->id,
            'technique' => TestTechnique::MissingRequired,
        ]);

        $this->actingAs($tester)->get(route('suites.show', $suite))
            ->assertOk()
            ->assertSee('Thiếu trường bắt buộc (MISSING_REQUIRED)')
            ->assertSee('title="Thiếu trường bắt buộc (MISSING_REQUIRED)"', false);

        $this->actingAs($tester)->get(route('test-cases.show', $case))
            ->assertOk()
            ->assertSee('title="Thiếu trường bắt buộc (MISSING_REQUIRED)">Thiếu trường bắt buộc</span>', false);

        $this->actingAs($tester)->get(route('generation-jobs.show', $job))
            ->assertOk()
            ->assertSee('title="Thiếu trường bắt buộc (MISSING_REQUIRED)"', false)
            ->assertSee('Thiếu trường bắt buộc');
    }

    public function test_bug_category_role_and_health_are_shown_in_vietnamese(): void
    {
        $admin = User::factory()->admin()->create();
        $benchmark = Benchmark::factory()->create();
        BenchmarkBug::factory()->create(['benchmark_id' => $benchmark->id, 'category' => BenchmarkBugCategory::BoundaryError]);

        $this->actingAs($admin)->get(route('admin.benchmarks.index'))
            ->assertOk()
            ->assertSee('Lỗi biên');

        $this->actingAs($admin)->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('Quản trị viên')
            ->assertDontSee('>ADMIN<', false);

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertSee('('.$admin->role->label().')')
            ->assertDontSee('Degraded');
    }

    // --- M15 matrix a11y + single init --------------------------------------------------------

    public function test_m15_comparison_matrix_has_text_alternative_and_single_init(): void
    {
        $benchmark = Benchmark::factory()->create();
        $bug = BenchmarkBug::factory()->create(['benchmark_id' => $benchmark->id, 'category' => BenchmarkBugCategory::AuthError]);
        $experiment = Experiment::factory()->create([
            'benchmark_id' => $benchmark->id,
            'strategy' => GenerationStrategy::Rule,
            'status' => ExperimentStatus::Completed,
        ]);
        $run = TestRun::factory()->create();
        ExperimentBugDetection::factory()->create([
            'experiment_id' => $experiment->id,
            'benchmark_bug_id' => $bug->id,
            'repetition' => 1,
            'detected' => false,
            'clean_test_run_id' => $run->id,
            'bug_test_run_id' => $run->id,
        ]);

        $this->actingAs(User::factory()->tester()->create())->get('/experiments')
            ->assertOk()
            ->assertSee('<span class="sr-only">Lần 1: Không phát hiện</span>', false)
            ->assertSee('aria-hidden="true">✗</span>', false)
            ->assertSee('Lỗi phân quyền')
            ->assertSee('class="relative mt-3 overflow-x-auto', false)
            ->assertDontSee('x-init="init()"', false);

        // sr-only cells are position:absolute: the scroll wrapper must be their containing block,
        // otherwise they escape overflow-x-auto and widen the page at 375 px.
        $this->actingAs(User::factory()->tester()->create())->get('/experiments/'.$experiment->id)
            ->assertOk()
            ->assertSee('<span class="sr-only">Không phát hiện</span>', false)
            ->assertDontSee('<div class="overflow-x-auto">', false);

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('class="relative overflow-x-auto', false)
            ->assertDontSee('<div class="overflow-x-auto">', false);
    }

    // --- Modal close buttons, form labels, live regions ----------------------------------------

    public function test_m03_and_m08_modal_close_buttons_and_inputs_are_labelled(): void
    {
        [$tester, $project] = $this->ownedSuite();

        $this->actingAs($tester)->get(route('projects.business-rules.index', $project))
            ->assertOk()
            ->assertSee('aria-labelledby="create-rule-modal-title"', false)
            ->assertSee('aria-labelledby="edit-rule-modal-title"', false)
            ->assertSee('@click="createModalOpen = false" aria-label="Đóng hộp thoại"', false)
            ->assertSee('@click="editModalOpen = false" aria-label="Đóng hộp thoại"', false)
            ->assertSee('<label for="rule-search" class="sr-only">', false)
            ->assertSee('<label for="rule-endpoint-filter" class="sr-only">', false);

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('aria-labelledby="create-user-modal-title"', false)
            ->assertSee('<button type="button" @click="createModalOpen = false" aria-label="Đóng hộp thoại"', false);
    }

    public function test_m06_file_input_and_m07_search_are_labelled(): void
    {
        [$tester, $project, $spec] = $this->ownedSuite();

        $this->actingAs($tester)->get(route('projects.specs.index', $project))
            ->assertOk()
            ->assertSee('aria-describedby="spec-file-hint"', false)
            ->assertSee('id="spec-file-hint"', false);

        $this->actingAs($tester)->get(route('projects.specs.show', [$project, $spec]))
            ->assertOk()
            ->assertSee('<label for="endpoint-search" class="sr-only">', false);
    }

    public function test_async_error_banners_are_live_regions_and_submit_stays_locked_after_success(): void
    {
        [$tester, , , $suite] = $this->ownedSuite();

        $runs = $this->html($this->actingAs($tester)->get(route('suites.runs.index', $suite)));
        $this->assertStringContainsString('x-show="errorMessage !== \'\'" x-cloak role="alert"', $runs);
        $this->assertDoesNotMatchRegularExpression('/finally\s*\{\s*this\.submitting = false;/', $runs);

        $generation = $this->html($this->actingAs($tester)->get(route('suites.generation-jobs.create', $suite)));
        $this->assertStringContainsString('x-show="errorMessage" x-cloak role="alert"', $generation);
        $this->assertDoesNotMatchRegularExpression('/finally\s*\{\s*this\.submitting = false;/', $generation);

        Benchmark::factory()->create();
        $this->actingAs($tester)->get('/experiments')
            ->assertOk()
            ->assertSee('x-text="error" role="alert"', false)
            ->assertSee('x-text="message" role="status"', false);
    }

    // --- M10 assertion chip and LLM log -------------------------------------------------------

    public function test_m10_assertion_chip_keeps_unicode_and_slashes(): void
    {
        [$tester, , , $suite, $endpoint] = $this->ownedSuite();
        $case = TestCaseModel::factory()->create(['test_suite_id' => $suite->id, 'endpoint_id' => $endpoint->id]);
        $dsl = $case->dsl;
        $dsl['steps'][0]['assertions'] = [['type' => 'json_path', 'path' => '$.data/items', 'equals' => 'Đã hủy']];
        $case->forceFill(['dsl' => $dsl])->save();

        $this->actingAs($tester)->get(route('test-cases.show', $case))
            ->assertOk()
            ->assertSee('json_path: {&quot;path&quot;:&quot;$.data/items&quot;,&quot;equals&quot;:&quot;Đã hủy&quot;}', false)
            ->assertDontSee('&quot;equals&quot;:&quot;'.chr(92).'u0110', false)
            ->assertDontSee('\/items', false);
    }

    public function test_m10_llm_log_shows_error_and_null_tokens_as_dash(): void
    {
        [$tester, , , $suite, $endpoint] = $this->ownedSuite();
        $job = $this->job($suite, $tester, ['strategy' => GenerationStrategy::Llm, 'status' => GenerationJobStatus::Succeeded]);
        $case = TestCaseModel::factory()->create([
            'test_suite_id' => $suite->id,
            'endpoint_id' => $endpoint->id,
            'generation_job_id' => $job->id,
        ]);
        LlmCallLog::factory()->create([
            'generation_job_id' => $job->id,
            'test_case_id' => $case->id,
            'purpose' => LlmPurpose::Repair,
            'prompt_tokens' => null,
            'completion_tokens' => null,
            'error' => 'timed out',
        ]);

        $this->actingAs($tester)->get(route('test-cases.show', $case))
            ->assertOk()
            ->assertSee('Lỗi khi gọi LLM:')
            ->assertSee('timed out')
            ->assertSee('Tokens: <strong class="text-slate-200">—</strong> (in: —, out: —)', false)
            ->assertDontSee('Tokens: <strong class="text-slate-200">0</strong>', false)
            ->assertSee('Tự sửa');
    }

    public function test_repaired_counter_counts_only_successful_repairs(): void
    {
        [$tester, , , $suite, $endpoint] = $this->ownedSuite();
        $job = $this->job($suite, $tester, ['strategy' => GenerationStrategy::Llm, 'status' => GenerationJobStatus::Partial, 'stats' => null]);
        TestCaseModel::factory()->create([
            'test_suite_id' => $suite->id, 'endpoint_id' => $endpoint->id, 'generation_job_id' => $job->id,
            'status' => TestCaseStatus::Invalid, 'repair_attempts' => 2, 'fingerprint' => null,
        ]);
        TestCaseModel::factory()->create([
            'test_suite_id' => $suite->id, 'endpoint_id' => $endpoint->id, 'generation_job_id' => $job->id,
            'status' => TestCaseStatus::Valid, 'repair_attempts' => 1,
        ]);

        $summary = app(SummarizeGenerationJobAction::class)->execute($job->refresh());

        $this->assertSame(1, $summary->counts['repaired']);
        $this->assertSame(1, $summary->endpoints[0]['repaired']);
    }

    // --- M13 breadcrumb ------------------------------------------------------------------------

    public function test_m13_breadcrumb_wraps_and_links_back_to_m12(): void
    {
        [$tester, , , $suite] = $this->ownedSuite();
        $run = TestRun::factory()->create([
            'test_suite_id' => $suite->id,
            'triggered_by' => $tester->id,
            'status' => TestRunStatus::Completed,
        ]);

        $this->actingAs($tester)->get(route('runs.report', $run))
            ->assertOk()
            ->assertSee('text-xs text-slate-400 mb-1 min-w-0 break-all', false)
            ->assertSee('href="'.route('suites.runs.index', $suite).'"', false)
            ->assertSee('id="report-run-link" href="'.route('runs.show', $run).'"', false);
    }

    // --- Time zone consistency -------------------------------------------------------------------

    public function test_server_rendered_times_use_display_timezone_matching_m12(): void
    {
        config(['ait.display_timezone' => 'Asia/Ho_Chi_Minh']);
        [$tester, , , $suite] = $this->ownedSuite();
        $run = TestRun::factory()->create([
            'test_suite_id' => $suite->id,
            'triggered_by' => $tester->id,
            'status' => TestRunStatus::Completed,
            'started_at' => Carbon::parse('2026-10-11 12:32:00', 'UTC'),
            'finished_at' => Carbon::parse('2026-10-11 12:33:00', 'UTC'),
        ]);
        $job = $this->job($suite, $tester, [
            'status' => GenerationJobStatus::Succeeded,
            'started_at' => Carbon::parse('2026-10-11 12:30:18', 'UTC'),
        ]);

        $this->actingAs($tester)->get(route('runs.report', $run))
            ->assertOk()
            ->assertSee('11/10/2026 19:32')
            ->assertDontSee('11/10/2026 12:32');

        $this->actingAs($tester)->get(route('runs.show', $run))
            ->assertOk()
            ->assertSee('displayTimezone', false)
            ->assertSee('Ho_Chi_Minh', false)
            ->assertSee('timeZone: config.displayTimezone', false);

        $this->actingAs($tester)->get(route('generation-jobs.show', $job))
            ->assertOk()
            ->assertSee('19:30:18 11/10/2026');
    }

    // --- CSS theme ---------------------------------------------------------------------------------

    public function test_theme_defines_used_slate_shades_and_loaded_font(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString("--font-sans: 'Inter'", $css);
        $this->assertStringNotContainsString('Instrument Sans', $css);
        $this->assertStringContainsString('--color-slate-850:', $css);
        $this->assertStringContainsString('--color-slate-750:', $css);
        $this->assertStringNotContainsString('Illuminate/Pagination/resources/views', $css);
    }
}
