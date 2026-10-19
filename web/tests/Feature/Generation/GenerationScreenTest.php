<?php

namespace Tests\Feature\Generation;

use App\Contracts\AiGeneratorClient;
use App\Enums\ApiSpecStatus;
use App\Enums\ErrorCode;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\HttpMethod;
use App\Enums\LlmPurpose;
use App\Enums\TestCaseSource;
use App\Enums\TestCaseStatus;
use App\Enums\TestTechnique;
use App\Jobs\GenerateTestsJob;
use App\Jobs\Generation\GenerateRuleTestCases;
use App\Models\ApiSpec;
use App\Models\BusinessRule;
use App\Models\Endpoint;
use App\Models\GenerationJob;
use App\Models\LlmCallLog;
use App\Models\Project;
use App\Models\TestCase as TestCaseModel;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\Ai\FakeAiGeneratorClient;
use App\Services\Support\GenerationJobError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * S6-04: screen M11 with RULE / LLM / HYBRID, LLM parameters and job statistics.
 */
class GenerationScreenTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiGeneratorClient $ai;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ai = new FakeAiGeneratorClient;
        $this->app->instance(AiGeneratorClient::class, $this->ai);
    }

    public function test_create_screen_offers_all_strategies_llm_parameters_and_business_rules(): void
    {
        [$tester, $suite, $get] = $this->context();
        BusinessRule::factory()->create([
            'project_id' => $suite->project_id,
            'endpoint_id' => null,
            'code' => 'BR-STOCK-01',
            'description' => 'Số lượng không vượt quá tồn kho.',
        ]);
        $admin = User::factory()->admin()->create();

        foreach ([$tester, $admin] as $user) {
            $this->actingAs($user)
                ->get("/suites/{$suite->id}/generation-jobs/create")
                ->assertOk()
                ->assertSee('Cấu hình sinh test')
                ->assertSee('RULE-BASED')
                ->assertSee('LLM-BASED')
                ->assertSee('HYBRID (LAI)')
                ->assertSee('THAM SỐ LLM')
                ->assertSee('Số lần tự sửa tối đa')
                ->assertSee('RÀNG BUỘC NGHIỆP VỤ GỬI KÈM')
                ->assertSee('BR-STOCK-01')
                ->assertSee('Số lượng không vượt quá tồn kho.')
                ->assertSee('name="max_repair_attempts"', false)
                ->assertSee($get->path)
                ->assertSee('apiUrl', false)
                ->assertSee('suites\\\\\\/'.$suite->id.'\\\\\\/generation-jobs', false)
                ->assertDontSee('Khóa (Sprint 5)')
                ->assertDontSee('gpt-4o');
        }
    }

    public function test_guest_is_redirected_and_other_tester_is_forbidden(): void
    {
        [, $suite] = $this->context();
        $job = GenerationJob::factory()->create([
            'project_id' => $suite->project_id,
            'api_spec_id' => $suite->api_spec_id,
            'test_suite_id' => $suite->id,
            'strategy' => GenerationStrategy::Llm,
        ]);
        $other = User::factory()->tester()->create();

        $this->get("/suites/{$suite->id}/generation-jobs/create")->assertRedirect('/login');
        $this->get("/generation-jobs/{$job->id}")->assertRedirect('/login');
        $this->getJson("/api/v1/generation-jobs/{$job->id}")->assertUnauthorized();

        $this->actingAs($other)->get("/suites/{$suite->id}/generation-jobs/create")->assertForbidden();
        $this->actingAs($other)->get("/generation-jobs/{$job->id}")->assertForbidden();
        $this->actingAs($other)->getJson("/api/v1/generation-jobs/{$job->id}")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
        $this->actingAs($other)->postJson("/api/v1/suites/{$suite->id}/generation-jobs", [
            'strategy' => 'LLM',
            'params' => ['endpoint_ids' => [$suite->apiSpec->endpoints()->firstOrFail()->id]],
        ])->assertForbidden();
        $this->assertSame(1, GenerationJob::query()->count());
    }

    public function test_llm_job_stores_parameters_and_forwards_them_to_ai_service(): void
    {
        [$tester, $suite, $get, $post] = $this->context();
        BusinessRule::factory()->create(['project_id' => $suite->project_id, 'endpoint_id' => null, 'code' => 'BR-GLOBAL']);
        BusinessRule::factory()->create(['project_id' => $suite->project_id, 'endpoint_id' => $post->id, 'code' => 'BR-POST']);

        $response = $this->actingAs($tester)->post("/suites/{$suite->id}/generation-jobs", [
            'strategy' => 'LLM',
            'endpoint_ids' => [$get->id],
            'techniques' => ['HAPPY_PATH', 'AUTH'],
            'max_cases_per_endpoint' => 4,
            'model' => 'qwen2.5-coder:14b',
            'temperature' => 0.7,
            'seed' => 1234,
            'max_repair_attempts' => 3,
        ]);

        $job = GenerationJob::query()->latest('id')->firstOrFail();
        $response->assertRedirect("/generation-jobs/{$job->id}");

        $this->assertSame(GenerationStrategy::Llm, $job->strategy);
        $params = $job->params;
        ksort($params);
        $this->assertSame([
            'endpoint_ids' => [$get->id],
            'max_cases_per_endpoint' => 4,
            'max_repair_attempts' => 3,
            'model' => 'qwen2.5-coder:14b',
            'seed' => 1234,
            'techniques' => ['HAPPY_PATH', 'AUTH'],
            'temperature' => 0.7,
        ], $params);

        $request = $this->ai->lastRequest();
        $this->assertNotNull($request);
        $payload = $request->toArray();
        $this->assertSame('LLM', $payload['strategy']);
        $this->assertSame([
            'max_cases_per_endpoint' => 4,
            'techniques' => ['HAPPY_PATH', 'AUTH'],
            'model' => 'qwen2.5-coder:14b',
            'temperature' => 0.7,
            'seed' => 1234,
            'max_repair_attempts' => 3,
        ], $payload['options']);
        $this->assertSame([$get->id], array_column($payload['endpoints'], 'id'));
        // Project-wide rule only: the endpoint-bound rule targets an endpoint that was not selected.
        $this->assertSame(['BR-GLOBAL'], array_column($payload['business_rules'], 'code'));
        $this->assertSame([], $payload['seed_cases']);

        // Sync queue + FakeAiGeneratorClient: the job ran end to end.
        $job->refresh();
        $this->assertSame(GenerationJobStatus::Succeeded, $job->status);
        $this->assertSame(1, $job->llmCallLogs()->count());
        $this->assertSame(TestCaseSource::Llm, $job->testCases()->firstOrFail()->source);

        $this->actingAs($tester)
            ->get("/generation-jobs/{$job->id}")
            ->assertOk()
            ->assertSee('Tiến độ sinh test #'.$job->id)
            ->assertSee('SUCCEEDED')
            ->assertSee('SỬ DỤNG LLM (TOKEN &amp; ĐỘ TRỄ)', false)
            ->assertSee('KẾT QUẢ THEO ENDPOINT')
            ->assertSee('qwen2.5-coder:14b')
            ->assertSee('1234')
            ->assertSee('id="llm-total-tokens" x-text="fmt(summary.llm.total_tokens)">180<', false)
            ->assertSee('generation-jobs\\\\\\/'.$job->id, false);
    }

    public function test_rule_job_ignores_llm_parameters(): void
    {
        [$tester, $suite, $get] = $this->context();

        $this->actingAs($tester)->post("/suites/{$suite->id}/generation-jobs", [
            'strategy' => 'RULE',
            'endpoint_ids' => [$get->id],
            'techniques' => ['HAPPY_PATH'],
            'model' => 'should-be-ignored',
            'temperature' => 1.5,
            'seed' => 7,
            'max_repair_attempts' => 5,
        ])->assertSessionHas('success');

        $job = GenerationJob::query()->latest('id')->firstOrFail();
        $this->assertSame(GenerationStrategy::Rule, $job->strategy);
        $this->assertEqualsCanonicalizing(['endpoint_ids', 'max_cases_per_endpoint', 'techniques'], array_keys($job->params));
        $this->assertNull($this->ai->lastRequest());
        $this->assertSame(0, LlmCallLog::query()->count());

        $this->actingAs($tester)
            ->get("/generation-jobs/{$job->id}")
            ->assertOk()
            ->assertSee('Chiến lược RULE không gọi LLM.')
            ->assertDontSee('should-be-ignored');
    }

    public function test_rule_rejects_llm_only_techniques_but_llm_accepts_them(): void
    {
        [$tester, $suite] = $this->context();

        $this->actingAs($tester)
            ->post("/suites/{$suite->id}/generation-jobs", ['strategy' => 'RULE', 'techniques' => ['BUSINESS_RULE']])
            ->assertSessionHasErrors(['techniques.0']);

        $this->actingAs($tester)
            ->post("/suites/{$suite->id}/generation-jobs", ['strategy' => 'LLM', 'temperature' => 3])
            ->assertSessionHasErrors(['temperature']);

        $this->actingAs($tester)
            ->post("/suites/{$suite->id}/generation-jobs", ['strategy' => 'LLM', 'max_repair_attempts' => 11])
            ->assertSessionHasErrors(['max_repair_attempts']);

        $this->assertSame(0, GenerationJob::query()->count());

        $this->actingAs($tester)
            ->post("/suites/{$suite->id}/generation-jobs", ['strategy' => 'LLM', 'techniques' => ['BUSINESS_RULE', 'SEQUENCE']])
            ->assertSessionHasNoErrors();

        $job = GenerationJob::query()->firstOrFail();
        $this->assertSame(['BUSINESS_RULE', 'SEQUENCE'], $job->params['techniques']);
        // Empty endpoint selection = every endpoint of the spec.
        $this->assertCount(2, $job->params['endpoint_ids']);
    }

    public function test_api_creates_llm_job_queued_with_contract_params(): void
    {
        Queue::fake();
        [$tester, $suite, $get] = $this->context();

        $response = $this->actingAs($tester)->postJson("/api/v1/suites/{$suite->id}/generation-jobs", [
            'strategy' => 'LLM',
            'params' => [
                'endpoint_ids' => [$get->id],
                'model' => null,
                'temperature' => 0,
                'seed' => null,
            ],
        ]);

        $response->assertAccepted()
            ->assertJsonPath('data.strategy', 'LLM')
            ->assertJsonPath('data.status', 'QUEUED')
            ->assertJsonPath('data.params.endpoint_ids', [$get->id])
            ->assertJsonPath('data.params.techniques', ['HAPPY_PATH', 'BOUNDARY', 'INVALID_INPUT'])
            ->assertJsonPath('data.params.max_repair_attempts', 2)
            ->assertJsonPath('data.params.model', null)
            ->assertJsonMissingPath('data.params.strategy')
            ->assertJsonMissingPath('data.summary');

        Queue::assertPushed(GenerateTestsJob::class, fn (GenerateTestsJob $j): bool => $j->generationJobId === $response->json('data.id'));
        Queue::assertNotPushed(GenerateRuleTestCases::class);
    }

    public function test_api_rejects_invalid_llm_parameters_with_envelope(): void
    {
        [$tester, $suite, $get] = $this->context();
        $foreign = Endpoint::factory()->create();

        $this->actingAs($tester)->postJson("/api/v1/suites/{$suite->id}/generation-jobs", [
            'strategy' => 'HYBRID',
            'params' => ['endpoint_ids' => [$get->id], 'temperature' => 5, 'unknown' => true],
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->actingAs($tester)->postJson("/api/v1/suites/{$suite->id}/generation-jobs", [
            'strategy' => 'LLM',
            'params' => ['endpoint_ids' => [$foreign->id]],
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertSame(0, GenerationJob::query()->count());
    }

    public function test_api_validation_details_are_in_vietnamese(): void
    {
        [$tester, $suite, $get] = $this->context();

        $response = $this->actingAs($tester)->postJson("/api/v1/suites/{$suite->id}/generation-jobs", [
            'strategy' => 'LLM',
            'params' => [
                'endpoint_ids' => [$get->id],
                'techniques' => ['NOT_A_TECHNIQUE'],
                'max_cases_per_endpoint' => 0,
                'temperature' => 3,
                'seed' => -1,
                'max_repair_attempts' => 11,
                'model' => str_repeat('m', 101),
            ],
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $details = $response->json('error.details');
        $this->assertSame(['Nhiệt độ LLM phải nằm trong khoảng 0 đến 2.'], $details['params.temperature']);
        $this->assertSame(['Số case tối đa mỗi endpoint phải từ 1 đến 100.'], $details['params.max_cases_per_endpoint']);
        $this->assertSame(['Số lần tự sửa tối đa phải từ 0 đến 10.'], $details['params.max_repair_attempts']);
        $this->assertSame(['Seed phải là số nguyên không âm.'], $details['params.seed']);
        $this->assertSame(['Tên mô hình LLM không được dài quá 100 ký tự.'], $details['params.model']);
        // The message matches the strategy (S14-06): no RULE wording for an LLM/HYBRID request.
        $this->assertStringStartsWith('Kỹ thuật kiểm thử không hợp lệ', $details['params.techniques.0'][0]);
        $this->assertStringNotContainsString('chiến lược RULE', $details['params.techniques.0'][0]);

        $missing = $this->actingAs($tester)->postJson("/api/v1/suites/{$suite->id}/generation-jobs", [
            'params' => ['endpoint_ids' => []],
        ])->assertUnprocessable();
        $this->assertSame(['Vui lòng chọn chiến lược sinh test.'], $missing->json('error.details.strategy'));
        $this->assertSame(['Vui lòng chọn ít nhất một endpoint.'], $missing->json('error.details')['params.endpoint_ids']);

        $flat = implode(' ', array_merge(...array_values($details)));
        $this->assertStringNotContainsString('The ', $flat);
        $this->assertStringNotContainsString(' field ', $flat);
    }

    public function test_api_applies_defaults_when_optional_numeric_params_are_omitted(): void
    {
        Queue::fake();
        [$tester, $suite, $get] = $this->context();

        // M11 omits cleared numeric fields instead of sending Number('') === 0.
        $this->actingAs($tester)->postJson("/api/v1/suites/{$suite->id}/generation-jobs", [
            'strategy' => 'LLM',
            'params' => ['endpoint_ids' => [$get->id], 'model' => null, 'seed' => null],
        ])->assertAccepted()
            ->assertJsonPath('data.params.temperature', 0.2)
            ->assertJsonPath('data.params.max_repair_attempts', 2)
            ->assertJsonPath('data.params.max_cases_per_endpoint', 10);
    }

    public function test_create_screen_omits_blank_numbers_and_localizes_validation_message(): void
    {
        [$tester, $suite] = $this->context();

        $this->actingAs($tester)->get("/suites/{$suite->id}/generation-jobs/create")
            ->assertOk()
            ->assertSee('Dữ liệu cấu hình không hợp lệ.')
            ->assertSee('if (!isBlank(this.temperature)) params.temperature', false)
            ->assertSee('if (!isBlank(this.maxRepairAttempts)) params.max_repair_attempts', false)
            ->assertSee('if (!isBlank(this.maxCasesPerEndpoint)) params.max_cases_per_endpoint', false)
            ->assertDontSee('max_cases_per_endpoint: Number(this.maxCasesPerEndpoint)', false);
    }

    public function test_hybrid_job_seeds_ai_service_with_rule_cases_generated_on_the_fly(): void
    {
        [$tester, $suite, $get, $post] = $this->context();

        $this->actingAs($tester)->postJson("/api/v1/suites/{$suite->id}/generation-jobs", [
            'strategy' => 'HYBRID',
            'params' => ['endpoint_ids' => [$get->id, $post->id], 'seed' => 42],
        ])->assertAccepted();

        $request = $this->ai->lastRequest();
        $this->assertNotNull($request);
        $this->assertSame(GenerationStrategy::Hybrid, $request->strategy);
        $this->assertNotEmpty($request->seedCases);
        foreach ($request->seedCases as $seed) {
            $this->assertSame('RULE', $seed['source']);
        }
        $this->assertSame(['BUSINESS_RULE', 'SEQUENCE', 'AUTH'], $request->options['techniques']);

        // Seeds are not persisted: only the cases returned by ai-service are stored as HYBRID.
        $job = GenerationJob::query()->latest('id')->firstOrFail();
        $this->assertSame(0, TestCaseModel::query()->where('source', TestCaseSource::Rule)->count());
        $this->assertSame(TestCaseSource::Hybrid, $job->testCases()->firstOrFail()->source);
        // The fake only answers for the first endpoint, so POST /api/items has no VALID case (§4.1 PARTIAL).
        $this->assertSame(GenerationJobStatus::Partial, $job->refresh()->status);
    }

    public function test_hybrid_job_prefers_existing_valid_rule_cases_of_the_suite(): void
    {
        [$tester, $suite, $get] = $this->context();

        // Generate RULE cases first, then HYBRID re-uses them as seed.
        $this->actingAs($tester)->post("/suites/{$suite->id}/generation-jobs", [
            'strategy' => 'RULE',
            'endpoint_ids' => [$get->id],
            'techniques' => ['HAPPY_PATH'],
        ]);
        $ruleDsl = TestCaseModel::query()
            ->where('source', TestCaseSource::Rule)
            ->where('status', TestCaseStatus::Valid)
            ->firstOrFail()
            ->dsl;

        $this->actingAs($tester)->post("/suites/{$suite->id}/generation-jobs", [
            'strategy' => 'HYBRID',
            'endpoint_ids' => [$get->id],
        ]);

        $request = $this->ai->lastRequest();
        $this->assertNotNull($request);
        $this->assertSame($ruleDsl['title'], $request->seedCases[0]['title']);
        $this->assertSame($ruleDsl['steps'][0]['request']['path'], $request->seedCases[0]['steps'][0]['request']['path']);
    }

    /**
     * Regression (S10-01): a suite with RULE cases for only some selected endpoints used to send
     * no seed at all for the others, which were then generated as plain LLM.
     */
    public function test_hybrid_job_seeds_every_selected_endpoint_when_the_suite_covers_only_some(): void
    {
        [$tester, $suite, $get, $post] = $this->context();

        $this->actingAs($tester)->post("/suites/{$suite->id}/generation-jobs", [
            'strategy' => 'RULE',
            'endpoint_ids' => [$get->id],
            'techniques' => ['HAPPY_PATH'],
        ]);
        $this->assertSame(0, TestCaseModel::query()->where('endpoint_id', $post->id)->count());

        $this->actingAs($tester)->post("/suites/{$suite->id}/generation-jobs", [
            'strategy' => 'HYBRID',
            'endpoint_ids' => [$get->id, $post->id],
        ]);

        $request = $this->ai->lastRequest();
        $this->assertNotNull($request);
        $seededEndpoints = array_unique(array_map(
            fn (array $seed): string => $seed['endpoint']['method'].' '.$seed['endpoint']['path'],
            $request->seedCases,
        ));
        sort($seededEndpoints);
        $this->assertSame(['GET /api/items', 'POST /api/items'], $seededEndpoints);
    }

    /**
     * Regression (S10-01): internal params keys (seed_cases, options, experiment_id) broke the
     * GenerationParams contract (additionalProperties: false) of GET /generation-jobs/{id}.
     */
    public function test_api_show_returns_only_contract_params(): void
    {
        [$tester, $suite, $get] = $this->context();
        $job = GenerationJob::factory()->create([
            'project_id' => $suite->project_id,
            'api_spec_id' => $suite->api_spec_id,
            'test_suite_id' => $suite->id,
            'strategy' => GenerationStrategy::Hybrid,
            'status' => GenerationJobStatus::Queued,
            'params' => [
                'endpoint_ids' => [$get->id],
                'seed' => 42,
                'experiment_id' => 7,
                'options' => ['model' => 'm'],
                'seed_cases' => [['title' => 'x']],
            ],
            'created_by' => $tester->id,
        ]);

        $params = $this->actingAs($tester)->getJson("/api/v1/generation-jobs/{$job->id}")
            ->assertOk()
            ->json('data.params');

        ksort($params);
        $this->assertSame(['endpoint_ids' => [$get->id], 'seed' => 42], $params);
    }

    public function test_api_show_returns_progress_and_statistics_summary(): void
    {
        [$tester, $suite, $get, $post] = $this->context();
        $job = GenerationJob::factory()->create([
            'project_id' => $suite->project_id,
            'api_spec_id' => $suite->api_spec_id,
            'test_suite_id' => $suite->id,
            'strategy' => GenerationStrategy::Llm,
            'status' => GenerationJobStatus::Partial,
            'params' => ['endpoint_ids' => [$get->id, $post->id], 'model' => 'm1'],
            'stats' => ['generated' => 4, 'valid' => 2, 'invalid' => 1, 'repaired' => 1, 'duplicates' => 1],
            'started_at' => now()->subSeconds(12),
            'finished_at' => now(),
            'created_by' => $tester->id,
        ]);
        $base = ['test_suite_id' => $suite->id, 'generation_job_id' => $job->id, 'source' => TestCaseSource::Llm, 'technique' => TestTechnique::HappyPath];
        TestCaseModel::factory()->create($base + ['endpoint_id' => $get->id, 'status' => TestCaseStatus::Valid, 'repair_attempts' => 1]);
        TestCaseModel::factory()->create($base + ['endpoint_id' => $get->id, 'status' => TestCaseStatus::Valid]);
        TestCaseModel::factory()->create($base + ['endpoint_id' => $get->id, 'status' => TestCaseStatus::Duplicate]);
        TestCaseModel::factory()->create($base + [
            'endpoint_id' => $post->id,
            'status' => TestCaseStatus::Invalid,
            'fingerprint' => null,
            'validation_errors' => ['Trường "qty" không có trong schema.'],
        ]);
        LlmCallLog::factory()->create(['generation_job_id' => $job->id, 'purpose' => LlmPurpose::Generate, 'prompt_tokens' => 100, 'completion_tokens' => 50, 'latency_ms' => 1000, 'model' => 'm1']);
        LlmCallLog::factory()->create(['generation_job_id' => $job->id, 'purpose' => LlmPurpose::Repair, 'prompt_tokens' => 40, 'completion_tokens' => 10, 'latency_ms' => 500, 'model' => 'm1', 'error' => 'timeout']);

        $admin = User::factory()->admin()->create();
        foreach ([$tester, $admin] as $user) {
            $this->actingAs($user)->getJson("/api/v1/generation-jobs/{$job->id}")
                ->assertOk()
                ->assertJsonPath('data.status', 'PARTIAL')
                ->assertJsonPath('data.summary.is_terminal', true)
                ->assertJsonPath('data.summary.progress_percent', 100)
                ->assertJsonPath('data.summary.counts', ['generated' => 4, 'valid' => 2, 'invalid' => 1, 'duplicates' => 1, 'repaired' => 1])
                ->assertJsonPath('data.summary.llm.calls', 2)
                ->assertJsonPath('data.summary.llm.repair_calls', 1)
                ->assertJsonPath('data.summary.llm.failed_calls', 1)
                ->assertJsonPath('data.summary.llm.total_tokens', 200)
                ->assertJsonPath('data.summary.llm.total_latency_ms', 1500)
                ->assertJsonPath('data.summary.llm.avg_latency_ms', 750)
                ->assertJsonPath('data.summary.llm.models', ['m1'])
                ->assertJsonPath('data.summary.duration_seconds', 12)
                ->assertJsonPath('data.summary.endpoints.0.endpoint_id', $get->id)
                ->assertJsonPath('data.summary.endpoints.0.valid', 2)
                ->assertJsonPath('data.summary.endpoints.0.duplicates', 1)
                ->assertJsonPath('data.summary.endpoints.0.repaired', 1)
                ->assertJsonPath('data.summary.endpoints.0.has_error', false)
                ->assertJsonPath('data.summary.endpoints.1.endpoint_id', $post->id)
                ->assertJsonPath('data.summary.endpoints.1.has_error', true)
                ->assertJsonPath('data.summary.endpoints.1.errors', ['Trường "qty" không có trong schema.']);
        }

        $this->actingAs($tester)->get("/generation-jobs/{$job->id}")
            ->assertOk()
            ->assertSee('PARTIAL')
            ->assertSee('Hoàn tất một phần')
            ->assertSee('Trường &quot;qty&quot; không có trong schema.', false)
            ->assertSee('id="llm-total-latency" x-text="fmt(summary.llm.total_latency_ms)">1.500<', false);
    }

    public function test_failed_ai_job_shows_vietnamese_error_and_queued_job_polls(): void
    {
        [$tester, $suite, $get] = $this->context();
        $this->ai->setReachable(false);

        $this->actingAs($tester)->post("/suites/{$suite->id}/generation-jobs", [
            'strategy' => 'LLM',
            'endpoint_ids' => [$get->id],
        ]);
        $job = GenerationJob::query()->latest('id')->firstOrFail();
        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertSame(ErrorCode::AiServiceUnavailable, GenerationJobError::code($job->error));

        $this->actingAs($tester)->get("/generation-jobs/{$job->id}")
            ->assertOk()
            ->assertSee('FAILED')
            ->assertSee('AI service không khả dụng hoặc kết nối bị từ chối. (AI_SERVICE_UNAVAILABLE)')
            ->assertDontSee('AI service is currently unavailable.')
            ->assertSee('Không sinh được test case nào cho endpoint này.');

        $queued = GenerationJob::factory()->create([
            'project_id' => $suite->project_id,
            'api_spec_id' => $suite->api_spec_id,
            'test_suite_id' => $suite->id,
            'strategy' => GenerationStrategy::Hybrid,
            'status' => GenerationJobStatus::Queued,
            'params' => ['endpoint_ids' => [$get->id]],
            'stats' => null,
            'created_by' => $tester->id,
        ]);

        $this->actingAs($tester)->get("/generation-jobs/{$queued->id}")
            ->assertOk()
            ->assertSee('QUEUED')
            ->assertSee('Đang chờ worker nhận job (QUEUED).')
            ->assertSee('Hủy tiến trình');

        $this->actingAs($tester)->getJson("/api/v1/generation-jobs/{$queued->id}")
            ->assertOk()
            ->assertJsonPath('data.summary.is_terminal', false)
            ->assertJsonPath('data.summary.progress_percent', 5)
            ->assertJsonPath('data.summary.counts.generated', 0)
            ->assertJsonPath('data.summary.endpoints.0.errors', []);
    }

    /**
     * @return array{0: User, 1: TestSuite, 2: Endpoint, 3: Endpoint}
     */
    private function context(): array
    {
        $tester = User::factory()->tester()->create();
        $project = Project::factory()->for($tester, 'owner')->create();
        $spec = ApiSpec::factory()->for($project)->create(['status' => ApiSpecStatus::Parsed]);
        $suite = TestSuite::factory()->create([
            'project_id' => $project->id,
            'api_spec_id' => $spec->id,
            'created_by' => $tester->id,
        ]);
        $get = Endpoint::factory()->create([
            'api_spec_id' => $spec->id,
            'method' => HttpMethod::Get,
            'path' => '/api/items',
            'parameters' => [
                ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
            ],
            'responses' => ['200' => ['description' => 'OK'], '422' => ['description' => 'Unprocessable']],
        ]);
        $post = Endpoint::factory()->create([
            'api_spec_id' => $spec->id,
            'method' => HttpMethod::Post,
            'path' => '/api/items',
            'request_body' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['name', 'quantity'],
                            'properties' => [
                                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 50],
                                'quantity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
                            ],
                        ],
                    ],
                ],
            ],
            'responses' => ['201' => ['description' => 'Created'], '422' => ['description' => 'Unprocessable']],
        ]);

        return [$tester, $suite, $get, $post];
    }
}
