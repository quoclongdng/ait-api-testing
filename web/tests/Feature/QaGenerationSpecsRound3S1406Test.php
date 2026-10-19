<?php

namespace Tests\Feature;

use App\Actions\Generation\ConvergeRecordedLlmJobsAction;
use App\Actions\Generation\ExecuteRuleGenerationAction;
use App\Actions\Generation\ProcessGeneratedTestCasesAction;
use App\Actions\Generation\SummarizeGenerationJobAction;
use App\Data\Ai\AiGeneratedTestCase;
use App\Enums\ApiSpecFormat;
use App\Enums\ApiSpecStatus;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\HttpMethod;
use App\Enums\LlmPurpose;
use App\Enums\TestCaseSource;
use App\Enums\TestCaseStatus;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Models\GenerationJob;
use App\Models\LlmCallLog;
use App\Models\Project;
use App\Models\TestCase as TestCaseModel;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\OpenApi\OpenApiParser;
use App\Services\TestEngine\TestDeduplicationService;
use App\Services\TestEngine\TestFingerprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * S14-06 live QA round 3 (generation, specs): honest label for duplicate-only endpoints, dedup only
 * against VALID/APPROVED cases, UTF-8 BOM in JSON specs, YAML !php/* tags and convergence of the
 * replayed showcase jobs imported before the message fixes.
 */
