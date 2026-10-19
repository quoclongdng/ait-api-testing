<?php

namespace Tests\Feature;

use App\Actions\Generation\ProcessGeneratedTestCasesAction;
use App\Actions\Generation\SummarizeGenerationJobAction;
use App\Contracts\AiGeneratorClient;
use App\Data\Ai\AiGeneratedTestCase;
use App\Data\Ai\AiGenerateRequest;
use App\Data\Ai\AiGenerateResponse;
use App\Data\AiServiceStatus;
use App\Enums\ApiSpecStatus;
use App\Enums\ErrorCode;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\HttpMethod;
use App\Enums\LlmPurpose;
use App\Enums\TestCaseSource;
use App\Enums\TestCaseStatus;
use App\Enums\TestTechnique;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Models\GenerationJob;
use App\Models\LlmCallLog;
use App\Models\Project;
use App\Models\TestCase as TestCaseModel;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\Support\GenerationJobError;
use App\Services\TestEngine\TestFingerprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * S14-06 regressions from the live QA of the production site: test case edit round trip, spec
 * checksum of pasted text, duplicate-only generation jobs, provider error text, M11 form,
 * no-op/terminal feedback and request messages.
 */
class QaGenerationSpecsS1406Test extends TestCase
{
    use RefreshDatabase;

    private User $tester;

    private Project $project;

    private ApiSpec $spec;

    private TestSuite $suite;

    private Endpoint $orders;

