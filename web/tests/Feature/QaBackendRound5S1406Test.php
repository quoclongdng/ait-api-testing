<?php

namespace Tests\Feature;

use App\Actions\Generation\ConvergeRecordedLlmJobsAction;
use App\Actions\Runs\ShowRunReportAction;
use App\Actions\TestCases\CountApprovedTestCasesAction;
use App\Data\AdminOverview;
use App\Enums\ApiSpecStatus;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\HttpMethod;
use App\Enums\TestCaseSource;
use App\Enums\TestCaseStatus;
use App\Enums\TestRunStatus;
use App\Enums\TestVerdict;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\TestCase as TestCaseModel;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\Dsl\DslValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Live QA round 5 (S14-06), backend group: ADMIN aggregates leave out soft-deleted projects, a
 * re-upload of failed content repeats the parse errors, schema-level DSL errors name a step
 * "Bước N" (1-based) and a totals-only run treats a 0 s and a 1 s gap alike.
 */
class QaBackendRound5S1406Test extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Project, 2: TestSuite}
     */
    private function suiteOf(string $projectName): array
    {
        $tester = User::factory()->tester()->create();
        $project = Project::factory()->for($tester, 'owner')->create(['name' => $projectName]);
        $spec = ApiSpec::factory()->for($project)->create(['status' => ApiSpecStatus::Parsed]);
        $suite = TestSuite::factory()->create([
            'project_id' => $project->id,
            'api_spec_id' => $spec->id,
            'created_by' => $tester->id,
            'name' => $projectName.' suite',
        ]);

        return [$tester, $project, $suite];
    }

    private function runOf(TestSuite $suite, User $tester, TestRunStatus $status, TestVerdict $verdict): TestRun
    {
        $run = TestRun::factory()->create([
            'test_suite_id' => $suite->id,
            'triggered_by' => $tester->id,
            'status' => $status,
            'finished_at' => $status === TestRunStatus::Completed ? now() : null,
        ]);
        TestResult::factory()->create(['test_run_id' => $run->id, 'verdict' => $verdict]);

        return $run;
    }

    // --- ADMIN M02 and soft-deleted projects ---------------------------------------------------

    public function test_admin_dashboard_leaves_out_runs_and_cases_of_deleted_projects(): void
    {
        $admin = User::factory()->admin()->create();
        [$liveTester, , $liveSuite] = $this->suiteOf('LIVE-PROJECT');
        [$goneTester, $goneProject, $goneSuite] = $this->suiteOf('QA4-JOURNEY-RULE');

        $live = $this->runOf($liveSuite, $liveTester, TestRunStatus::Completed, TestVerdict::Pass);
        $gone = $this->runOf($goneSuite, $goneTester, TestRunStatus::Completed, TestVerdict::Fail);
        $goneFailed = $this->runOf($goneSuite, $goneTester, TestRunStatus::Failed, TestVerdict::Fail);
        $goneActive = TestRun::factory()->create([
            'test_suite_id' => $goneSuite->id, 'triggered_by' => $goneTester->id, 'status' => TestRunStatus::Running,
        ]);
        TestCaseModel::factory()->create(['test_suite_id' => $liveSuite->id, 'status' => TestCaseStatus::Approved]);
        TestCaseModel::factory()->create(['test_suite_id' => $goneSuite->id, 'status' => TestCaseStatus::Approved]);

        // Soft delete as M05 does, after the run finished (the running one stays RUNNING here so
        // that the "đang chờ/chạy" count is checked too).
        $goneProject->delete();

        $response = $this->actingAs($admin)->get('/dashboard')->assertOk()
            ->assertViewHas('overview', function (AdminOverview $overview) use ($live): bool {
                return $overview->runsLast7Days === 1
                    && $overview->completedRunsLast7Days === 1
                    && $overview->failedRunsLast7Days === 0
                    && $overview->activeRuns === 0
                    && $overview->passRateLast7Days === 1.0
                    && $overview->projects === 1
                    && $overview->recentRuns->pluck('id')->all() === [$live->id];
            });

        $response->assertSeeText('LIVE-PROJECT suite')
            ->assertDontSeeText('QA4-JOURNEY-RULE suite');
        foreach ([$gone, $goneFailed, $goneActive] as $run) {
            $response->assertDontSee("/runs/{$run->id}\"", escape: false);
            // The rows that used to be listed were 404 for the ADMIN as well.
            $this->actingAs($admin)->get("/runs/{$run->id}")->assertNotFound();
        }

        // The ADMIN branch of the approved-case count (not shown on the ADMIN M02 today, but any
        // caller must not count cases of a deleted project either).
        $this->assertSame(1, app(CountApprovedTestCasesAction::class)->execute($admin));
    }

    public function test_admin_still_sees_runs_of_every_live_project(): void
    {
        $admin = User::factory()->admin()->create();
        [$a, , $suiteA] = $this->suiteOf('PROJECT-A');
        [$b, , $suiteB] = $this->suiteOf('PROJECT-B');
        $runA = $this->runOf($suiteA, $a, TestRunStatus::Completed, TestVerdict::Pass);
        $runB = $this->runOf($suiteB, $b, TestRunStatus::Completed, TestVerdict::Fail);

        $this->actingAs($admin)->get('/dashboard')->assertOk()
            ->assertViewHas('overview', fn (AdminOverview $overview): bool => $overview->runsLast7Days === 2
                && $overview->passRateLast7Days === 0.5
                && $overview->recentRuns->pluck('id')->sort()->values()->all() === [$runA->id, $runB->id]);
    }

    public function test_tester_dashboard_is_unchanged_by_the_live_project_scope(): void
    {
        [$tester, $project, $suite] = $this->suiteOf('OWN');
        $run = $this->runOf($suite, $tester, TestRunStatus::Completed, TestVerdict::Pass);

        $this->actingAs($tester)->get('/dashboard')->assertOk()
            ->assertViewHas('recentRuns', fn ($runs): bool => $runs->pluck('id')->all() === [$run->id]);

        $project->delete();
        $this->actingAs($tester)->get('/dashboard')->assertOk()
            ->assertViewHas('recentRuns', fn ($runs): bool => $runs->isEmpty());
    }

    // --- M06: re-upload of content that already failed to parse --------------------------------

    public function test_reuploading_failed_content_repeats_the_stored_parse_errors(): void
    {
        [$tester, $project] = $this->suiteOf('SPECS');
        $content = "openapi: 3.0.3\ninfo:\n  title: !custom |\n    x\n  version: '1'\npaths: {}\n";

        $first = $this->actingAs($tester)->postJson("/api/v1/projects/{$project->id}/specs", [
            'version_label' => 'v-bad', 'format' => 'yaml', 'raw_content' => $content,
        ])->assertStatus(422)->assertJsonPath('error.code', 'SPEC_PARSE_FAILED');
        $reasons = $first->json('error.details.errors');
        $this->assertIsArray($reasons);
        $this->assertNotEmpty($reasons);

        $again = $this->actingAs($tester)->postJson("/api/v1/projects/{$project->id}/specs", [
            'version_label' => 'v-bad-2', 'format' => 'yaml', 'raw_content' => $content,
        ])->assertStatus(409)
            ->assertJsonPath('error.code', 'CONFLICT')
            ->assertJsonPath('error.details.existing_status', 'PARSE_FAILED')
            ->assertJsonPath('error.details.version_label', 'v-bad')
            ->assertJsonPath('error.details.errors', $reasons);
        $this->assertStringContainsString('không phân tích được', (string) $again->json('error.message'));
        $this->assertStringContainsString('"v-bad"', (string) $again->json('error.message'));

        // M06 shows the message and the reasons under the flash, as for a first failed upload.
        $this->actingAs($tester)->post("/projects/{$project->id}/specs", [
            'version_label' => 'v-bad-3', 'input_method' => 'text', 'spec_text' => $content,
        ])->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'không phân tích được'))
            ->assertSessionHas('spec_parse_errors', $reasons);

        $this->assertSame(1, ApiSpec::query()->where('project_id', $project->id)->where('status', ApiSpecStatus::ParseFailed)->count());
    }

    public function test_reuploading_parsed_content_keeps_the_plain_conflict(): void
    {
        [$tester, $project] = $this->suiteOf('SPECS-OK');
        $content = "openapi: 3.0.3\ninfo:\n  title: Ok\n  version: '1'\npaths:\n  /ping:\n    get:\n      responses:\n        '200':\n          description: ok\n";

        $this->actingAs($tester)->postJson("/api/v1/projects/{$project->id}/specs", [
            'version_label' => 'v1', 'format' => 'yaml', 'raw_content' => $content,
        ])->assertCreated();

        $this->actingAs($tester)->postJson("/api/v1/projects/{$project->id}/specs", [
            'version_label' => 'v2', 'format' => 'yaml', 'raw_content' => $content,
        ])->assertStatus(409)
            ->assertJsonPath('error.message', 'Bản đặc tả OpenAPI với nội dung này đã tồn tại trong dự án.')
            ->assertJsonPath('error.details.existing_status', 'PARSED')
            ->assertJsonMissingPath('error.details.errors');
    }

    // --- DSL schema errors: "Bước N" like the semantic errors -----------------------------------

    /**
     * @return array<string, mixed>
     */
    private function twoStepDsl(): array
    {
        $step = [
            'name' => 'Execute GET /items',
            'request' => ['method' => 'GET', 'path' => '/items'],
            'assertions' => [['type' => 'status', 'equals' => 200]],
        ];

        return [
            'dsl_version' => '1.0',
            'title' => 'two steps',
            'endpoint' => ['method' => 'GET', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [$step, ['name' => 'read again'] + $step],
        ];
    }

    public function test_schema_errors_use_one_based_step_numbers_like_semantic_errors(): void
    {
        $dsl = $this->twoStepDsl();
        unset($dsl['steps'][1]['assertions']);

        $this->assertSame(
            ["Bước 2 ('read again'): thiếu trường bắt buộc assertions"],
            (new DslValidator)->validate($dsl)->schemaErrors,
        );
    }

    public function test_schema_errors_number_assertions_and_extracts_from_one_and_keep_the_rest(): void
    {
        // opis reports the first problem only, so each shape is checked on its own document.
        $dsl = $this->twoStepDsl();
        $dsl['steps'][1]['assertions'][] = ['type' => 'status'];
        $this->assertSame(
            ["Bước 2 ('read again'), assertion #2: thiếu trường bắt buộc equals"],
            (new DslValidator)->validate($dsl)->schemaErrors,
        );

        $dsl = $this->twoStepDsl();
        $dsl['steps'][1]['extract'] = [['var' => 'id', 'from' => 'body', 'path' => '$.id'], ['var' => 'x']];
        $this->assertSame(
            ["Bước 2 ('read again'), extract #2: thiếu trường bắt buộc from"],
            (new DslValidator)->validate($dsl)->schemaErrors,
        );

        $dsl = $this->twoStepDsl();
        $dsl['steps'][0]['request']['headers'] = [];
        $errors = (new DslValidator)->validate($dsl)->schemaErrors;
        // The step name is quoted as written in the DSL JSON, like the semantic errors (S14-06 live QA r7).
        $this->assertContains("Bước 1 ('Execute GET /items'), request.headers: sai kiểu dữ liệu: cần đối tượng, nhận mảng", $errors);
        $this->assertSame([], array_filter($errors, fn (string $e): bool => str_contains($e, 'steps[')));
    }

    public function test_schema_errors_outside_steps_and_unnamed_steps(): void
    {
        $dsl = $this->twoStepDsl();
        unset($dsl['title']);
        $this->assertSame(['Gốc DSL: thiếu trường bắt buộc title'], (new DslValidator)->validate($dsl)->schemaErrors);

        $dsl = $this->twoStepDsl();
        $dsl['endpoint']['method'] = 'FETCH';
        $errors = (new DslValidator)->validate($dsl)->schemaErrors;
        $this->assertCount(1, $errors);
        $this->assertStringStartsWith('endpoint.method: ', $errors[0]);

        $dsl = $this->twoStepDsl();
        $dsl['steps'][0]['name'] = '';
        $this->assertSame(['Bước 1, name: chuỗi phải có ít nhất 1 ký tự'], (new DslValidator)->validate($dsl)->schemaErrors);
    }

    public function test_showcase_convergence_rewords_stored_zero_based_schema_errors_once(): void
    {
        // The QA evidence came from replayed showcase jobs whose cases were stored with the old
        // "steps[N]" wording: `ait:demo:showcase` (ConvergeRecordedLlmJobsAction) must bring them to
        // the "Bước N" wording on the next deploy, and a second run must change nothing.
        [$tester, $project, $suite] = $this->suiteOf('SHOWCASE');
        $endpoint = Endpoint::factory()->create([
            'api_spec_id' => $suite->api_spec_id,
            'method' => HttpMethod::Get,
            'path' => '/items',
            'responses' => ['200' => ['description' => 'OK']],
        ]);
        $replay = GenerationJob::factory()->create([
            'project_id' => $project->id,
            'api_spec_id' => $suite->api_spec_id,
            'test_suite_id' => $suite->id,
            'strategy' => GenerationStrategy::Llm,
            'status' => GenerationJobStatus::Succeeded,
            'params' => ['endpoint_ids' => [$endpoint->id], GenerationJob::RECORDED_SOURCE_PARAM => 6],
            'stats' => ['generated' => 1, 'valid' => 0, 'invalid' => 1, 'duplicates' => 0, 'repaired' => 0],
            'created_by' => $tester->id,
        ]);
        $dsl = $this->twoStepDsl();
        $dsl['source'] = 'LLM';
        unset($dsl['steps'][1]['assertions']);
        $case = TestCaseModel::factory()->create([
            'test_suite_id' => $suite->id,
            'endpoint_id' => $endpoint->id,
            'generation_job_id' => $replay->id,
            'source' => TestCaseSource::Llm,
            'dsl' => $dsl,
            'fingerprint' => null,
            'status' => TestCaseStatus::Invalid,
            'validation_errors' => ['steps[1]: thiếu trường bắt buộc assertions'],
        ]);

        $this->assertSame(['jobs' => 0, 'cases' => 1], app(ConvergeRecordedLlmJobsAction::class)->execute($suite));
        $this->assertSame(["Bước 2 ('read again'): thiếu trường bắt buộc assertions"], $case->refresh()->validation_errors);

        Carbon::setTestNow(now()->addHour());
        $stamp = (string) $case->updated_at;
        $this->assertSame(['jobs' => 0, 'cases' => 0], app(ConvergeRecordedLlmJobsAction::class)->execute($suite));
        $this->assertSame($stamp, (string) $case->refresh()->updated_at);
        Carbon::setTestNow();
    }

    // --- Totals-only runs: 0 s and 1 s gaps alike ----------------------------------------------

    public function test_totals_only_run_with_a_one_second_gap_has_no_duration_like_a_zero_gap(): void
    {
        [$tester, , $suite] = $this->suiteOf('RECORDS');
        $make = fn (string $start, string $end): TestRun => TestRun::factory()->create([
            'test_suite_id' => $suite->id,
            'triggered_by' => $tester->id,
            'status' => TestRunStatus::Completed,
            'started_at' => Carbon::parse($start),
            'finished_at' => Carbon::parse($end),
            'totals' => ['total' => 58, 'pass' => 58, 'fail' => 0, 'error' => 0, 'skipped' => 0],
        ]);
        $zero = $make('2026-10-12 12:44:16', '2026-10-12 12:44:16');
        $one = $make('2026-10-12 12:44:17', '2026-10-12 12:44:18');
        $two = $make('2026-10-12 12:44:17', '2026-10-12 12:44:19');

        foreach ([$zero, $one] as $run) {
            $this->assertNull(app(ShowRunReportAction::class)->execute($run)->summary->wallDurationMs);
            $this->actingAs($tester)->get("/runs/{$run->id}/report")->assertOk()
                ->assertSeeText('Thời lượng: không có số liệu')
                ->assertDontSeeText('1,0 giây');
            $this->actingAs($tester)->getJson("/api/v1/runs/{$run->id}/export?format=json")->assertOk()
                ->assertJsonPath('summary.wall_duration_ms', null);
            $html = (string) $this->actingAs($tester)->get("/api/v1/runs/{$run->id}/export?format=html")->assertOk()->getContent();
            $this->assertStringContainsString('Thời lượng: không có số liệu', $html);
        }

        // A longer record keeps its (second-precision) duration.
        $this->assertSame(2000, app(ShowRunReportAction::class)->execute($two)->summary->wallDurationMs);
    }
}
