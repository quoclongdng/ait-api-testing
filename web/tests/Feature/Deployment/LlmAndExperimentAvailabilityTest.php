<?php

namespace Tests\Feature\Deployment;

use App\Actions\Experiments\CreateExperimentAction;
use App\Actions\Generation\ExecuteAiGenerationAction;
use App\Actions\Generation\RecordLlmCallsAction;
use App\Actions\Generation\ResolveLlmAvailabilityAction;
use App\Contracts\AiGeneratorClient;
use App\Enums\ApiSpecStatus;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\HttpMethod;
use App\Exceptions\ApiException;
use App\Jobs\RunExperimentJob;
use App\Models\ApiSpec;
use App\Models\Benchmark;
use App\Models\BenchmarkBug;
use App\Models\Endpoint;
use App\Models\Experiment;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\Ai\FakeAiGeneratorClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ADR-0007 production profile: AI_LLM_PROVIDER=disabled (only RULE) and EXPERIMENTS_ENABLED=false.
 */
class LlmAndExperimentAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiGeneratorClient $ai;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ai = new FakeAiGeneratorClient;
        $this->app->instance(AiGeneratorClient::class, $this->ai);
    }

    // --- AI_LLM_PROVIDER=disabled -------------------------------------------------------------

    public function test_api_refuses_llm_and_hybrid_generation_when_ai_service_has_no_llm(): void
    {
        Queue::fake();
        $this->ai->setProvider('disabled');
        [$tester, $suite, $endpoint] = $this->suite();

        foreach (['LLM', 'HYBRID'] as $strategy) {
            $this->actingAs($tester)->postJson("/api/v1/suites/{$suite->id}/generation-jobs", [
                'strategy' => $strategy,
                'params' => ['endpoint_ids' => [$endpoint->id]],
            ])
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'VALIDATION_FAILED')
                ->assertJsonPath('error.message', ResolveLlmAvailabilityAction::DISABLED_MESSAGE)
                ->assertJsonPath('error.details.strategy.0', ResolveLlmAvailabilityAction::DISABLED_MESSAGE);
        }

        $this->assertSame(0, GenerationJob::query()->count());
        $this->assertSame([], $this->ai->recordedRequests());
    }

    public function test_rule_generation_still_works_when_llm_is_disabled(): void
    {
        Queue::fake();
        $this->ai->setProvider('disabled');
        [$tester, $suite, $endpoint] = $this->suite();

        $this->actingAs($tester)->postJson("/api/v1/suites/{$suite->id}/generation-jobs", [
            'strategy' => 'RULE',
            'params' => ['endpoint_ids' => [$endpoint->id]],
        ])->assertSuccessful();

        $this->assertSame(1, GenerationJob::query()->where('strategy', GenerationStrategy::Rule)->count());
    }

    public function test_m11_disables_llm_and_hybrid_with_vietnamese_reason(): void
    {
        $this->ai->setProvider('disabled');
        [$tester, $suite] = $this->suite();

        $response = $this->actingAs($tester)->get("/suites/{$suite->id}/generation-jobs/create")->assertOk();

        $response->assertSee('data-testid="llm-disabled"', false)
            ->assertSee(ResolveLlmAvailabilityAction::DISABLED_MESSAGE)
            ->assertSee('\u0022llmDisabled\u0022:true', false);
        $html = (string) $response->getContent();
        $this->assertMatchesRegularExpression('/id="strategy-llm"[^>]*\bdisabled aria-disabled="true"/s', $html);
        $this->assertMatchesRegularExpression('/id="strategy-hybrid"[^>]*\bdisabled aria-disabled="true"/s', $html);
        $this->assertDoesNotMatchRegularExpression('/id="strategy-rule"[^>]*\bdisabled aria-disabled/s', $html);
    }

    public function test_m11_keeps_every_strategy_when_llm_is_available(): void
    {
        [$tester, $suite] = $this->suite();

        $this->actingAs($tester)->get("/suites/{$suite->id}/generation-jobs/create")
            ->assertOk()
            ->assertDontSee('data-testid="llm-disabled"', false)
            ->assertSee('\u0022llmDisabled\u0022:false', false);
    }

    public function test_m11_form_post_of_llm_goes_back_with_the_reason(): void
    {
        $this->ai->setProvider('disabled');
        [$tester, $suite, $endpoint] = $this->suite();

        $this->actingAs($tester)
            ->from("/suites/{$suite->id}/generation-jobs/create")
            ->post("/suites/{$suite->id}/generation-jobs", ['strategy' => 'LLM', 'endpoint_ids' => [$endpoint->id]])
            ->assertRedirect("/suites/{$suite->id}/generation-jobs/create")
            ->assertSessionHas('error', ResolveLlmAvailabilityAction::DISABLED_MESSAGE);

        $this->assertSame(0, GenerationJob::query()->count());
    }

    public function test_already_queued_llm_job_fails_without_calling_ai_service(): void
    {
        $this->ai->setProvider('disabled');
        [, $suite] = $this->suite();
        $job = GenerationJob::factory()->create([
            'project_id' => $suite->project_id,
            'api_spec_id' => $suite->api_spec_id,
            'test_suite_id' => $suite->id,
            'strategy' => GenerationStrategy::Llm,
            'status' => GenerationJobStatus::Queued,
        ]);

        try {
            (new ExecuteAiGenerationAction($this->ai, new RecordLlmCallsAction))->execute($job);
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(ResolveLlmAvailabilityAction::DISABLED_MESSAGE, $e->getMessage());
        }

        $job->refresh();
        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertStringContainsString('chỉ hỗ trợ chiến lược RULE', (string) $job->error);
        $this->assertSame([], $this->ai->recordedRequests());
    }

    public function test_unreachable_ai_service_is_not_reported_as_disabled(): void
    {
        $this->ai->setReachable(false);

        $this->assertNull(app(ResolveLlmAvailabilityAction::class)->execute());
    }

    public function test_llm_experiment_is_refused_when_llm_is_disabled(): void
    {
        Queue::fake();
        $this->ai->setProvider('disabled');
        $tester = User::factory()->tester()->create();

        $benchmark = $this->benchmark();

        $this->actingAs($tester)->postJson('/api/v1/experiments', $this->experimentPayload($benchmark, 'LLM'))
            ->assertStatus(422)
            ->assertJsonPath('error.message', ResolveLlmAvailabilityAction::DISABLED_MESSAGE);
        $this->actingAs($tester)->postJson('/api/v1/experiments', $this->experimentPayload($benchmark, 'RULE'))
            ->assertStatus(202);
    }

    // --- EXPERIMENTS_ENABLED=false ----------------------------------------------------------------

    public function test_experiment_creation_is_refused_but_reading_still_works(): void
    {
        Queue::fake();
        config(['ait.experiments.enabled' => false]);
        $tester = User::factory()->tester()->create();
        $benchmark = $this->benchmark();
        $existing = Experiment::factory()->create(['benchmark_id' => $benchmark->id]);

        $this->actingAs($tester)->postJson('/api/v1/experiments', $this->experimentPayload($benchmark, 'RULE'))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.message', CreateExperimentAction::DISABLED_MESSAGE)
            ->assertJsonPath('error.details.experiments.0', CreateExperimentAction::DISABLED_MESSAGE);

        $this->assertSame(1, Experiment::query()->count());
        Queue::assertNotPushed(RunExperimentJob::class);

        $this->actingAs($tester)->getJson('/api/v1/experiments')->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAs($tester)->getJson("/api/v1/experiments/{$existing->id}")->assertOk();
    }

    public function test_m15_shows_the_reason_and_hides_the_create_form(): void
    {
        config(['ait.experiments.enabled' => false]);
        $tester = User::factory()->tester()->create();
        $this->benchmark();

        $this->actingAs($tester)->get('/experiments')
            ->assertOk()
            ->assertSee('data-testid="experiments-disabled"', false)
            ->assertSee(CreateExperimentAction::DISABLED_MESSAGE)
            ->assertDontSee('btn-create-experiment', false)
            ->assertDontSee('CẤU HÌNH THỰC NGHIỆM')
            // Empty state: no instruction to click a button that is not there.
            ->assertSee('data-testid="experiments-empty-disabled"', false)
            ->assertSee('không khả dụng trên bản triển khai này')
            ->assertDontSee('id="btn-create-experiment"', false);
    }

    public function test_dashboard_hides_the_run_experiment_quick_action_when_experiments_are_disabled(): void
    {
        config(['ait.experiments.enabled' => false]);
        $tester = User::factory()->tester()->create();

        $this->actingAs($tester)->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Chạy thực nghiệm')
            ->assertSee('data-testid="experiments-quick-action-disabled"', false)
            ->assertSee('Thực nghiệm không khả dụng trên bản triển khai này');

        config(['ait.experiments.enabled' => true]);
        $this->actingAs($tester)->get('/dashboard')
            ->assertOk()
            ->assertSee('Chạy thực nghiệm')
            ->assertDontSee('data-testid="experiments-quick-action-disabled"', false);
    }

    public function test_m15_offers_creation_when_experiments_are_enabled(): void
    {
        $tester = User::factory()->tester()->create();
        $this->benchmark();

        $this->actingAs($tester)->get('/experiments')
            ->assertOk()
            ->assertSee('btn-create-experiment', false)
            ->assertSee('Bấm “Tạo thực nghiệm”')
            ->assertDontSee('data-testid="experiments-disabled"', false);
    }

    /**
     * @return array{0: User, 1: TestSuite, 2: Endpoint}
     */
    private function suite(): array
    {
        $tester = User::factory()->tester()->create();
        $project = Project::factory()->for($tester, 'owner')->create();
        $spec = ApiSpec::factory()->for($project)->create(['status' => ApiSpecStatus::Parsed]);
        $suite = TestSuite::factory()->create([
            'project_id' => $project->id,
            'api_spec_id' => $spec->id,
            'created_by' => $tester->id,
        ]);
        $endpoint = Endpoint::factory()->create([
            'api_spec_id' => $spec->id,
            'method' => HttpMethod::Get,
            'path' => '/api/items',
            'responses' => ['200' => ['description' => 'OK']],
        ]);

        return [$tester, $suite, $endpoint];
    }

    private function benchmark(): Benchmark
    {
        $benchmark = Benchmark::factory()->create(['slug' => 'simple-store']);
        BenchmarkBug::factory()->count(2)->create(['benchmark_id' => $benchmark->id]);

        return $benchmark;
    }

    /**
     * @return array<string, mixed>
     */
    private function experimentPayload(Benchmark $benchmark, string $strategy): array
    {
        return [
            'name' => 'Thực nghiệm '.$strategy,
            'benchmark_id' => $benchmark->id,
            'strategy' => $strategy,
            'config' => ['model' => null, 'seed' => 42, 'temperature' => 0.2, 'repetitions' => 3],
        ];
    }
}