class QaGenerationSpecsRound3S1406Test extends TestCase
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
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function dsl(string $path, string $title = 'Case', array $query = []): array
    {
        $request = ['method' => 'GET', 'path' => $path];
        if ($query !== []) {
            $request['query'] = $query;
        }

        return [
            'dsl_version' => '1.0',
            'title' => $title,
            'endpoint' => ['method' => 'GET', 'path' => $path],
            'technique' => 'HAPPY_PATH',
            'source' => 'LLM',
            'steps' => [[
                'name' => 'Call',
                'request' => $request,
                'assertions' => [['type' => 'status', 'equals' => 200]],
            ]],
        ];
    }

    /**
     * @param  list<int>  $endpointIds
     * @param  array<string, mixed>  $attributes
     */
    private function job(array $endpointIds, array $attributes = []): GenerationJob
    {
        return GenerationJob::factory()->create([
            'project_id' => $this->project->id,
            'api_spec_id' => $this->spec->id,
            'test_suite_id' => $this->suite->id,
            'strategy' => GenerationStrategy::Llm,
            'status' => GenerationJobStatus::Running,
            'params' => ['endpoint_ids' => $endpointIds],
            'created_by' => $this->tester->id,
            ...$attributes,
        ]);
    }

    private function process(GenerationJob $job, AiGeneratedTestCase ...$cases): GenerationJob
    {
        app(ProcessGeneratedTestCasesAction::class)->execute($job, array_values($cases));

        return $job->refresh();
    }

    // r3-1 ---------------------------------------------------------------------------------------

    public function test_succeeded_job_with_a_duplicate_only_endpoint_says_so_in_label_and_row(): void
    {
        $this->process($this->job([$this->orders->id]), new AiGeneratedTestCase($this->dsl('/orders'), 'VALID', [], 0));

        $job = $this->process(
            $this->job([$this->orders->id, $this->items->id]),
            new AiGeneratedTestCase($this->dsl('/orders', 'Again'), 'VALID', [], 0),
            new AiGeneratedTestCase($this->dsl('/items'), 'VALID', [], 0),
        );
        $this->assertSame(GenerationJobStatus::Succeeded, $job->status);

        $summary = app(SummarizeGenerationJobAction::class)->execute($job);
        $this->assertStringNotContainsString('mọi endpoint đều có test case hợp lệ', $summary->progressLabel);
        $this->assertStringContainsString('1 endpoint không có test case mới', $summary->progressLabel);

        $ordersRow = collect($summary->endpoints)->firstWhere('endpoint_id', $this->orders->id);
        $itemsRow = collect($summary->endpoints)->firstWhere('endpoint_id', $this->items->id);
        $this->assertIsArray($ordersRow);
        $this->assertIsArray($itemsRow);
        $this->assertFalse($ordersRow['has_error']);
        $this->assertSame('Mọi case đều trùng với case đã có trong suite: không có case mới.', $ordersRow['note']);
        $this->assertNull($itemsRow['note']);

        $this->actingAs($this->tester)->get("/generation-jobs/{$job->id}")
            ->assertOk()
            ->assertSeeText('Mọi case đều trùng với case đã có trong suite: không có case mới.')
            ->assertDontSeeText('mọi endpoint đều có test case hợp lệ');

        $this->actingAs($this->tester)->getJson("/api/v1/generation-jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.summary.endpoints.1.note', null);
    }

    public function test_succeeded_job_where_every_endpoint_has_a_new_case_keeps_the_plain_label(): void
    {
        $job = $this->process($this->job([$this->orders->id]), new AiGeneratedTestCase($this->dsl('/orders'), 'VALID', [], 0));

        $summary = app(SummarizeGenerationJobAction::class)->execute($job);
        $this->assertSame('Hoàn tất: mọi endpoint đều có test case hợp lệ.', $summary->progressLabel);
        $this->assertNull($summary->endpoints[0]['note']);
    }

    public function test_succeeded_rule_job_with_an_endpoint_of_duplicates_and_invalid_cases_does_not_claim_all_duplicates(): void
    {
        // RULE counts an endpoint by its VALID cases before dedup, so a SUCCEEDED job can have an
        // endpoint whose cases ended DUPLICATE and INVALID only (review of S14-06 round 3).
        $job = $this->job([$this->orders->id, $this->items->id], [
            'strategy' => GenerationStrategy::Rule,
            'status' => GenerationJobStatus::Succeeded,
            'stats' => ['generated' => 3, 'valid' => 1, 'invalid' => 1, 'duplicates' => 1, 'repaired' => 0],
        ]);
        $base = [
            'test_suite_id' => $this->suite->id,
            'generation_job_id' => $job->id,
            'source' => TestCaseSource::Rule,
            'repair_attempts' => 0,
        ];
        TestCaseModel::factory()->create([...$base, 'endpoint_id' => $this->orders->id, 'dsl' => $this->dsl('/orders'), 'status' => TestCaseStatus::Duplicate]);
        TestCaseModel::factory()->create([...$base, 'endpoint_id' => $this->orders->id, 'dsl' => $this->dsl('/orders', 'Bad'), 'status' => TestCaseStatus::Invalid, 'fingerprint' => null, 'validation_errors' => ['Lỗi kiểm tra.']]);
        TestCaseModel::factory()->create([...$base, 'endpoint_id' => $this->items->id, 'dsl' => $this->dsl('/items'), 'status' => TestCaseStatus::Valid]);

        $summary = app(SummarizeGenerationJobAction::class)->execute($job->refresh());

        $this->assertStringNotContainsString('mọi test case sinh ra đều trùng', $summary->progressLabel);
        $this->assertStringContainsString('1 endpoint không có test case mới (test case trùng với test case đã có hoặc không hợp lệ)', $summary->progressLabel);
        $ordersRow = collect($summary->endpoints)->firstWhere('endpoint_id', $this->orders->id);
        $this->assertIsArray($ordersRow);
        $this->assertSame('1 case trùng với case đã có trong suite, các case còn lại không hợp lệ.', $ordersRow['note']);
    }

    // r3-3 ---------------------------------------------------------------------------------------

    public function test_llm_case_is_valid_again_when_its_original_was_edited_and_only_a_stale_duplicate_remains(): void
    {
        $first = $this->process($this->job([$this->orders->id]), new AiGeneratedTestCase($this->dsl('/orders'), 'VALID', [], 0));
        $this->process($this->job([$this->orders->id]), new AiGeneratedTestCase($this->dsl('/orders', 'Twice'), 'VALID', [], 0));
        $original = TestCaseModel::query()->where('generation_job_id', $first->id)->sole();
        $this->assertSame(1, TestCaseModel::query()->where('status', TestCaseStatus::Duplicate->value)->count());

        // The original now has other content: no VALID/APPROVED case of the suite has the old fingerprint.
        $edited = $this->dsl('/orders', 'Edited');
        $edited['steps'][0]['assertions'][] = ['type' => 'response_time', 'lte_ms' => 2000];
        $this->actingAs($this->tester)->patchJson("/api/v1/test-cases/{$original->id}", ['dsl' => $edited])->assertOk();

        $third = $this->process($this->job([$this->orders->id]), new AiGeneratedTestCase($this->dsl('/orders', 'Third'), 'VALID', [], 0));

        $this->assertSame(1, $third->stats['valid'] ?? null);
        $this->assertSame(0, $third->stats['duplicates'] ?? null);
    }

    public function test_llm_case_is_valid_again_when_its_original_was_archived(): void
    {
        $first = $this->process($this->job([$this->orders->id]), new AiGeneratedTestCase($this->dsl('/orders'), 'VALID', [], 0));
        TestCaseModel::query()->where('generation_job_id', $first->id)->update(['status' => TestCaseStatus::Archived->value]);

        $second = $this->process($this->job([$this->orders->id]), new AiGeneratedTestCase($this->dsl('/orders', 'Again'), 'VALID', [], 0));

        $this->assertSame(1, $second->stats['valid'] ?? null);
        $this->assertSame(0, $second->stats['duplicates'] ?? null);
    }

    public function test_approved_case_still_counts_as_existing(): void
    {
        $first = $this->process($this->job([$this->orders->id]), new AiGeneratedTestCase($this->dsl('/orders'), 'VALID', [], 0));
        TestCaseModel::query()->where('generation_job_id', $first->id)->update(['status' => TestCaseStatus::Approved->value]);

        $second = $this->process($this->job([$this->orders->id]), new AiGeneratedTestCase($this->dsl('/orders', 'Again'), 'VALID', [], 0));

        $this->assertSame(0, $second->stats['valid'] ?? null);
        $this->assertSame(1, $second->stats['duplicates'] ?? null);
    }

    public function test_rule_job_does_not_match_stale_duplicate_rows(): void
    {
        $rule = fn (): GenerationJob => app(ExecuteRuleGenerationAction::class)->execute($this->job([$this->orders->id], [
            'strategy' => GenerationStrategy::Rule,
            'status' => GenerationJobStatus::Queued,
            'params' => ['endpoint_ids' => [$this->orders->id], 'techniques' => ['HAPPY_PATH']],
        ])->id)->refresh();

        $first = $rule();
        $this->assertGreaterThan(0, $first->stats['valid'] ?? 0);
        $second = $rule();
        $this->assertSame(0, $second->stats['valid'] ?? null, 'precondition: the second run only finds duplicates');

        // Retire the originals: only the DUPLICATE rows of the second run still carry the fingerprints.
        TestCaseModel::query()->where('generation_job_id', $first->id)->update(['status' => TestCaseStatus::Archived->value]);

        $third = $rule();
        $this->assertSame($first->stats['valid'] ?? null, $third->stats['valid'] ?? null);
        $this->assertSame(0, $third->stats['duplicates'] ?? null);
    }

    public function test_dedup_service_counts_only_valid_and_approved_cases_as_existing(): void
    {
        $fingerprints = app(TestFingerprintService::class);
        foreach (TestCaseStatus::cases() as $i => $status) {
            $dsl = $this->dsl('/orders', 'S', ['n' => (string) $i]);
            TestCaseModel::factory()->create([
                'test_suite_id' => $this->suite->id,
                'endpoint_id' => $this->orders->id,
                'dsl' => $dsl,
                'fingerprint' => $fingerprints->calculate($dsl),
                'status' => $status,
            ]);
        }

        $service = app(TestDeduplicationService::class);
        $existing = $service->existingFingerprints($this->suite->id);
        $this->assertCount(2, $existing);

        $result = $service->deduplicateBatch(array_map(
            fn (int $i): array => ['dsl' => $this->dsl('/orders', 'New', ['n' => (string) $i])],
            array_keys(TestCaseStatus::cases()),
        ), $this->suite->id);
        $statuses = array_map(static fn (array $r): TestCaseStatus => $r['status'], $result->processed);
        $expected = array_map(
            static fn (TestCaseStatus $s): TestCaseStatus => in_array($s, TestDeduplicationService::EXISTING_STATUSES, true) ? TestCaseStatus::Duplicate : TestCaseStatus::Valid,
            TestCaseStatus::cases(),
        );
        $this->assertSame($expected, $statuses);
    }

    // r3-4 ---------------------------------------------------------------------------------------

    private function jsonSpec(string $title = 'Bom'): string
    {
        return (string) json_encode([
            'openapi' => '3.0.3',
            'info' => ['title' => $title, 'version' => '1'],
            'paths' => ['/ping' => ['get' => ['responses' => ['200' => ['description' => 'ok']]]]],
        ]);
    }

    public function test_json_spec_with_a_utf8_bom_is_accepted_and_stored_without_it(): void
    {
        $content = $this->jsonSpec();

        $id = (int) $this->actingAs($this->tester)->postJson("/api/v1/projects/{$this->project->id}/specs", [
            'version_label' => 'bom',
            'format' => 'json',
            'raw_content' => OpenApiParser::UTF8_BOM.$content,
        ])->assertCreated()->json('data.id');

        $stored = ApiSpec::query()->whereKey($id)->firstOrFail();
        $this->assertSame(ApiSpecStatus::Parsed, $stored->status);
        $this->assertSame($content, $stored->raw_content);
        $this->assertSame(hash('sha256', $content), $stored->checksum);

        // The same document without a BOM is the same version.
        $this->actingAs($this->tester)->postJson("/api/v1/projects/{$this->project->id}/specs", [
            'version_label' => 'no bom',
            'format' => 'json',
            'raw_content' => $content,
        ])->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');
    }

    public function test_pasted_json_spec_with_a_bom_is_detected_as_json_on_m06(): void
    {
        $this->actingAs($this->tester)->post("/projects/{$this->project->id}/specs", [
            'version_label' => 'bom form',
            'input_method' => 'text',
            'spec_text' => OpenApiParser::UTF8_BOM.$this->jsonSpec('Form'),
        ])->assertSessionHas('success');

        $stored = ApiSpec::query()->where('version_label', 'bom form')->firstOrFail();
        $this->assertSame(ApiSpecFormat::Json, $stored->format);
        $this->assertSame('pasted-specification.json', $stored->source_filename);
    }

    // r3-2 ---------------------------------------------------------------------------------------

    public function test_php_object_tag_upload_is_rejected_with_the_custom_tag_message(): void
    {
        $yaml = "openapi: 3.0.3\ninfo:\n  title: !php/object 'O:8:\"stdClass\":0:{}'\n  version: '1'\npaths: {}\n";

        $this->actingAs($this->tester)->post("/projects/{$this->project->id}/specs", [
            'version_label' => 'phpobj',
            'input_method' => 'text',
            'spec_text' => $yaml,
        ])->assertSessionHas('spec_parse_errors', fn (array $errors): bool => count($errors) === 1
            && str_contains($errors[0], 'thẻ tùy biến !php/object')
            && ! str_contains($errors[0], 'missing'));
    }

    // r3-showcase-messages -------------------------------------------------------------------------

    public function test_convergence_rewrites_old_messages_of_replayed_jobs_only_and_is_idempotent(): void
    {
        $replay = $this->job([$this->orders->id], [
            'strategy' => GenerationStrategy::Hybrid,
            'status' => GenerationJobStatus::Succeeded,
            'params' => ['endpoint_ids' => [$this->orders->id], GenerationJob::RECORDED_SOURCE_PARAM => 77],
            'stats' => ['generated' => 4, 'valid' => 0, 'invalid' => 4, 'duplicates' => 0, 'repaired' => 0, 'tokens' => 99],
            'error' => 'old',
        ]);
        $live = $this->job([$this->orders->id], ['status' => GenerationJobStatus::Succeeded]);

        $case = fn (GenerationJob $job, array $dsl, array $errors, TestCaseSource $source = TestCaseSource::Hybrid): TestCaseModel => TestCaseModel::factory()->create([
            'test_suite_id' => $this->suite->id,
            'endpoint_id' => $this->orders->id,
            'generation_job_id' => $job->id,
            'source' => $source,
            'dsl' => $dsl,
            'fingerprint' => null,
            'status' => TestCaseStatus::Invalid,
            'validation_errors' => $errors,
        ]);
        $undeclared = $this->dsl('/orders', 'Undeclared', ['invalid_param' => 'x']);
        $twice = $case($replay, $undeclared, [
            "Tham số query 'invalid_param' không được khai báo cho GET /orders.",
            "Tham số truy vấn 'invalid_param' không tồn tại trong đặc tả.",
            'The properties must match schema: steps',
        ]);
        $repair = $case($replay, $this->dsl('/orders', 'Repair'), [
            'The data must match $ref',
            'Repair must preserve the original target endpoint',
        ]);
        $english = $case($replay, $this->dsl('/orders', 'English'), [
            'The required properties (assertions) are missing',
            'All array items must match schema',
            "Lỗi schema tại 'steps.0': 'assertions' is a required property",
        ]);
        $manual = $case($replay, $undeclared, ['The data must match $ref'], TestCaseSource::Manual);
        $other = $case($live, $undeclared, ['The data must match $ref'], TestCaseSource::Llm);
        LlmCallLog::factory()->create(['generation_job_id' => $replay->id, 'purpose' => LlmPurpose::Generate]);
        $logs = LlmCallLog::query()->orderBy('id')->get()->toArray();

        $result = app(ConvergeRecordedLlmJobsAction::class)->execute($this->suite);
        $this->assertSame(['jobs' => 1, 'cases' => 3], $result);

        $twiceErrors = $twice->refresh()->validation_errors;
        $this->assertIsArray($twiceErrors);
        $this->assertCount(1, $twiceErrors);
        $this->assertStringContainsString("'invalid_param'", (string) array_values($twiceErrors)[0]);
        $this->assertSame(['Bản sửa phải giữ nguyên endpoint đích của test case ban đầu.'], $repair->refresh()->validation_errors);
        $this->assertSame([ProcessGeneratedTestCasesAction::AI_GAVE_UP_MESSAGE], $english->refresh()->validation_errors);
        $this->assertSame(['The data must match $ref'], $manual->refresh()->validation_errors, 'a visitor\'s edit is kept');
        $this->assertSame(['The data must match $ref'], $other->refresh()->validation_errors, 'a live job is not a replay');

        $replay->refresh();
        $stats = $replay->stats ?? [];
        ksort($stats);
        $this->assertSame(['duplicates' => 0, 'generated' => 4, 'invalid' => 4, 'repaired' => 0, 'valid' => 0], $stats);
        $this->assertNull($replay->error);
        $this->assertSame($logs, LlmCallLog::query()->orderBy('id')->get()->toArray(), 'llm_call_logs are insert-only');

        // Second run: nothing changes, not even updated_at.
        Carbon::setTestNow(now()->addHour());
        $stamps = TestCaseModel::query()->orderBy('id')->pluck('updated_at', 'id')->map(fn ($t): string => (string) $t)->all();
        $jobStamp = (string) $replay->updated_at;
        $this->assertSame(['jobs' => 0, 'cases' => 0], app(ConvergeRecordedLlmJobsAction::class)->execute($this->suite));
        $this->assertSame($stamps, TestCaseModel::query()->orderBy('id')->pluck('updated_at', 'id')->map(fn ($t): string => (string) $t)->all());
        $this->assertSame($jobStamp, (string) $replay->refresh()->updated_at);
        Carbon::setTestNow();
    }
}
