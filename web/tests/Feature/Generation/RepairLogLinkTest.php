<?php

namespace Tests\Feature\Generation;

use App\Actions\Generation\ProcessGeneratedTestCasesAction;
use App\Actions\TestCases\ListTestCaseLlmLogsAction;
use App\Contracts\AiGeneratorClient;
use App\Data\Ai\AiGeneratedTestCase;
use App\Data\Ai\AiGenerateRequest;
use App\Data\Ai\AiGenerateResponse;
use App\Data\Ai\AiGenerationStats;
use App\Data\Ai\AiLlmCall;
use App\Data\AiServiceStatus;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\HttpMethod;
use App\Enums\LlmPurpose;
use App\Exceptions\ApiException;
use App\Jobs\GenerateTestsJob;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Models\GenerationJob;
use App\Models\LlmCallLog;
use App\Models\TestCase as TestCaseModel;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression (QA S13-03): REPAIR calls were stored with test_case_id NULL, so M10 tab 3 listed every
 * repair of the generation job under every case, including cases that needed no repair.
 */
class RepairLogLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private GenerationJob $job;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->tester()->create();
        $spec = ApiSpec::factory()->create();
        $spec->project->update(['owner_id' => $this->owner->id]);
        $endpoint = Endpoint::factory()->create([
            'api_spec_id' => $spec->id,
            'method' => HttpMethod::Get,
            'path' => '/items',
            'responses' => ['200' => ['description' => 'OK']],
        ]);
        $suite = TestSuite::factory()->create(['project_id' => $spec->project_id, 'api_spec_id' => $spec->id]);
        $this->job = GenerationJob::factory()->create([
            'project_id' => $spec->project_id,
            'api_spec_id' => $spec->id,
            'test_suite_id' => $suite->id,
            'strategy' => GenerationStrategy::Llm,
            'status' => GenerationJobStatus::Queued,
            'params' => ['endpoint_ids' => [$endpoint->id]],
        ]);
    }

    public function test_case_index_is_parsed_and_optional(): void
    {
        $base = [
            'purpose' => 'REPAIR', 'provider' => 'fake', 'model' => 'm', 'prompt' => [['role' => 'user', 'content' => 'x']],
            'response_raw' => '{}', 'temperature' => 0.2, 'seed' => 42, 'prompt_tokens' => 1, 'completion_tokens' => 1,
            'latency_ms' => 1, 'error' => null,
        ];

        $this->assertNull(AiLlmCall::fromArray($base)->caseIndex);
        $this->assertNull(AiLlmCall::fromArray([...$base, 'case_index' => null])->caseIndex);
        $this->assertSame(2, AiLlmCall::fromArray([...$base, 'case_index' => 2])->caseIndex);
        $this->assertSame(2, AiLlmCall::fromArray([...$base, 'case_index' => 2])->toArray()['case_index']);
        $this->assertArrayNotHasKey('case_index', AiLlmCall::fromArray($base)->toArray());

        $this->expectException(\InvalidArgumentException::class);
        AiLlmCall::fromArray([...$base, 'case_index' => -1]);
    }

    public function test_repair_logs_are_linked_to_their_case_and_m10_lists_only_them(): void
    {
        $this->fakeAiService();

        $this->app->call([new GenerateTestsJob($this->job->id), 'handle']);

        $cases = TestCaseModel::query()->where('generation_job_id', $this->job->id)->orderBy('id')->get();
        $this->assertCount(3, $cases);
        [$repairedTwice, $noRepair, $repairedOnce] = $cases->all();

        $this->assertSame(5, LlmCallLog::query()->where('generation_job_id', $this->job->id)->count());
        $this->assertSame(1, LlmCallLog::query()->where('purpose', LlmPurpose::Generate)->whereNull('test_case_id')->count());
        $this->assertSame(['repair A1', 'repair A2'], $this->repairResponses($repairedTwice));
        $this->assertSame([], $this->repairResponses($noRepair));
        $this->assertSame(['repair C1'], $this->repairResponses($repairedOnce));

        $this->actingAs($this->owner)->get("/test-cases/{$noRepair->id}")
            ->assertOk()
            ->assertSee('generate raw')
            ->assertDontSee('repair A1')
            ->assertDontSee('repair C1');

        $this->actingAs($this->owner)->get("/test-cases/{$repairedTwice->id}")
            ->assertOk()
            ->assertSee('generate raw')
            ->assertSee('repair A1')
            ->assertSee('repair A2')
            ->assertDontSee('repair C1');
    }

    public function test_linked_repair_logs_are_kept_when_persisting_the_cases_fails(): void
    {
        $this->job->update(['status' => GenerationJobStatus::Succeeded]);

        try {
            app(ProcessGeneratedTestCasesAction::class)->execute(
                $this->job,
                [$this->generatedCase('A', 'INVALID', 1)],
                [$this->llmCall(LlmPurpose::Repair, 'repair A1', 0)],
            );
            $this->fail('A job that is not RUNNING must not receive cases.');
        } catch (ApiException) {
        }

        $this->assertSame(0, TestCaseModel::query()->count());
        $this->assertSame(['repair A1'], LlmCallLog::query()->whereNull('test_case_id')->pluck('response_raw')->all());
    }

    public function test_jobs_logged_before_the_link_keep_their_repairs_on_repaired_cases_only(): void
    {
        $repaired = TestCaseModel::factory()->create([
            'test_suite_id' => $this->job->test_suite_id,
            'generation_job_id' => $this->job->id,
            'repair_attempts' => 2,
        ]);
        $notRepaired = TestCaseModel::factory()->create([
            'test_suite_id' => $this->job->test_suite_id,
            'generation_job_id' => $this->job->id,
            'repair_attempts' => 0,
        ]);
        foreach ([[LlmPurpose::Generate, 'legacy generate'], [LlmPurpose::Repair, 'legacy repair']] as [$purpose, $raw]) {
            LlmCallLog::factory()->create([
                'generation_job_id' => $this->job->id, 'test_case_id' => null, 'purpose' => $purpose, 'response_raw' => $raw,
            ]);
        }

        $this->actingAs($this->owner)->get("/test-cases/{$repaired->id}")
            ->assertOk()->assertSee('legacy generate')->assertSee('legacy repair');
        $this->actingAs($this->owner)->get("/test-cases/{$notRepaired->id}")
            ->assertOk()->assertSee('legacy generate')->assertDontSee('legacy repair');
    }

    /**
     * Merge of the two M10 fixes (QA S13-03): the endpoint match of web-ui never pulls in a REPAIR of
     * another case on the same endpoint, and a dropped case's repair is listed nowhere.
     */
    public function test_endpoint_matching_never_lists_another_cases_repair(): void
    {
        $endpoint = Endpoint::query()->where('path', '/items')->firstOrFail();
        [$own, $other] = TestCaseModel::factory()->count(2)->create([
            'test_suite_id' => $this->job->test_suite_id,
            'generation_job_id' => $this->job->id,
            'endpoint_id' => $endpoint->id,
            'repair_attempts' => 1,
        ])->all();
        $prompt = [['role' => 'user', 'content' => 'Every case uses "endpoint": {"method": "GET", "path": "/items"}.']];
        $log = fn (LlmPurpose $purpose, string $raw, ?int $caseId): LlmCallLog => LlmCallLog::factory()->create([
            'generation_job_id' => $this->job->id, 'test_case_id' => $caseId, 'purpose' => $purpose,
            'prompt' => $prompt, 'response_raw' => $raw,
        ]);
        $log(LlmPurpose::Generate, 'generate items', null);
        $log(LlmPurpose::Repair, 'repair own', $own->id);
        $log(LlmPurpose::Repair, 'repair other', $other->id);
        $log(LlmPurpose::Repair, 'repair dropped', null);
        LlmCallLog::factory()->create([
            'generation_job_id' => $this->job->id, 'purpose' => LlmPurpose::Generate, 'response_raw' => 'generate orders',
            'prompt' => [['role' => 'user', 'content' => 'Every case uses "endpoint": {"method": "GET", "path": "/orders"}.']],
        ]);

        $logs = app(ListTestCaseLlmLogsAction::class)->execute($own);

        $this->assertSame(['generate items', 'repair own'], $logs['related']->pluck('response_raw')->all());
        $this->assertSame(['generate orders'], $logs['others']->pluck('response_raw')->all());
    }

    /**
     * @return list<string>
     */
    private function repairResponses(TestCaseModel $case): array
    {
        /** @var list<string> $raw */
        $raw = LlmCallLog::query()
            ->where('test_case_id', $case->id)
            ->where('purpose', LlmPurpose::Repair)
            ->orderBy('id')
            ->pluck('response_raw')
            ->all();

        return $raw;
    }

    private function fakeAiService(): void
    {
        $response = new AiGenerateResponse(
            testCases: [
                $this->generatedCase('A', 'INVALID', 2),
                $this->generatedCase('B', 'VALID', 0),
                $this->generatedCase('C', 'VALID', 1),
            ],
            llmCalls: [
                $this->llmCall(LlmPurpose::Generate, 'generate raw', null),
                $this->llmCall(LlmPurpose::Repair, 'repair A1', 0),
                $this->llmCall(LlmPurpose::Repair, 'repair A2', 0),
                $this->llmCall(LlmPurpose::Repair, 'repair C1', 2),
                $this->llmCall(LlmPurpose::Repair, 'repair of a dropped case', null),
            ],
            stats: new AiGenerationStats(generated: 3, valid: 2, invalid: 1, repaired: 1, duplicates: 0),
        );

        $this->app->instance(AiGeneratorClient::class, new class($response) implements AiGeneratorClient
        {
            public function __construct(private AiGenerateResponse $response) {}

            public function status(): AiServiceStatus
            {
                return new AiServiceStatus(reachable: true, provider: 'fake');
            }

            public function generate(AiGenerateRequest $request): AiGenerateResponse
            {
                return $this->response;
            }
        });
    }

    private function generatedCase(string $label, string $status, int $repairs): AiGeneratedTestCase
    {
        return new AiGeneratedTestCase(
            dsl: [
                'dsl_version' => '1.0',
                'title' => "Case {$label}",
                'endpoint' => ['method' => 'GET', 'path' => '/items'],
                'technique' => 'HAPPY_PATH',
                'source' => 'LLM',
                'steps' => [[
                    'name' => "Call {$label}",
                    'request' => ['method' => 'GET', 'path' => '/items', 'query' => ['case' => $label]],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ]],
            ],
            status: $status,
            validationErrors: $status === 'INVALID' ? ['still invalid'] : [],
            repairAttempts: $repairs,
        );
    }

    private function llmCall(LlmPurpose $purpose, string $raw, ?int $caseIndex): AiLlmCall
    {
        return new AiLlmCall(
            purpose: $purpose,
            provider: 'fake',
            model: 'test-model',
            prompt: [['role' => 'user', 'content' => $purpose->value]],
            responseRaw: $raw,
            temperature: 0.2,
            seed: 42,
            promptTokens: 10,
            completionTokens: 5,
            latencyMs: 12,
            caseIndex: $caseIndex,
        );
    }
}
