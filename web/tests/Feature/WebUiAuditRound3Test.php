<?php

namespace Tests\Feature;

use App\Actions\Experiments\ShowExperimentAction;
use App\Actions\Generation\ExecuteAiGenerationAction;
use App\Actions\Generation\RecordLlmCallsAction;
use App\Enums\ApiSpecStatus;
use App\Enums\ErrorCode;
use App\Enums\ExperimentStatus;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Exceptions\ApiException;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Models\Experiment;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\Ai\FakeAiGeneratorClient;
use App\Services\Ai\HttpAiGeneratorClient;
use App\Services\Support\GenerationJobError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Generation\BudgetReportingAiClient;
use Tests\TestCase;

/**
 * Regression tests for the S10-01 web-ui audit (round 3).
 * The CLI batch / M15 grouping regression lives in Experiments\ExperimentCommandsTest.
 */
class WebUiAuditRound3Test extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: TestSuite}
     */
    private function ownedSuite(): array
    {
        $tester = User::factory()->tester()->create();
        $project = Project::factory()->for($tester, 'owner')->create();
        $spec = ApiSpec::factory()->for($project)->create(['status' => ApiSpecStatus::Parsed]);
        $suite = TestSuite::factory()->create([
            'project_id' => $project->id,
            'api_spec_id' => $spec->id,
            'created_by' => $tester->id,
        ]);
        Endpoint::factory()->create(['api_spec_id' => $spec->id, 'path' => '/items']);

        return [$tester, $suite];
    }

    private function queuedLlmJob(User $tester, TestSuite $suite): GenerationJob
    {
        return GenerationJob::factory()->create([
            'project_id' => $suite->project_id,
            'api_spec_id' => $suite->api_spec_id,
            'test_suite_id' => $suite->id,
            'created_by' => $tester->id,
            'strategy' => GenerationStrategy::Llm,
            'status' => GenerationJobStatus::Queued,
            'params' => [],
            'stats' => null,
        ]);
    }

    public function test_m11_shows_the_timeout_guard_hint_instead_of_ai_service_down(): void
    {
        [$tester, $suite] = $this->ownedSuite();
        config(['ait.ai_service.timeout_seconds' => 120]);
        $job = $this->queuedLlmJob($tester, $suite);

        try {
            (new ExecuteAiGenerationAction(new BudgetReportingAiClient(1320.0), new RecordLlmCallsAction))->execute($job);
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::AiServiceUnavailable, $e->errorCode);
        }

        $job->refresh();
        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertSame(ErrorCode::AiServiceUnavailable, GenerationJobError::code($job->error));
        $this->assertStringContainsString('AI_SERVICE_TIMEOUT=1380', (string) GenerationJobError::detail($job->error));

        $this->actingAs($tester)->get("/generation-jobs/{$job->id}")
            ->assertOk()
            ->assertSee('AI_SERVICE_TIMEOUT=1380')
            ->assertDontSee('Không gọi được ai-service');

        $summary = $this->actingAs($tester)->getJson("/api/v1/generation-jobs/{$job->id}")
            ->assertOk()
            ->json('data.summary');
        $this->assertIsArray($summary);
        $this->assertStringContainsString('AI_SERVICE_TIMEOUT=1380', (string) $summary['error_message']);
        $this->assertStringEndsWith('(AI_SERVICE_UNAVAILABLE)', (string) $summary['error_message']);
    }

    public function test_m11_shows_the_prompt_size_cause_of_an_ai_service_422(): void
    {
        [$tester, $suite] = $this->ownedSuite();
        $job = $this->queuedLlmJob($tester, $suite);
        Http::fake(['ai-service/v1/generate' => Http::response([
            'error' => [
                'code' => 'VALIDATION_FAILED',
                'message' => 'Prompt for POST /items has 40000 characters, exceeding the AI_MAX_PROMPT_CHARS limit of 36000; reduce the spec or business rules',
                'details' => [],
            ],
            'trace_id' => 't',
        ], 422)]);

        try {
            (new ExecuteAiGenerationAction(new HttpAiGeneratorClient('http://ai-service', 30), new RecordLlmCallsAction))->execute($job);
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::ValidationFailed, $e->errorCode);
        }

        $this->actingAs($tester)->get("/generation-jobs/{$job->refresh()->id}")
            ->assertOk()
            ->assertSee('AI_MAX_PROMPT_CHARS limit of 36000')
            ->assertDontSee('HYBRID thiếu case RULE làm hạt giống');
    }

    public function test_job_without_stored_detail_keeps_the_generic_message(): void
    {
        [$tester, $suite] = $this->ownedSuite();
        $job = $this->queuedLlmJob($tester, $suite);
        $job->forceFill(['status' => GenerationJobStatus::Failed, 'error' => 'AI_SERVICE_UNAVAILABLE'])->save();

        $this->actingAs($tester)->get("/generation-jobs/{$job->id}")
            ->assertOk()
            ->assertSee('Không gọi được ai-service (AI_SERVICE_UNAVAILABLE).');
    }

    public function test_m15_repetition_failure_shows_the_stored_cause(): void
    {
        [$tester, $suite] = $this->ownedSuite();
        $job = $this->queuedLlmJob($tester, $suite);
        $job->forceFill([
            'status' => GenerationJobStatus::Failed,
            'error' => GenerationJobError::compose('AI_SERVICE_UNAVAILABLE', 'Hãy đặt AI_SERVICE_TIMEOUT=1380 trong web/.env.'),
        ])->save();
        $experiment = Experiment::factory()->create([
            'status' => ExperimentStatus::Failed,
            'repetitions_data' => [['repetition' => 1, 'seed' => 42, 'generation_job_id' => $job->id]],
        ]);

        $failures = app(ShowExperimentAction::class)->generationFailures($experiment);

        $this->assertSame([1 => 'AI_SERVICE_UNAVAILABLE: Hãy đặt AI_SERVICE_TIMEOUT=1380 trong web/.env.'], $failures);
    }

    /** Regression S13-03: with AI_SERVICE_DRIVER=fake, M15 showed an English ai-service message (§8). */
    public function test_m15_shows_vietnamese_cause_when_fake_ai_service_is_down(): void
    {
        [$tester, $suite] = $this->ownedSuite();
        $job = $this->queuedLlmJob($tester, $suite);

        try {
            (new ExecuteAiGenerationAction(new FakeAiGeneratorClient(reachable: false), new RecordLlmCallsAction))->execute($job);
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::AiServiceUnavailable, $e->errorCode);
        }

        $experiment = Experiment::factory()->create([
            'status' => ExperimentStatus::Failed,
            'repetitions_data' => [['repetition' => 1, 'seed' => 42, 'generation_job_id' => $job->id]],
        ]);

        $failures = app(ShowExperimentAction::class)->generationFailures($experiment);

        $this->assertSame([1 => 'AI_SERVICE_UNAVAILABLE: AI service không khả dụng hoặc kết nối bị từ chối.'], $failures);
    }

    public function test_m11_tells_that_llm_cases_per_endpoint_are_capped_by_ai_service(): void
    {
        [$tester, $suite] = $this->ownedSuite();

        $this->actingAs($tester)->get("/suites/{$suite->id}/generation-jobs/create")
            ->assertOk()
            ->assertSee('id="llm-cases-cap-note"', false)
            ->assertSee('mặc định 3')
            ->assertDontSee('AI_LLM_CASES_PER_CALL');

        $llmJob = $this->queuedLlmJob($tester, $suite);
        $llmJob->forceFill(['params' => ['max_cases_per_endpoint' => 10]])->save();
        $this->actingAs($tester)->get("/generation-jobs/{$llmJob->id}")
            ->assertOk()
            ->assertSee('id="llm-cases-cap-hint"', false);

        $ruleJob = $this->queuedLlmJob($tester, $suite);
        $ruleJob->forceFill(['strategy' => GenerationStrategy::Rule])->save();
        $this->actingAs($tester)->get("/generation-jobs/{$ruleJob->id}")
            ->assertOk()
            ->assertDontSee('id="llm-cases-cap-hint"', false);
    }
}