    private Endpoint $items;

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
        $this->orders = Endpoint::factory()->create([
            'api_spec_id' => $this->spec->id,
            'method' => HttpMethod::Get,
            'path' => '/orders',
            'responses' => ['200' => ['description' => 'OK']],
        ]);
        $this->items = Endpoint::factory()->create([
            'api_spec_id' => $this->spec->id,
            'method' => HttpMethod::Get,
            'path' => '/items',
            'responses' => ['200' => ['description' => 'OK']],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $extraAssertions
     * @return array<string, mixed>
     */
    private function dsl(string $path, string $title = 'Case', array $extraAssertions = []): array
    {
        return [
            'dsl_version' => '1.0',
            'title' => $title,
            'endpoint' => ['method' => 'GET', 'path' => $path],
            'technique' => 'HAPPY_PATH',
            'source' => 'LLM',
            'steps' => [[
                'name' => 'Call',
                'request' => ['method' => 'GET', 'path' => $path],
                'assertions' => [['type' => 'status', 'equals' => 200], ...$extraAssertions],
            ]],
        ];
    }

    /**
     * @param  list<int>  $endpointIds
     */
    private function runningJob(array $endpointIds): GenerationJob
    {
        return GenerationJob::factory()->create([
            'project_id' => $this->project->id,
            'api_spec_id' => $this->spec->id,
            'test_suite_id' => $this->suite->id,
            'strategy' => GenerationStrategy::Llm,
            'status' => GenerationJobStatus::Running,
            'params' => ['endpoint_ids' => $endpointIds],
            'created_by' => $this->tester->id,
        ]);
    }

    // -1 ---------------------------------------------------------------------------------------

    public function test_editing_a_case_with_inline_schema_keeps_the_dsl_and_fingerprint_and_round_trips(): void
    {
        $case = TestCaseModel::factory()->create([
            'test_suite_id' => $this->suite->id,
            'endpoint_id' => $this->orders->id,
            'status' => TestCaseStatus::Valid,
            'source' => TestCaseSource::Rule,
            'technique' => TestTechnique::HappyPath,
        ]);
        $dsl = $this->dsl('/orders', 'Orders schema', [[
            'type' => 'json_schema',
            'schema' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer'], 'meta' => ['type' => 'object', 'properties' => new \stdClass]],
            ],
        ]]);

        $first = $this->actingAs($this->tester)->patchJson("/api/v1/test-cases/{$case->id}", ['dsl' => $dsl])->assertOk();
        $fingerprint = $first->json('data.fingerprint');
        $this->assertSame((new TestFingerprintService)->calculate(json_decode((string) json_encode($dsl), false)), $fingerprint);

        // The stored schema is exactly the one sent: no meta-schema defaults (readOnly, properties: []...).
        $schema = $first->json('data.dsl.steps.0.assertions.1.schema');
        $this->assertSame(['type', 'properties'], array_keys($schema));
        $this->assertSame(['type' => 'integer'], $schema['properties']['id']);

        // A description-only edit keeps the fingerprint (§10: description is not part of it).
        $dsl['description'] = 'Only the description changes.';
        $second = $this->actingAs($this->tester)->patchJson("/api/v1/test-cases/{$case->id}", ['dsl' => $dsl])->assertOk();
        $this->assertSame($fingerprint, $second->json('data.fingerprint'));

        // GET then PATCH the returned DSL again: still valid (an empty object stays an object).
        $raw = $this->actingAs($this->tester)->getJson("/api/v1/test-cases/{$case->id}")->assertOk()->getContent();
        $this->assertStringContainsString('"properties":{}', (string) $raw);
        $returned = json_decode((string) $raw, false)->data->dsl;
        $this->actingAs($this->tester)
            ->call('PATCH', "/api/v1/test-cases/{$case->id}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], (string) json_encode(['dsl' => $returned]))
            ->assertOk()
            ->assertJsonPath('data.fingerprint', $fingerprint)
            ->assertJsonPath('data.status', 'VALID');
    }

    // -2 ---------------------------------------------------------------------------------------

    public function test_pasted_spec_with_the_same_content_as_an_uploaded_file_is_a_duplicate(): void
    {
        $content = "openapi: 3.0.3\ninfo:\n  title: Dup\n  version: '1'\npaths:\n  /ping:\n    get:\n      responses:\n        '200':\n          description: ok\n";

        $this->actingAs($this->tester)->post("/api/v1/projects/{$this->project->id}/specs", [
            'version_label' => 'file',
            'file' => UploadedFile::fake()->createWithContent('dup.yaml', $content),
        ])->assertCreated();

        $this->actingAs($this->tester)->postJson("/api/v1/projects/{$this->project->id}/specs", [
            'version_label' => 'api text',
            'format' => 'yaml',
            'raw_content' => $content,
        ])->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');

        $this->actingAs($this->tester)->post("/projects/{$this->project->id}/specs", [
            'version_label' => 'web text',
            'input_method' => 'text',
            'spec_text' => $content,
        ])->assertSessionHas('error');

        $this->assertSame(1, ApiSpec::query()->where('project_id', $this->project->id)->where('version_label', '!=', $this->spec->version_label)->count());
    }

    public function test_pasted_spec_is_stored_byte_exact(): void
    {
        $content = "openapi: 3.0.3\ninfo:\n  title: Exact\n  version: '1'\npaths:\n  /ping:\n    get:\n      responses:\n        '200':\n          description: ok\n\n";

        $id = (int) $this->actingAs($this->tester)->postJson("/api/v1/projects/{$this->project->id}/specs", [
            'version_label' => 'exact',
            'format' => 'yaml',
            'raw_content' => $content,
        ])->assertCreated()->json('data.id');

        $spec = ApiSpec::query()->whereKey($id)->firstOrFail();
        $this->assertSame($content, $spec->raw_content);
        $this->assertSame(hash('sha256', $content), $spec->checksum);
    }

    // -3 ---------------------------------------------------------------------------------------

    public function test_job_whose_cases_are_all_duplicates_succeeds_without_dsl_invalid(): void
    {
        $first = $this->runningJob([$this->orders->id]);
        app(ProcessGeneratedTestCasesAction::class)->execute($first, [new AiGeneratedTestCase($this->dsl('/orders'), 'VALID', [], 0)]);
        $this->assertSame(GenerationJobStatus::Succeeded, $first->refresh()->status);

        $second = $this->runningJob([$this->orders->id]);
        app(ProcessGeneratedTestCasesAction::class)->execute($second, [new AiGeneratedTestCase($this->dsl('/orders', 'Other title'), 'VALID', [], 0)]);

        $second->refresh();
        $this->assertSame(GenerationJobStatus::Succeeded, $second->status);
        $this->assertNull($second->error);
        $this->assertSame(0, $second->stats['valid'] ?? null);
        $this->assertSame(1, $second->stats['duplicates'] ?? null);

        $summary = app(SummarizeGenerationJobAction::class)->execute($second);
        $this->assertNull($summary->errorMessage);
        $this->assertStringContainsString('mọi test case sinh ra đều trùng', $summary->progressLabel);
        $this->assertFalse($summary->endpoints[0]['has_error']);
    }

    public function test_an_endpoint_with_only_duplicates_does_not_make_the_job_partial(): void
    {
        $seed = $this->runningJob([$this->orders->id]);
        app(ProcessGeneratedTestCasesAction::class)->execute($seed, [new AiGeneratedTestCase($this->dsl('/orders'), 'VALID', [], 0)]);

        $job = $this->runningJob([$this->orders->id, $this->items->id]);
        app(ProcessGeneratedTestCasesAction::class)->execute($job, [
            new AiGeneratedTestCase($this->dsl('/orders'), 'VALID', [], 0),
            new AiGeneratedTestCase($this->dsl('/items'), 'VALID', [], 0),
        ]);

        $job->refresh();
        $this->assertSame(GenerationJobStatus::Succeeded, $job->status);
        $this->assertSame(1, $job->stats['valid'] ?? null);
        $this->assertSame(1, $job->stats['duplicates'] ?? null);
    }

    public function test_an_endpoint_without_any_case_still_makes_the_job_partial(): void
    {
        $job = $this->runningJob([$this->orders->id, $this->items->id]);
        app(ProcessGeneratedTestCasesAction::class)->execute($job, [new AiGeneratedTestCase($this->dsl('/orders'), 'VALID', [], 0)]);

        $this->assertSame(GenerationJobStatus::Partial, $job->refresh()->status);
    }

    public function test_job_with_invalid_and_duplicate_cases_fails_with_both_counts(): void
    {
        $seed = $this->runningJob([$this->orders->id]);
        app(ProcessGeneratedTestCasesAction::class)->execute($seed, [new AiGeneratedTestCase($this->dsl('/orders'), 'VALID', [], 0)]);

        $broken = $this->dsl('/orders', 'Broken');
        $broken['steps'][0]['assertions'] = [['type' => 'status', 'equals' => 404]];
        $job = $this->runningJob([$this->orders->id]);
        app(ProcessGeneratedTestCasesAction::class)->execute($job, [
            new AiGeneratedTestCase($this->dsl('/orders'), 'VALID', [], 0),
            new AiGeneratedTestCase($broken, 'VALID', [], 0),
        ]);

        $job->refresh();
        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertSame(ErrorCode::DslInvalid, GenerationJobError::code($job->error));
        $message = (string) app(SummarizeGenerationJobAction::class)->execute($job)->errorMessage;
        $this->assertStringContainsString('1 test case không qua được kiểm tra DSL', $message);
        $this->assertStringContainsString('1 test case trùng', $message);
    }

    public function test_job_with_only_invalid_cases_keeps_dsl_invalid(): void
    {
        $broken = $this->dsl('/orders');
        $broken['steps'][0]['assertions'] = [['type' => 'status', 'equals' => 404]];
        $job = $this->runningJob([$this->orders->id]);
        app(ProcessGeneratedTestCasesAction::class)->execute($job, [new AiGeneratedTestCase($broken, 'VALID', [], 0)]);

        $job->refresh();
        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertSame(ErrorCode::DslInvalid->value, $job->error);
        $this->assertTrue(app(SummarizeGenerationJobAction::class)->execute($job)->endpoints[0]['has_error']);
    }

    // -7(3) ------------------------------------------------------------------------------------

    public function test_ai_rejected_case_keeps_only_the_web_errors_when_the_web_finds_problems(): void
    {
        $dsl = $this->dsl('/orders');
        $dsl['steps'][0]['request']['query'] = ['invalid_param' => 1];
        $job = $this->runningJob([$this->orders->id]);
        app(ProcessGeneratedTestCasesAction::class)->execute($job, [
            new AiGeneratedTestCase($dsl, 'INVALID', ["Bước 1 ('Call'): Tham số truy vấn 'invalid_param' không tồn tại trong endpoint."], 2),
        ]);

        $errors = TestCaseModel::query()->where('generation_job_id', $job->id)->firstOrFail()->validation_errors;
        $this->assertIsArray($errors);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString("Tham số query 'invalid_param'", (string) array_values($errors)[0]);
    }

    public function test_ai_rejected_case_keeps_the_ai_errors_when_the_web_finds_none(): void
    {
        $job = $this->runningJob([$this->orders->id]);
        app(ProcessGeneratedTestCasesAction::class)->execute($job, [
            new AiGeneratedTestCase($this->dsl('/orders'), 'INVALID', ['Bản sửa phải giữ nguyên endpoint đích của test case ban đầu.'], 2),
        ]);

        $this->assertSame(
            ['Bản sửa phải giữ nguyên endpoint đích của test case ban đầu.'],
            TestCaseModel::query()->where('generation_job_id', $job->id)->firstOrFail()->validation_errors,
        );
    }

    // -6 ---------------------------------------------------------------------------------------

    public function test_provider_error_is_shown_once_and_without_url(): void
    {
        $raw = "Client error '404 Not Found' for url 'https://api.groq.com/openai/v1/chat/completions'\nFor more information check: https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/404";
        $job = $this->runningJob([$this->orders->id]);
        $job->update([
            'status' => GenerationJobStatus::Failed,
            'error' => GenerationJobError::compose(ErrorCode::LlmProviderError->value, 'Lỗi từ LLM provider: '.$raw),
        ]);
        LlmCallLog::factory()->create(['generation_job_id' => $job->id, 'purpose' => LlmPurpose::Generate, 'error' => $raw]);

        $message = (string) app(SummarizeGenerationJobAction::class)->execute($job->refresh())->errorMessage;

        $this->assertStringNotContainsString('https://', $message);
        $this->assertStringNotContainsString('Chi tiết từ LLM provider', $message);
        $this->assertSame(1, substr_count($message, '404 Not Found'));
    }

    public function test_m11_model_suggestion_is_the_model_reported_by_ai_service(): void
    {
        $this->app->instance(AiGeneratorClient::class, new class implements AiGeneratorClient
        {
            public function status(): AiServiceStatus
            {
                return new AiServiceStatus(reachable: true, provider: 'openai_compatible', model: 'qwen/qwen3.8-27b');
            }

            public function generate(AiGenerateRequest $request): AiGenerateResponse
            {
                throw new \LogicException('not called');
            }
        });

        $this->actingAs($this->tester)->get("/suites/{$this->suite->id}/generation-jobs/create")
            ->assertOk()
            ->assertSee('<option value="qwen/qwen3.8-27b"></option>', false)
            ->assertDontSee('llama3.1:8b')
            ->assertDontSee('qwen2.5-coder:14b')
            // -8: no technique selected falls back to the strategy defaults, and the form says so.
            ->assertSee('Không chọn kỹ thuật nào: hệ thống dùng bộ kỹ thuật mặc định của chiến lược')
            ->assertSee('RULE (HAPPY_PATH, BOUNDARY, EQUIVALENCE, MISSING_REQUIRED, TYPE_MISMATCH, INVALID_INPUT)', false);
    }

    // -10a -------------------------------------------------------------------------------------

    public function test_empty_spec_file_is_reported_as_empty(): void
    {
        $this->actingAs($this->tester)->post("/api/v1/projects/{$this->project->id}/specs", [
            'version_label' => 'empty',
            'file' => UploadedFile::fake()->createWithContent('empty.yaml', ''),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.details.file', ['Tệp đặc tả rỗng, vui lòng chọn tệp có nội dung.']);

        $this->actingAs($this->tester)->post("/projects/{$this->project->id}/specs", [
            'version_label' => 'empty',
            'input_method' => 'file',
            'spec_file' => UploadedFile::fake()->createWithContent('empty.yaml', ''),
        ])->assertSessionHasErrors(['spec_file' => 'Tệp đặc tả rỗng, vui lòng chọn tệp có nội dung.']);
    }

    // -11 --------------------------------------------------------------------------------------

    public function test_bulk_action_that_changes_nothing_is_not_a_success(): void
    {
        $approved = TestCaseModel::factory()->create([
            'test_suite_id' => $this->suite->id,
            'endpoint_id' => $this->orders->id,
            'status' => TestCaseStatus::Approved,
        ]);

        $this->actingAs($this->tester)
            ->post("/suites/{$this->suite->id}/bulk-action", ['action' => 'approve', 'test_case_ids' => [$approved->id]])
            ->assertRedirect("/suites/{$this->suite->id}")
            ->assertSessionMissing('success')
            ->assertSessionHas('error', 'Không có test case nào thay đổi: chỉ duyệt được test case ở trạng thái VALID.');
    }

    public function test_cancelling_a_finished_job_names_its_status(): void
    {
        $job = $this->runningJob([$this->orders->id]);
        $job->update(['status' => GenerationJobStatus::Succeeded]);

        $this->actingAs($this->tester)
            ->post("/generation-jobs/{$job->id}/cancel")
            ->assertSessionHas('error', 'Tiến trình đã kết thúc (SUCCEEDED), không thể hủy.');

        $job->update(['status' => GenerationJobStatus::Running]);
        $this->actingAs($this->tester)
            ->post("/generation-jobs/{$job->id}/cancel")
            ->assertSessionHas('error', 'Tiến trình đang chạy (RUNNING): chỉ có thể hủy khi còn ở hàng đợi (QUEUED).');
    }

    // -12 --------------------------------------------------------------------------------------

    public function test_generation_request_messages_match_the_strategy_and_come_once(): void
    {
        $response = $this->actingAs($this->tester)->postJson("/api/v1/suites/{$this->suite->id}/generation-jobs", [
            'strategy' => 'LLM',
            'params' => ['endpoint_ids' => [$this->orders->id], 'techniques' => ['FOO'], 'temperature' => 'abc'],
        ])->assertStatus(422);

        $technique = (string) $response->json('error.details')['params.techniques.0'][0];
        $this->assertStringStartsWith('Kỹ thuật kiểm thử không hợp lệ (chỉ nhận: HAPPY_PATH', $technique);
        $this->assertStringContainsString('SEQUENCE', $technique);
        $this->assertStringNotContainsString('chiến lược RULE', $technique);
        $this->assertSame(['Nhiệt độ LLM phải là số.'], $response->json('error.details')['params.temperature']);

        $rule = $this->actingAs($this->tester)->postJson("/api/v1/suites/{$this->suite->id}/generation-jobs", [
            'strategy' => 'RULE',
            'params' => ['endpoint_ids' => [$this->orders->id], 'techniques' => ['AUTH']],
        ])->assertStatus(422);
        $this->assertStringContainsString('chiến lược RULE', (string) $rule->json('error.details')['params.techniques.0'][0]);
    }
}
