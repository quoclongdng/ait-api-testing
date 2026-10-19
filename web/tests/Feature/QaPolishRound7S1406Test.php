<?php

namespace Tests\Feature;

use App\Enums\ApiSpecStatus;
use App\Enums\ExperimentStatus;
use App\Enums\HttpMethod;
use App\Enums\TestCaseSource;
use App\Enums\TestCaseStatus;
use App\Enums\TestRunStatus;
use App\Enums\TestTechnique;
use App\Enums\TestVerdict;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Models\Experiment;
use App\Models\Project;
use App\Models\TestCase as TestCaseModel;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\Dsl\DslValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression tests for the sixth S14-06 live QA round, group "r7": DSL errors quote the step name
 * as written in the DSL JSON (r7-1), the title column follows dsl.title after an M10 edit (r7-3),
 * and the TESTER shell accent of M06, M07 and the M13/M15 export buttons (r7-2).
 */
class QaPolishRound7S1406Test extends TestCase
{
    use RefreshDatabase;

    private User $tester;

    private Project $project;

    private ApiSpec $spec;

    private TestSuite $suite;

    private Endpoint $endpoint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tester = User::factory()->tester()->create();
        $this->project = Project::factory()->for($this->tester, 'owner')->create();
        $this->spec = ApiSpec::factory()->for($this->project)->create(['status' => ApiSpecStatus::Parsed]);
        $this->suite = TestSuite::factory()->create([
            'project_id' => $this->project->id,
            'api_spec_id' => $this->spec->id,
            'created_by' => $this->tester->id,
        ]);
        $this->endpoint = Endpoint::factory()->create([
            'api_spec_id' => $this->spec->id,
            'method' => HttpMethod::Post,
            'path' => '/categories',
            'responses' => ['201' => ['description' => 'Created'], '401' => ['description' => 'Unauthorized']],
        ]);
    }

    // r7-1 ------------------------------------------------------------------------------------

    public function test_schema_error_quotes_the_rule_step_name_as_written_in_the_dsl(): void
    {
        $case = $this->makeCase(TestCaseStatus::Valid);
        $dsl = $this->categoriesDsl('Tạo danh mục');
        $dsl['steps'][0]['request']['method'] = 'FETCH';

        $errors = $this->actingAs($this->tester)
            ->patchJson("/api/v1/test-cases/{$case->id}", ['dsl' => $dsl])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'DSL_INVALID')
            ->json('error.details.dsl');

        $this->assertIsArray($errors);
        $joined = implode("\n", $errors);
        $this->assertStringContainsString("Bước 1 ('Execute POST /categories'), request.method: ", $joined);
        $this->assertStringNotContainsString('Gọi POST', $joined);
    }

    public function test_semantic_error_quotes_the_rule_step_name_as_written_in_the_dsl(): void
    {
        $case = $this->makeCase(TestCaseStatus::Valid);
        $dsl = $this->categoriesDsl('Tạo danh mục');
        $dsl['steps'][0]['request']['path'] = '/qa6-nope';

        $this->actingAs($this->tester)
            ->patch("/test-cases/{$case->id}", ['title' => 'Original', 'dsl' => (string) json_encode($dsl)])
            ->assertRedirect("/test-cases/{$case->id}");

        $error = session('error');
        $message = is_string($error) ? $error : '';
        $this->assertStringContainsString("Bước 1 ('Execute POST /categories'): Endpoint 'POST /qa6-nope' không tồn tại", $message);
        $this->assertStringNotContainsString('Gọi POST', $message);

        // A user-chosen name is quoted verbatim too, and an unnamed step falls back to "Bước N".
        $dsl['steps'][0]['name'] = 'qa6_second';
        $this->assertStringStartsWith("Bước 1 ('qa6_second'): ", (new DslValidator)->validate($dsl, $this->spec)->semanticErrors[0]);
    }

    // r7-3 ------------------------------------------------------------------------------------

    public function test_m10_edit_of_dsl_title_updates_the_title_column(): void
    {
        $case = $this->makeCase(TestCaseStatus::Valid, ['title' => 'Xác thực: POST /categories không kèm thông tin xác thực bị từ chối']);

        // The M10 form always posts the title input; the user left it unchanged and edited dsl.title.
        $this->actingAs($this->tester)
            ->patch("/test-cases/{$case->id}", [
                'title' => 'Xác thực: POST /categories không kèm thông tin xác thực bị từ chối',
                'dsl' => (string) json_encode($this->categoriesDsl('QA6: POST /categories với token mặc định')),
            ])
            ->assertRedirect("/test-cases/{$case->id}")
            ->assertSessionHas('success');

        $case->refresh();
        $this->assertSame('QA6: POST /categories với token mặc định', $case->title);
        $this->assertSame('QA6: POST /categories với token mặc định', $case->dsl['title']);
        $this->assertSame(TestCaseStatus::Valid, $case->status);
        $this->assertSame(TestCaseSource::Manual, $case->source);

        // Lists and exports show the same title as the DSL that runs.
        $this->actingAs($this->tester)->getJson("/api/v1/suites/{$this->suite->id}/test-cases")
            ->assertOk()
            ->assertJsonPath('data.0.title', 'QA6: POST /categories với token mặc định');

        $run = TestRun::factory()->create([
            'test_suite_id' => $this->suite->id,
            'triggered_by' => $this->tester->id,
            'status' => TestRunStatus::Completed,
            'totals' => ['total' => 1, 'pass' => 1, 'fail' => 0, 'error' => 0, 'skipped' => 0],
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        TestResult::factory()->create(['test_run_id' => $run->id, 'test_case_id' => $case->id, 'verdict' => TestVerdict::Pass]);
        $csv = (string) $this->actingAs($this->tester)->get("/api/v1/runs/{$run->id}/export?format=csv")->assertOk()->getContent();
        $this->assertStringContainsString('QA6: POST /categories với token mặc định', $csv);
        $this->assertStringNotContainsString('không kèm thông tin xác thực', $csv);
    }

    public function test_changed_title_input_is_written_into_the_dsl_title(): void
    {
        $case = $this->makeCase(TestCaseStatus::Approved);

        $this->actingAs($this->tester)
            ->patchJson("/api/v1/test-cases/{$case->id}", [
                'title' => 'Tiêu đề mới từ ô nhập',
                'dsl' => $this->categoriesDsl('Tiêu đề cũ trong DSL'),
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Tiêu đề mới từ ô nhập')
            ->assertJsonPath('data.dsl.title', 'Tiêu đề mới từ ô nhập')
            // §4.2 unchanged: an edited APPROVED case goes back to VALID and must be re-approved.
            ->assertJsonPath('data.status', 'VALID');
    }

    public function test_api_patch_without_title_follows_the_dsl_title(): void
    {
        $case = $this->makeCase(TestCaseStatus::Valid);

        $this->actingAs($this->tester)
            ->patchJson("/api/v1/test-cases/{$case->id}", ['dsl' => $this->categoriesDsl('Chỉ sửa trong DSL')])
            ->assertOk()
            ->assertJsonPath('data.title', 'Chỉ sửa trong DSL')
            ->assertJsonPath('data.dsl.title', 'Chỉ sửa trong DSL');
    }

    public function test_invalid_case_edit_keeps_title_and_dsl_title_together_and_stays_invalid(): void
    {
        $case = $this->makeCase(TestCaseStatus::Invalid);
        $dsl = $this->categoriesDsl('Bản nháp vẫn lỗi');
        $dsl['steps'][0]['request']['path'] = '/qa6-nope';

        $this->actingAs($this->tester)
            ->patchJson("/api/v1/test-cases/{$case->id}", ['title' => 'Original', 'dsl' => $dsl])
            ->assertOk()
            ->assertJsonPath('data.status', 'INVALID')
            ->assertJsonPath('data.title', 'Bản nháp vẫn lỗi')
            ->assertJsonPath('data.dsl.title', 'Bản nháp vẫn lỗi');
    }

    public function test_rejected_edit_leaves_title_untouched(): void
    {
        $case = $this->makeCase(TestCaseStatus::Valid);
        $dsl = $this->categoriesDsl('Không được lưu');
        $dsl['steps'][0]['request']['path'] = '/qa6-nope';

        $this->actingAs($this->tester)
            ->patchJson("/api/v1/test-cases/{$case->id}", ['title' => 'Cũng không được lưu', 'dsl' => $dsl])
            ->assertStatus(422);

        $case->refresh();
        $this->assertSame('Original', $case->title);
        $this->assertSame(TestCaseStatus::Valid, $case->status);
    }

    public function test_m10_explains_that_title_and_dsl_title_are_synced(): void
    {
        $case = $this->makeCase(TestCaseStatus::Valid);

        $html = (string) $this->actingAs($this->tester)->get("/test-cases/{$case->id}")->assertOk()->getContent();

        $this->assertStringContainsString('aria-describedby="tc-title-hint"', $html);
        $this->assertStringContainsString('Tiêu đề luôn trùng với trường "title" trong DSL', $html);
    }

    public function test_title_shorter_than_the_dsl_schema_minimum_is_rejected_on_the_title_field(): void
    {
        // A changed title is written into dsl.title (minLength 3 in the DSL schema): the error names
        // the title field instead of surfacing as a DSL error the user never typed.
        $case = $this->makeCase(TestCaseStatus::Valid);

        $this->actingAs($this->tester)
            ->patchJson("/api/v1/test-cases/{$case->id}", ['title' => ' ab ', 'dsl' => $this->categoriesDsl('Tạo danh mục')])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.details.title.0', 'Tiêu đề test case phải dài ít nhất 3 ký tự.');

        $case->refresh();
        $this->assertSame('Original', $case->title);
        $this->assertSame(TestCaseStatus::Valid, $case->status);
    }

    public function test_m10_keeps_the_title_input_in_sync_after_an_invalid_save_without_reload(): void
    {
        $case = $this->makeCase(TestCaseStatus::Invalid);

        // An INVALID save stays on the page (no reload), so M10 mirrors the saved title into the title
        // input, the DSL editor and the header before the next save.
        $html = (string) $this->actingAs($this->tester)->get("/test-cases/{$case->id}")->assertOk()->getContent();
        $this->assertMatchesRegularExpression("/if \\(data\\.status === 'INVALID'\\) \\{.*?this\\.syncSavedTitle\\(form, dsl, data\\.title\\);.*?this\\.show\\('warning'/s", $html);
        $this->assertStringContainsString("form.elements['title'].value = title;", $html);
        $this->assertStringContainsString("form.elements['dsl'].value = JSON.stringify(dsl, null, 4);", $html);

        // Server side of the same sequence: the first save (title input unchanged) takes dsl.title;
        // the second save posts the mirrored input and keeps the edited title.
        $broken = $this->categoriesDsl('Tiêu đề sửa trong DSL');
        $broken['steps'][0]['request']['path'] = '/qa6-nope';
        $first = $this->actingAs($this->tester)
            ->patchJson("/api/v1/test-cases/{$case->id}", ['title' => 'Original', 'dsl' => $broken])
            ->assertOk()
            ->assertJsonPath('data.status', 'INVALID')
            ->json('data.title');
        $this->assertSame('Tiêu đề sửa trong DSL', $first);

        $this->actingAs($this->tester)
            ->patchJson("/api/v1/test-cases/{$case->id}", ['title' => $first, 'dsl' => $this->categoriesDsl('Tiêu đề sửa trong DSL')])
            ->assertOk()
            ->assertJsonPath('data.status', 'VALID')
            ->assertJsonPath('data.title', 'Tiêu đề sửa trong DSL')
            ->assertJsonPath('data.dsl.title', 'Tiêu đề sửa trong DSL');
    }

    // r7-2 ------------------------------------------------------------------------------------

    public function test_m06_upload_area_and_history_follow_the_shell_accent(): void
    {
        $admin = User::factory()->admin()->create();
        $uri = "/projects/{$this->project->id}/specs";

        foreach (['spec-dropzone-icon', 'spec-dropzone-link', 'spec-version-dot'] as $testId) {
            $testerTag = $this->tagWithTestId($this->html($uri, $this->tester), $testId);
            $this->assertStringContainsString('teal', $testerTag, $testId);
            $this->assertStringNotContainsString('indigo', $testerTag, $testId);
            $this->assertStringContainsString('indigo', $this->tagWithTestId($this->html($uri, $admin), $testId), "{$testId} (admin)");
        }

        $testerMain = $this->main($this->html($uri, $this->tester));
        $this->assertStringNotContainsString('indigo', $testerMain);
        $this->assertStringContainsString("dragOver ? 'border-teal-500 bg-teal-500", $testerMain);
    }

    public function test_m07_endpoint_view_follows_the_shell_accent(): void
    {
        $admin = User::factory()->admin()->create();
        $uri = "/projects/{$this->project->id}/specs/{$this->spec->id}";

        foreach (['spec-endpoint-count', 'spec-openapi-version', 'endpoint-operation-id'] as $testId) {
            $testerTag = $this->tagWithTestId($this->html($uri, $this->tester), $testId);
            $this->assertStringContainsString('teal', $testerTag, $testId);
            $this->assertStringNotContainsString('indigo', $testerTag, $testId);
            $this->assertStringContainsString('indigo', $this->tagWithTestId($this->html($uri, $admin), $testId), "{$testId} (admin)");
        }

        $this->assertStringNotContainsString('indigo', $this->main($this->html($uri, $this->tester)));
    }

    public function test_m13_and_m15_export_buttons_hover_in_the_shell_accent(): void
    {
        $admin = User::factory()->admin()->create();
        $run = TestRun::factory()->create([
            'test_suite_id' => $this->suite->id,
            'triggered_by' => $this->tester->id,
            'status' => TestRunStatus::Completed,
            'totals' => ['total' => 0, 'pass' => 0, 'fail' => 0, 'error' => 0, 'skipped' => 0],
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        $experiment = Experiment::factory()->create(['status' => ExperimentStatus::Completed]);

        $checks = [
            ["/runs/{$run->id}/report", '/<a id="btn-export-json"[^>]*>/s'],
            ["/runs/{$run->id}/report", '/<a id="btn-export-csv"[^>]*>/s'],
            ["/experiments/{$experiment->id}", '/<a [^>]*data-testid="experiment-export-json"[^>]*>/s'],
            ["/experiments/{$experiment->id}", '/<a [^>]*data-testid="experiment-export-csv"[^>]*>/s'],
        ];
        foreach ($checks as [$uri, $pattern]) {
            $testerTag = $this->firstMatch($pattern, $this->html($uri, $this->tester));
            $this->assertStringContainsString('hover:border-teal-400', $testerTag, $uri);
            $this->assertStringNotContainsString('indigo', $testerTag, $uri);
            $this->assertStringContainsString('hover:border-indigo-400', $this->firstMatch($pattern, $this->html($uri, $admin)), "{$uri} (admin)");
        }
    }

    public function test_text_selection_follows_the_shell_accent(): void
    {
        $admin = User::factory()->admin()->create();
        $uri = "/projects/{$this->project->id}/specs";

        $this->assertMatchesRegularExpression('/<body class="[^"]*selection:bg-teal-600[^"]*" data-layout="app"/', $this->html($uri, $this->tester));
        $this->assertMatchesRegularExpression('/<body class="[^"]*selection:bg-indigo-500[^"]*" data-layout="app"/', $this->html($uri, $admin));
    }

    // helpers ---------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeCase(TestCaseStatus $status, array $attributes = []): TestCaseModel
    {
        return TestCaseModel::factory()->create(array_merge([
            'test_suite_id' => $this->suite->id,
            'endpoint_id' => $this->endpoint->id,
            'title' => 'Original',
            'status' => $status,
            'source' => TestCaseSource::Rule,
            'technique' => TestTechnique::HappyPath,
        ], $attributes));
    }

    /**
     * A RULE-shaped case: the step keeps the generator's English name "Execute POST /categories".
     *
     * @return array<string, mixed>
     */
    private function categoriesDsl(string $title): array
    {
        return [
            'dsl_version' => '1.0',
            'title' => $title,
            'endpoint' => ['method' => 'POST', 'path' => '/categories'],
            'technique' => 'HAPPY_PATH',
            'source' => 'MANUAL',
            'steps' => [
                [
                    'name' => 'Execute POST /categories',
                    'request' => ['method' => 'POST', 'path' => '/categories'],
                    'assertions' => [['type' => 'status', 'equals' => 201]],
                ],
            ],
        ];
    }

    private function html(string $uri, User $user): string
    {
        return (string) $this->actingAs($user)->get($uri)->assertOk()->getContent();
    }

    /** Content of the <main> element, without the shell (the logo gradient stays indigo by design). */
    private function main(string $html): string
    {
        if (preg_match('/<main id="main".*?<\/main>/s', $html, $match) !== 1) {
            $this->fail('<main> not found');
        }

        return $match[0];
    }

    private function firstMatch(string $pattern, string $html): string
    {
        if (preg_match($pattern, $html, $match) !== 1) {
            $this->fail("{$pattern} not found");
        }

        return $match[0];
    }

    /** Opening tag of the element carrying data-testid="$testId". */
    private function tagWithTestId(string $html, string $testId): string
    {
        if (preg_match('/<[a-z0-9]+\b[^>]*data-testid="'.preg_quote($testId, '/').'"[^>]*>/s', $html, $match) !== 1) {
            $this->fail("{$testId} not found");
        }

        return $match[0];
    }
}
