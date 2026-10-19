<?php

namespace Tests\Feature\Experiments;

use App\Actions\Experiments\RunExperimentAction;
use App\Actions\Generation\ProcessGeneratedTestCasesAction;
use App\Actions\Runs\ExecuteTestRunAction;
use App\Actions\Runs\TransitionTestRunStatusAction;
use App\Contracts\AiGeneratorClient;
use App\Contracts\BenchmarkSandbox;
use App\Contracts\TargetHttpClient;
use App\Data\Ai\AiGenerateRequest;
use App\Data\Ai\AiGenerateResponse;
use App\Data\AiServiceStatus;
use App\Data\Experiments\AuthFixture;
use App\Data\Experiments\ExperimentStep;
use App\Data\TargetRequest;
use App\Data\TargetResponse;
use App\Enums\BenchmarkBugCategory;
use App\Enums\ErrorCode;
use App\Enums\ExperimentStatus;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\HttpMethod;
use App\Enums\TestCaseSource;
use App\Enums\TestCaseStatus;
use App\Enums\TestRunStatus;
use App\Exceptions\ApiException;
use App\Http\Resources\GenerationJobResource;
use App\Jobs\RunExperimentJob;
use App\Jobs\RunExperimentStepJob;
use App\Models\Benchmark;
use App\Models\BenchmarkBug;
use App\Models\Experiment;
use App\Models\ExperimentBugDetection;
use App\Models\GenerationJob;
use App\Models\TestCase as TestCaseModel;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\Ai\FakeAiGeneratorClient;
use App\Services\Benchmark\FakeBenchmarkSandbox;
use App\Services\Benchmark\GroundTruthLoader;
use App\Services\Execution\AssertionEngine;
use App\Services\Execution\CredentialInjector;
use App\Services\Execution\TestExecutor;
use App\Services\Target\TargetAllowlistService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery\MockInterface;
use Tests\TestCase;

class RunExperimentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Target double whose response depends on the bug variant currently enabled in the sandbox.
     *
     * @param  Closure(?string, TargetRequest): TargetResponse  $respond
     */
    private function bindTarget(Closure $respond): void
    {
        $this->app->instance(TargetHttpClient::class, new class($respond) implements TargetHttpClient
        {
            /** @param Closure(?string, TargetRequest): TargetResponse $respond */
            public function __construct(private Closure $respond) {}

            public function send(TargetRequest $request): TargetResponse
            {
                /** @var FakeBenchmarkSandbox $sandbox */
                $sandbox = app(BenchmarkSandbox::class);

                return ($this->respond)($sandbox->activeBugCode, $request);
            }
        });
    }

    private function response(int $status, string $body = '{}'): TargetResponse
    {
        return new TargetResponse($status, ['Content-Type' => ['application/json']], $body, 1);
    }

    /**
     * simple-store benchmark (real openapi.yaml) with two seeded bugs.
     */
    private function benchmark(): Benchmark
    {
        $benchmark = Benchmark::factory()->create([
            'slug' => 'simple-store',
            'name' => 'Simple Store',
            'base_url' => 'http://benchmark:8002',
        ]);
        BenchmarkBug::factory()->create([
            'benchmark_id' => $benchmark->id,
            'code' => 'BUG-001',
            'method' => HttpMethod::Post,
            'path' => '/products',
            'category' => BenchmarkBugCategory::ValidationMissing,
            'toggle_flag' => 'BUG_001',
        ]);
        BenchmarkBug::factory()->create([
            'benchmark_id' => $benchmark->id,
            'code' => 'BUG-002',
            'method' => HttpMethod::Post,
            'path' => '/products',
            'category' => BenchmarkBugCategory::BoundaryError,
            'toggle_flag' => 'BUG_002',
        ]);

        return $benchmark;
    }

    public function test_llm_experiment_runs_every_variant_and_stores_metrics_and_detections(): void
    {
        // Clean variant answers 200; with BUG-001 the target answers 500; BUG-002 behaves like clean.
        $this->bindTarget(fn (?string $bug): TargetResponse => $this->response($bug === 'BUG-001' ? 500 : 200));

        $user = User::factory()->tester()->create();
        $benchmark = $this->benchmark();
        $experiment = Experiment::factory()->create([
            'name' => 'So sánh đợt 1',
            'benchmark_id' => $benchmark->id,
            'strategy' => GenerationStrategy::Llm,
            'config' => ['model' => null, 'seed' => 42, 'temperature' => 0.2, 'repetitions' => 3],
        ]);

        (new RunExperimentJob($experiment->id, $user->id))->handle(app(RunExperimentAction::class));

        $experiment->refresh();
        $this->assertSame(ExperimentStatus::Completed, $experiment->status);
        $this->assertNotNull($experiment->started_at);
        $this->assertNotNull($experiment->finished_at);

        $repetitions = $experiment->repetitions_data ?? [];
        $this->assertCount(3, $repetitions);
        // Regression (S11-02): protocol seeds 42, 1024, 2026 (experiment-design.md §4.2), not seed + r - 1.
        $this->assertSame([42, 1024, 2026], array_column($repetitions, 'seed'));
        $this->assertSame(['BUG-001', 'BUG-002'], array_keys($repetitions[0]['bug_runs']));

        $cleanRun = TestRun::query()->findOrFail((int) $repetitions[0]['clean_test_run_id']);
        $this->assertSame('clean', $cleanRun->benchmark_variant);
        $this->assertSame(TestRunStatus::Completed, $cleanRun->status);
        $this->assertSame(9, TestRun::query()->count()); // 3 × (clean + 2 bugs)

        $metrics = $experiment->metrics ?? [];
        $this->assertEquals(1.0, $metrics['validity_rate']);
        $this->assertEquals(1.0, $metrics['executable_rate']);
        $this->assertEquals(0.0, $metrics['false_positive_rate']);
        $this->assertEquals(0.5, $metrics['bug_detection_rate']);
        $this->assertEquals(0.5, $metrics['mutation_score']);
        $this->assertEquals(0.0, $metrics['stddev']['bug_detection_rate']);
        $this->assertEquals(0.0, $metrics['redundancy']);
        $this->assertEquals(100, $metrics['prompt_tokens']);
        $this->assertEquals(80, $metrics['completion_tokens']);
        $this->assertGreaterThan(0, $metrics['endpoint_coverage']);
        $this->assertEquals(2, $metrics['bugs_total']);
        $this->assertCount(3, $metrics['repetitions']);

        $detections = ExperimentBugDetection::query()->where('experiment_id', $experiment->id)->get();
        $this->assertCount(6, $detections);
        $bug1 = BenchmarkBug::query()->where('code', 'BUG-001')->firstOrFail();
        foreach ($detections as $detection) {
            $this->assertSame($detection->benchmark_bug_id === $bug1->id, $detection->detected);
            $this->assertNotSame($detection->clean_test_run_id, $detection->bug_test_run_id);
        }
        $this->assertNotEmpty($detections->firstWhere('benchmark_bug_id', $bug1->id)?->detecting_test_case_ids);

        // The generated suites live in a project owned by the user who started the experiment.
        $this->assertDatabaseHas('projects', ['owner_id' => $user->id, 'name' => 'Thực nghiệm: Simple Store (v1.0)']);
    }

    /**
     * Regression: LLM/HYBRID experiments must not fall back to the HAPPY_PATH-only default
     * of AiGenerateRequest; an explicit empty techniques list lets ai-service pick the
     * per-strategy defaults, so the RULE/LLM/HYBRID comparison uses equal setups.
     */
    public function test_llm_and_hybrid_experiments_send_strategy_default_techniques_and_reproducibility_options(): void
    {
        $this->bindTarget(fn (): TargetResponse => $this->response(200));
        $ai = new FakeAiGeneratorClient;
        $this->app->instance(AiGeneratorClient::class, $ai);
        $user = User::factory()->tester()->create();
        $benchmark = $this->benchmark();

        foreach ([GenerationStrategy::Llm, GenerationStrategy::Hybrid] as $strategy) {
            $before = count($ai->recordedRequests());
            $experiment = Experiment::factory()->create([
                'name' => 'Kỹ thuật '.$strategy->value,
                'benchmark_id' => $benchmark->id,
                'strategy' => $strategy,
                'config' => ['model' => 'qwen2.5-coder:7b', 'seed' => 7, 'temperature' => 0.5, 'repetitions' => 1],
            ]);

            app(RunExperimentAction::class)->execute($experiment->id, $user->id);

            $this->assertSame(ExperimentStatus::Completed, $experiment->refresh()->status, $strategy->value);
            $requests = array_slice($ai->recordedRequests(), $before);
            $this->assertNotEmpty($requests, $strategy->value);
            foreach ($requests as $request) {
                $payload = $request->toArray();
                $this->assertSame($strategy->value, $payload['strategy']);
                $this->assertSame([], $payload['options']['techniques'], $strategy->value);
                $this->assertSame(7, $payload['options']['seed']);
                $this->assertSame('qwen2.5-coder:7b', $payload['options']['model']);
                $this->assertEquals(0.5, $payload['options']['temperature']);
                if ($strategy === GenerationStrategy::Hybrid) {
                    $this->assertNotEmpty($payload['seed_cases']);
                } else {
                    $this->assertSame([], $payload['seed_cases']);
                }
            }
        }
    }

    public function test_hybrid_experiment_completes_with_metrics(): void
    {
        $this->bindTarget(fn (?string $bug): TargetResponse => $this->response($bug === 'BUG-001' ? 500 : 200));
        $user = User::factory()->tester()->create();
        $experiment = Experiment::factory()->create([
            'benchmark_id' => $this->benchmark()->id,
            'strategy' => GenerationStrategy::Hybrid,
            'config' => ['model' => null, 'seed' => 42, 'temperature' => 0.2, 'repetitions' => 2],
        ]);

        app(RunExperimentAction::class)->execute($experiment->id, $user->id);

        $experiment->refresh();
        $this->assertSame(ExperimentStatus::Completed, $experiment->status);
        $this->assertCount(2, $experiment->repetitions_data ?? []);
        $metrics = $experiment->metrics ?? [];
        $this->assertEquals(0.5, $metrics['bug_detection_rate']);
        $this->assertGreaterThan(0, $metrics['prompt_tokens']);
    }

    /**
     * Regression (S10-01 audit round 3): execution_time_ms subtracted test_runs.started_at from
     * finished_at, which are timestamp(0), so every sub-second run counted as 0 or 1000 ms. It is
     * now the sum of the measured per-case durations (test_results.duration_ms) of the clean run and
     * every bug run of the repetition.
     */
    public function test_execution_time_is_the_sum_of_measured_case_durations_not_second_timestamps(): void
    {
        // Each request takes ~2 ms (measured by the executor with hrtime), so a whole run stays far
        // below one second and its second-precision timestamps usually do not differ.
        $this->bindTarget(function (?string $bug): TargetResponse {
            usleep(2000);

            return $this->response($bug === 'BUG-001' ? 500 : 200);
        });
        $user = User::factory()->tester()->create();
        $experiment = Experiment::factory()->create([
            'benchmark_id' => $this->benchmark()->id,
            'strategy' => GenerationStrategy::Hybrid,
            'config' => ['model' => null, 'seed' => 42, 'temperature' => 0.2, 'repetitions' => 2],
        ]);

        app(RunExperimentAction::class)->execute($experiment->id, $user->id);

        $experiment->refresh();
        $this->assertSame(ExperimentStatus::Completed, $experiment->status);
        $perRepetition = array_column($experiment->metrics['repetitions'] ?? [], 'execution_time_ms', 'repetition');
        foreach ($experiment->repetitions_data ?? [] as $entry) {
            $runIds = [(int) $entry['clean_test_run_id'], ...array_map('intval', array_values($entry['bug_runs']))];
            $expected = (int) TestResult::query()->whereIn('test_run_id', $runIds)->sum('duration_ms');
            $this->assertGreaterThan(0, $expected);
            $this->assertSame($expected, $perRepetition[$entry['repetition']] ?? null);
        }
    }

    /**
     * Regression (S10-01): the HYBRID experiment suite used to hold only the LLM add-on cases, the
     * RULE seeds were sent to the LLM but never persisted or run. HYBRID = RULE ∪ LLM (§10.2).
     */
    public function test_hybrid_experiment_suite_runs_rule_seed_cases_and_llm_additions(): void
    {
        $this->bindTarget(fn (): TargetResponse => $this->response(200));
        $ai = new FakeAiGeneratorClient;
        $this->app->instance(AiGeneratorClient::class, $ai);
        $user = User::factory()->tester()->create();
        $experiment = Experiment::factory()->create([
            'benchmark_id' => $this->benchmark()->id,
            'strategy' => GenerationStrategy::Hybrid,
            'config' => ['model' => null, 'seed' => 42, 'temperature' => 0.2, 'repetitions' => 1],
        ]);

        app(RunExperimentAction::class)->execute($experiment->id, $user->id);

        $experiment->refresh();
        $this->assertSame(ExperimentStatus::Completed, $experiment->status);
        $repetition = ($experiment->repetitions_data ?? [])[0];
        $suiteCases = TestCaseModel::query()->where('test_suite_id', $repetition['test_suite_id'])->get();
        $ruleCases = $suiteCases->where('source', TestCaseSource::Rule)->whereIn('status', [TestCaseStatus::Valid]);
        $this->assertNotEmpty($ruleCases, 'RULE seed cases must be persisted in the HYBRID suite');
        $this->assertNotEmpty($suiteCases->where('source', TestCaseSource::Hybrid));

        // The seeds sent to the LLM are the persisted RULE cases.
        $requests = $ai->recordedRequests();
        $this->assertNotEmpty($requests);
        $this->assertNotEmpty($requests[0]->toArray()['seed_cases']);

        // The clean run executes the RULE cases too, not only the LLM additions.
        $executed = TestResult::query()->where('test_run_id', $repetition['clean_test_run_id'])->pluck('test_case_id')->all();
        foreach ($ruleCases as $ruleCase) {
            $this->assertContains($ruleCase->id, $executed);
        }

        // Generated counts both halves of the suite.
        $metrics = $experiment->metrics ?? [];
        $this->assertSame($suiteCases->count(), $metrics['repetitions'][0]['generated'] ?? null);
    }

    /**
     * Regression (S10-01): an unreachable bug sandbox made every case ERROR, which D14 counted as
     * a detection of that bug.
     */
    public function test_unreachable_bug_sandbox_fails_the_experiment_instead_of_detecting(): void
    {
        $this->bindTarget(fn (): TargetResponse => $this->response(200));
        /** @var FakeBenchmarkSandbox $sandbox */
        $sandbox = app(BenchmarkSandbox::class);
        $sandbox->unreachable = 'BUG-002';
        $user = User::factory()->tester()->create();
        $experiment = Experiment::factory()->create([
            'benchmark_id' => $this->benchmark()->id,
            'strategy' => GenerationStrategy::Rule,
            'config' => ['model' => null, 'seed' => 42, 'temperature' => 0.2, 'repetitions' => 1],
        ]);

        app(RunExperimentAction::class)->execute($experiment->id, $user->id);

        $experiment->refresh();
        $this->assertSame(ExperimentStatus::Failed, $experiment->status);
        $this->assertStringContainsString('BUG-002', (string) ($experiment->metrics['error'] ?? ''));
        $this->assertFalse(ExperimentBugDetection::query()->where('detected', true)->exists());
    }

    /**
     * Regression (S10-01 r2): D14 (LOCKED) counts FAIL or ERROR on the bug variant. A completed bug
     * run where every case is ERROR (e.g. the seeded bug hangs past the timeout) detects the bug,
     * and Bug Detection Rate and Mutation Score agree.
     */
    public function test_all_error_bug_run_counts_as_detected_per_d14(): void
    {
        $this->bindTarget(function (?string $bug): TargetResponse {
            if ($bug === 'BUG-002') {
                throw new \RuntimeException('cURL error 28: Operation timed out');
            }

            return $this->response($bug === 'BUG-001' ? 500 : 200);
        });
        $user = User::factory()->tester()->create();
        $experiment = Experiment::factory()->create([
            'benchmark_id' => $this->benchmark()->id,
            'strategy' => GenerationStrategy::Llm,
            'config' => ['model' => null, 'seed' => 42, 'temperature' => 0.2, 'repetitions' => 1],
        ]);

        app(RunExperimentAction::class)->execute($experiment->id, $user->id);

        $experiment->refresh();
        $this->assertSame(ExperimentStatus::Completed, $experiment->status);
        $bug2 = BenchmarkBug::query()->where('code', 'BUG-002')->firstOrFail();
        $detection = ExperimentBugDetection::query()->where('benchmark_bug_id', $bug2->id)->firstOrFail();
        $this->assertTrue($detection->detected);
        $this->assertNotSame([], $detection->detecting_test_case_ids);
        $metrics = $experiment->metrics ?? [];
        $this->assertEquals(1.0, $metrics['bug_detection_rate']);
        $this->assertEquals(1.0, $metrics['mutation_score']);
    }

    /**
     * Regression (S10-01 r2): a cancelled bug run was folded into "not detected" for Bug Detection
     * Rate while Mutation Score ignored it, so both metrics disagreed silently.
     */
    public function test_experiment_fails_when_a_bug_run_does_not_complete(): void
    {
        $this->bindTarget(function (?string $bug): TargetResponse {
            if ($bug === 'BUG-002') {
                TestRun::query()->where('benchmark_variant', 'BUG-002')->update(['status' => TestRunStatus::Cancelled->value]);
            }

            return $this->response($bug === 'BUG-001' ? 500 : 200);
        });
        $user = User::factory()->tester()->create();
        $experiment = Experiment::factory()->create([
            'benchmark_id' => $this->benchmark()->id,
            'strategy' => GenerationStrategy::Llm,
            'config' => ['model' => null, 'seed' => 42, 'temperature' => 0.2, 'repetitions' => 1],
        ]);

        app(RunExperimentAction::class)->execute($experiment->id, $user->id);

        $experiment->refresh();
        $this->assertSame(ExperimentStatus::Failed, $experiment->status);
        $error = (string) ($experiment->metrics['error'] ?? '');
        $this->assertStringContainsString('BUG-002', $error);
        $this->assertStringContainsString('CANCELLED', $error);
        $bug2 = BenchmarkBug::query()->where('code', 'BUG-002')->firstOrFail();
        $this->assertFalse(ExperimentBugDetection::query()->where('benchmark_bug_id', $bug2->id)->exists());
    }

    public function test_rule_experiment_detects_bug_through_negative_cases(): void
    {
        // Clean variant rejects everything with 422; with BUG-001 everything is accepted (201).
        $this->bindTarget(fn (?string $bug): TargetResponse => $bug === 'BUG-001'
            ? $this->response(201, '{"id": 1}')
            : $this->response(422, '{"detail": "invalid"}'));

        $user = User::factory()->tester()->create();
        $experiment = Experiment::factory()->create([
            'benchmark_id' => $this->benchmark()->id,
            'strategy' => GenerationStrategy::Rule,
            'config' => ['model' => null, 'seed' => 42, 'temperature' => 0.2, 'repetitions' => 3],
        ]);

        app(RunExperimentAction::class)->execute($experiment->id, $user->id);

        $experiment->refresh();
        $this->assertSame(ExperimentStatus::Completed, $experiment->status);
        $metrics = $experiment->metrics ?? [];
        $this->assertEquals(1.0, $metrics['endpoint_coverage']);
        $this->assertGreaterThan(0, $metrics['validity_rate']);
        $this->assertGreaterThan(0, $metrics['false_positive_rate']); // happy paths fail on a 422-only target
        $this->assertEquals(0.5, $metrics['bug_detection_rate']);
        $this->assertEquals(0, $metrics['prompt_tokens']);
        $this->assertGreaterThan(0, $metrics['status_code_coverage']);
    }

    public function test_experiment_fails_when_target_is_outside_the_allowlist(): void
    {
        $this->bindTarget(fn (): TargetResponse => $this->response(200));
        $user = User::factory()->tester()->create();
        $benchmark = $this->benchmark();
        $benchmark->update(['base_url' => 'http://evil.example.com']);
        $experiment = Experiment::factory()->create(['benchmark_id' => $benchmark->id, 'strategy' => GenerationStrategy::Llm]);

        app(RunExperimentAction::class)->execute($experiment->id, $user->id);

        $experiment->refresh();
        $this->assertSame(ExperimentStatus::Failed, $experiment->status);
        $this->assertNotEmpty($experiment->metrics['error'] ?? null);
        $this->assertSame(0, ExperimentBugDetection::query()->count());
    }

    public function test_experiment_without_openapi_file_fails(): void
    {
        $user = User::factory()->tester()->create();
        $benchmark = Benchmark::factory()->create(['slug' => 'does-not-exist']);
        BenchmarkBug::factory()->create(['benchmark_id' => $benchmark->id]);
        $experiment = Experiment::factory()->create(['benchmark_id' => $benchmark->id]);

        app(RunExperimentAction::class)->execute($experiment->id, $user->id);

        $experiment->refresh();
        $this->assertSame(ExperimentStatus::Failed, $experiment->status);
        $this->assertStringContainsString('openapi.yaml', (string) ($experiment->metrics['error'] ?? ''));
    }

    public function test_only_planned_experiments_are_executed(): void
    {
        $user = User::factory()->tester()->create();
        $experiment = Experiment::factory()->create(['status' => ExperimentStatus::Completed, 'metrics' => ['validity_rate' => 1.0]]);

        app(RunExperimentAction::class)->execute($experiment->id, $user->id);

        $this->assertSame(ExperimentStatus::Completed, $experiment->refresh()->status);
        $this->assertSame(0, TestRun::query()->count());
    }

    public function test_sandbox_is_reset_before_every_clean_and_bug_variant_run(): void
    {
        $this->bindTarget(fn (): TargetResponse => $this->response(200));
        $user = User::factory()->tester()->create();
        $experiment = Experiment::factory()->create([
            'benchmark_id' => $this->benchmark()->id,
            'strategy' => GenerationStrategy::Rule,
            'config' => ['model' => null, 'seed' => 42, 'seeds' => [7, 8], 'temperature' => 0.2, 'repetitions' => 2],
        ]);

        app(RunExperimentAction::class)->execute($experiment->id, $user->id);

        $this->assertSame(ExperimentStatus::Completed, $experiment->refresh()->status);
        /** @var FakeBenchmarkSandbox $sandbox */
        $sandbox = app(BenchmarkSandbox::class);
        $this->assertSame(
            [null, 'BUG-001', 'BUG-002', null, 'BUG-001', 'BUG-002'],
            array_column($sandbox->resets, 'bug'),
        );
        $this->assertSame(['http://benchmark:8002'], array_values(array_unique(array_column($sandbox->resets, 'url'))));
        $this->assertSame([7, 8], array_column($experiment->repetitions_data ?? [], 'seed'));
    }

    public function test_failed_sandbox_reset_fails_the_experiment_before_running_tests(): void
    {
        $this->bindTarget(fn (): TargetResponse => $this->response(200));
        /** @var FakeBenchmarkSandbox $sandbox */
        $sandbox = app(BenchmarkSandbox::class);
        $sandbox->failResetWith = 'Reset sandbox thất bại (HTTP 500).';
        $user = User::factory()->tester()->create();
        $experiment = Experiment::factory()->create([
            'benchmark_id' => $this->benchmark()->id,
            'strategy' => GenerationStrategy::Rule,
            'config' => ['model' => null, 'seed' => 42, 'temperature' => 0.2, 'repetitions' => 3],
        ]);

        app(RunExperimentAction::class)->execute($experiment->id, $user->id);

        $experiment->refresh();
        $this->assertSame(ExperimentStatus::Failed, $experiment->status);
        $this->assertSame('Reset sandbox thất bại (HTTP 500).', $experiment->metrics['error'] ?? null);
        $this->assertSame(0, TestRun::query()->count());
    }

    public function test_show_returns_bug_runs_as_object_while_running_after_generate_step_only(): void
    {
        $this->bindTarget(fn (): TargetResponse => $this->response(200));
        $user = User::factory()->tester()->create();
        $experiment = Experiment::factory()->create([
            'benchmark_id' => $this->benchmark()->id,
            'strategy' => GenerationStrategy::Rule,
            'config' => ['model' => null, 'seed' => 42, 'temperature' => 0.2, 'repetitions' => 1],
        ]);

        $action = app(RunExperimentAction::class);
        $this->assertNotNull($action->start($experiment->id, $user->id));
        $this->assertTrue($action->runStep($experiment->id, $user->id, ExperimentStep::generate(1)));
        $this->assertSame(ExperimentStatus::Running, $experiment->refresh()->status);

        $content = (string) $this->actingAs($user)->getJson("/api/v1/experiments/{$experiment->id}")
            ->assertOk()->getContent();
        $payload = json_decode($content, false, 512, JSON_THROW_ON_ERROR);

        $this->assertCount(1, $payload->data->repetitions_data);
        $this->assertSame('{}', json_encode($payload->data->repetitions_data[0]->bug_runs));
    }

    public function test_show_returns_bug_runs_as_object_after_failed_clean_reset(): void
    {
        $this->bindTarget(fn (): TargetResponse => $this->response(200));
        /** @var FakeBenchmarkSandbox $sandbox */
        $sandbox = app(BenchmarkSandbox::class);
        $sandbox->failResetWith = 'Reset sandbox thất bại (HTTP 500).';
        $user = User::factory()->tester()->create();
        $experiment = Experiment::factory()->create([
            'benchmark_id' => $this->benchmark()->id,
            'strategy' => GenerationStrategy::Rule,
            'config' => ['model' => null, 'seed' => 42, 'temperature' => 0.2, 'repetitions' => 1],
        ]);

        app(RunExperimentAction::class)->execute($experiment->id, $user->id);
        $this->assertSame(ExperimentStatus::Failed, $experiment->refresh()->status);

        $content = (string) $this->actingAs($user)->getJson("/api/v1/experiments/{$experiment->id}")
            ->assertOk()->getContent();
        $payload = json_decode($content, false, 512, JSON_THROW_ON_ERROR);

        $this->assertNotEmpty($payload->data->repetitions_data);
        foreach ($payload->data->repetitions_data as $entry) {
            $this->assertSame('{}', json_encode($entry->bug_runs));
        }
    }

    public function test_job_plans_one_chained_step_job_per_generation_variant_and_finalisation(): void
    {
        Bus::fake();
        $user = User::factory()->tester()->create();
        $benchmark = $this->benchmark();
        $experiment = Experiment::factory()->create([
            'benchmark_id' => $benchmark->id,
            'strategy' => GenerationStrategy::Rule,
            'config' => ['model' => null, 'seed' => 42, 'temperature' => 0.2, 'repetitions' => 3],
        ]);

        (new RunExperimentJob($experiment->id, $user->id))->handle(app(RunExperimentAction::class));

        $this->assertSame(ExperimentStatus::Running, $experiment->refresh()->status);
        $bugIds = BenchmarkBug::query()->orderBy('code')->pluck('id')->all();
        $expected = [];
        foreach ([1, 2, 3] as $r) {
            $expected[] = ExperimentStep::generate($r);
            $expected[] = ExperimentStep::variant($r, null);
            foreach ($bugIds as $bugId) {
                $expected[] = ExperimentStep::variant($r, (int) $bugId);
            }
        }
        $expected[] = ExperimentStep::finalize();

        Bus::assertChained(array_map(
            fn (ExperimentStep $step): Closure => fn (RunExperimentStepJob $job): bool => $job->experimentId === $experiment->id
                && $job->userId === $user->id
                && $job->step == $step,
            $expected,
        ));
        // S14-06: every chained step stays on the llm queue.
        Bus::assertDispatched(RunExperimentStepJob::class, fn (RunExperimentStepJob $job): bool => $job->queue === 'llm' && $job->chainQueue === 'llm');
    }

    public function test_step_jobs_complete_the_experiment_and_skip_after_failure(): void
    {
        $this->bindTarget(fn (?string $bug): TargetResponse => $this->response($bug === 'BUG-001' ? 500 : 200));
        $user = User::factory()->tester()->create();
        $experiment = Experiment::factory()->create([
            'benchmark_id' => $this->benchmark()->id,
            'strategy' => GenerationStrategy::Llm,
            'config' => ['model' => null, 'seed' => 42, 'seeds' => [42, 1024, 2026], 'temperature' => 0.2, 'repetitions' => 3],
        ]);
        $action = app(RunExperimentAction::class);

        $steps = $action->start($experiment->id, $user->id);
        $this->assertNotNull($steps);
        $this->assertCount(13, $steps); // 3 × (generate + clean + 2 bugs) + finalize
        foreach ($steps as $step) {
            (new RunExperimentStepJob($experiment->id, $user->id, $step))->handle($action);
        }

        $experiment->refresh();
        $this->assertSame(ExperimentStatus::Completed, $experiment->status);
        $this->assertEquals(0.5, $experiment->metrics['bug_detection_rate'] ?? null);
        $this->assertSame(9, TestRun::query()->count());

        // A step of an experiment that is no longer RUNNING does nothing.
        $this->assertFalse($action->runStep($experiment->id, $user->id, ExperimentStep::generate(1)));
        $this->assertSame(9, TestRun::query()->count());
    }

    public function test_variant_step_without_generated_repetition_fails_the_experiment(): void
    {
        $user = User::factory()->tester()->create();
        $experiment = Experiment::factory()->create([
            'benchmark_id' => $this->benchmark()->id,
            'status' => ExperimentStatus::Running,
            'started_at' => now(),
        ]);

        $this->assertFalse(app(RunExperimentAction::class)->runStep($experiment->id, $user->id, ExperimentStep::variant(1, null)));

        $experiment->refresh();
        $this->assertSame(ExperimentStatus::Failed, $experiment->status);
        $this->assertStringContainsString('Lần lặp 1', (string) ($experiment->metrics['error'] ?? ''));
    }

    public function test_step_job_failure_marks_experiment_failed(): void
    {
        config(['app.debug' => true]);
        $experiment = Experiment::factory()->create(['status' => ExperimentStatus::Running, 'started_at' => now()]);

        (new RunExperimentStepJob($experiment->id, 1, ExperimentStep::finalize()))->failed(new \RuntimeException('step timeout'));

        $experiment->refresh();
        $this->assertSame(ExperimentStatus::Failed, $experiment->status);
        // Raw detail only with APP_DEBUG=true (SafeErrorMessage).
        $this->assertStringContainsString('step timeout', (string) ($experiment->metrics['error'] ?? ''));
    }

    /**
     * Regression (S10-01): seeds were config.seed + r - 1 (42, 43, 44) instead of the protocol's
     * (ExperimentSeedPlanner continues with last + 1 beyond the protocol list).
     */
    public function test_default_seed_follows_the_experiment_protocol(): void
    {
        $this->bindTarget(fn (): TargetResponse => $this->response(200));
        $user = User::factory()->tester()->create();
        $experiment = Experiment::factory()->create([
            'benchmark_id' => $this->benchmark()->id,
            'strategy' => GenerationStrategy::Rule,
            'config' => ['model' => null, 'seed' => 42, 'temperature' => 0.2, 'repetitions' => 4],
        ]);

        app(RunExperimentAction::class)->execute($experiment->id, $user->id);

        $this->assertSame([42, 1024, 2026, 2027], array_column($experiment->refresh()->repetitions_data ?? [], 'seed'));
    }

    /**
     * Regression (S10-01): the scored HYBRID suite held only the LLM additions, not the RULE seed.
     */
    public function test_hybrid_suite_contains_the_rule_seed_cases_and_the_llm_additions(): void
    {
        $this->bindTarget(fn (): TargetResponse => $this->response(200));
        $ai = new FakeAiGeneratorClient;
        $this->app->instance(AiGeneratorClient::class, $ai);
        $user = User::factory()->tester()->create();
        $experiment = Experiment::factory()->create([
            'benchmark_id' => $this->benchmark()->id,
            'strategy' => GenerationStrategy::Hybrid,
            'config' => ['model' => null, 'seed' => 42, 'temperature' => 0.2, 'repetitions' => 1],
        ]);

        app(RunExperimentAction::class)->execute($experiment->id, $user->id);

        $experiment->refresh();
        $this->assertSame(ExperimentStatus::Completed, $experiment->status);
        $suiteId = (int) ($experiment->repetitions_data[0]['test_suite_id'] ?? 0);
        $sources = TestCaseModel::query()->where('test_suite_id', $suiteId)->where('status', TestCaseStatus::Valid)
            ->pluck('source')->map(fn (TestCaseSource $s): string => $s->value)->unique()->sort()->values()->all();
        $this->assertSame(['HYBRID', 'RULE'], $sources);

        $hybridJob = GenerationJob::query()->findOrFail((int) ($experiment->repetitions_data[0]['generation_job_id'] ?? 0));
        $this->assertSame(GenerationStrategy::Hybrid, $hybridJob->strategy);
        $request = $ai->lastRequest();
        $this->assertNotNull($request);
        $this->assertNotEmpty($request->seedCases);
    }

    /**
     * Regression (S10-01): experiment generation jobs stored experiment_id/options/seed_cases,
     * violating GenerationParams (additionalProperties: false).
     */
    public function test_experiment_generation_jobs_store_contract_params_only(): void
    {
        $this->bindTarget(fn (): TargetResponse => $this->response(200));
        $user = User::factory()->tester()->create();
        $experiment = Experiment::factory()->create([
            'benchmark_id' => $this->benchmark()->id,
            'strategy' => GenerationStrategy::Hybrid,
            'config' => ['model' => 'qwen2.5-coder:7b', 'seed' => 7, 'temperature' => 0.5, 'repetitions' => 1],
        ]);

        app(RunExperimentAction::class)->execute($experiment->id, $user->id);

        $this->assertSame(ExperimentStatus::Completed, $experiment->refresh()->status);
        foreach (GenerationJob::all() as $job) {
            $this->assertSame([], array_diff(array_keys($job->params), GenerationJobResource::PARAM_KEYS), $job->strategy->value);
        }
        $llmJob = GenerationJob::query()->where('strategy', GenerationStrategy::Hybrid)->firstOrFail();
        $this->assertSame(7, $llmJob->params['seed']);
        $this->assertSame('qwen2.5-coder:7b', $llmJob->params['model']);
    }

    /**
     * Regression (S10-01): bugs removed from ground-truth.yaml were still run and counted.
     */
    public function test_stale_bugs_are_not_run_or_counted(): void
    {
        $this->bindTarget(fn (?string $bug): TargetResponse => $this->response($bug === 'BUG-001' ? 500 : 200));
        $user = User::factory()->tester()->create();
        $benchmark = $this->benchmark();
        BenchmarkBug::factory()->create([
            'benchmark_id' => $benchmark->id,
            'code' => 'BUG-999',
            'method' => HttpMethod::Post,
            'path' => '/products',
            'category' => BenchmarkBugCategory::ValidationMissing,
            'toggle_flag' => 'BUG_999',
        ]);
        $experiment = Experiment::factory()->create([
            'benchmark_id' => $benchmark->id,
            'strategy' => GenerationStrategy::Llm,
            'config' => ['model' => null, 'seed' => 42, 'temperature' => 0.2, 'repetitions' => 1],
        ]);

        app(RunExperimentAction::class)->execute($experiment->id, $user->id);

        $experiment->refresh();
        $this->assertSame(ExperimentStatus::Completed, $experiment->status);
        $this->assertSame(['BUG-001', 'BUG-002'], array_keys($experiment->repetitions_data[0]['bug_runs'] ?? []));
        $this->assertEquals(2, $experiment->metrics['bugs_total'] ?? null);
        $this->assertEquals(0.5, $experiment->metrics['bug_detection_rate'] ?? null);
    }

    /**
     * Regression (S10-01 r2): an unreadable ground-truth.yaml made the experiment fall back to every
     * stored bug row, stale ones included.
     */
    public function test_experiment_fails_when_ground_truth_is_unreadable(): void
    {
        $this->bindTarget(fn (): TargetResponse => $this->response(200));
        $root = $this->benchmarkRootCopy("bugs: [\n  not: valid");
        $this->app->instance(GroundTruthLoader::class, new GroundTruthLoader($root));
        $user = User::factory()->tester()->create();
        $experiment = Experiment::factory()->create([
            'benchmark_id' => $this->benchmark()->id,
            'strategy' => GenerationStrategy::Rule,
            'config' => ['model' => null, 'seed' => 42, 'temperature' => 0.2, 'repetitions' => 1],
        ]);

        app(RunExperimentAction::class)->execute($experiment->id, $user->id);

        $experiment->refresh();
        $this->assertSame(ExperimentStatus::Failed, $experiment->status);
        $this->assertStringContainsString('ground-truth.yaml', (string) ($experiment->metrics['error'] ?? ''));
        $this->assertSame(0, TestRun::query()->count());
    }

    /**
     * Regression (S10-01 r2): finalize() re-read ground-truth.yaml for the Bug Detection Rate
     * denominator, so an edit during the experiment made it differ from the bugs actually run.
     */
    public function test_bug_denominator_is_the_planned_bug_set_even_if_ground_truth_changes(): void
    {
        $this->bindTarget(fn (?string $bug): TargetResponse => $this->response($bug === 'BUG-001' ? 500 : 200));
        $user = User::factory()->tester()->create();
        $experiment = Experiment::factory()->create([
            'benchmark_id' => $this->benchmark()->id,
            'strategy' => GenerationStrategy::Llm,
            'config' => ['model' => null, 'seed' => 42, 'temperature' => 0.2, 'repetitions' => 1],
        ]);

        $steps = app(RunExperimentAction::class)->start($experiment->id, $user->id);
        $this->assertNotNull($steps);

        // ground-truth.yaml now lists only BUG-001 while the planned steps still run BUG-002.
        $root = $this->benchmarkRootCopy(<<<'YAML'
            benchmark: simple-store
            base_url: http://simple-store:8000
            bugs:
              - code: BUG-001
                method: POST
                path: /products
                category: VALIDATION_MISSING
                toggle_env: BUG_001
            YAML);
        $this->app->instance(GroundTruthLoader::class, new GroundTruthLoader($root));
        $this->assertSame(['BUG-001'], array_map(fn ($b) => $b->code, app(GroundTruthLoader::class)->load('simple-store')->bugs));
        $this->app->forgetInstance(RunExperimentAction::class);

        foreach ($steps as $step) {
            $this->assertTrue(app(RunExperimentAction::class)->runStep($experiment->id, $user->id, $step));
        }

        $experiment->refresh();
        $this->assertSame(ExperimentStatus::Completed, $experiment->status);
        $this->assertEquals(2, $experiment->metrics['bugs_total'] ?? null);
        $this->assertEquals(0.5, $experiment->metrics['bug_detection_rate'] ?? null);
        $this->assertEquals(0.5, $experiment->metrics['mutation_score'] ?? null);
    }

    /**
     * Temporary benchmark root holding the real simple-store openapi.yaml and the given ground truth.
     */
    private function benchmarkRootCopy(string $groundTruth): string
    {
        $root = sys_get_temp_dir().'/ait-gt-'.bin2hex(random_bytes(6));
        mkdir($root.'/simple-store', 0777, true);
        copy(base_path('../benchmark/simple-store/openapi.yaml'), $root.'/simple-store/openapi.yaml');
        file_put_contents($root.'/simple-store/ground-truth.yaml', $groundTruth);
        $this->beforeApplicationDestroyed(function () use ($root): void {
            @unlink($root.'/simple-store/openapi.yaml');
            @unlink($root.'/simple-store/ground-truth.yaml');
            @rmdir($root.'/simple-store');
            @rmdir($root);
        });

        return $root;
    }

    /**
     * Regression (S10-01): a cancelled clean run was aggregated as a valid baseline.
     */
    public function test_experiment_fails_when_the_clean_run_does_not_complete(): void
    {
        $this->bindTarget(function (?string $bug): TargetResponse {
            TestRun::query()->where('benchmark_variant', 'clean')->update(['status' => TestRunStatus::Cancelled->value]);

            return $this->response(200);
        });
        $user = User::factory()->tester()->create();
        $experiment = Experiment::factory()->create([
            'benchmark_id' => $this->benchmark()->id,
            'strategy' => GenerationStrategy::Rule,
            'config' => ['model' => null, 'seed' => 42, 'temperature' => 0.2, 'repetitions' => 1],
        ]);

        app(RunExperimentAction::class)->execute($experiment->id, $user->id);

        $experiment->refresh();
        $this->assertSame(ExperimentStatus::Failed, $experiment->status);
        $this->assertStringContainsString('CANCELLED', (string) ($experiment->metrics['error'] ?? ''));
        $this->assertSame(0, ExperimentBugDetection::query()->count());
    }

    /**
     * Regression (S10-01): a non-API error while persisting LLM cases left the job RUNNING forever.
     */
    public function test_llm_processing_error_fails_the_generation_job(): void
    {
        $this->bindTarget(fn (): TargetResponse => $this->response(200));
        $this->mock(ProcessGeneratedTestCasesAction::class, function (MockInterface $mock): void {
            $mock->shouldReceive('execute')->andThrow(new \RuntimeException('database connection lost'));
        });
        $user = User::factory()->tester()->create();
        $experiment = Experiment::factory()->create([
            'benchmark_id' => $this->benchmark()->id,
            'strategy' => GenerationStrategy::Llm,
            'config' => ['model' => null, 'seed' => 42, 'temperature' => 0.2, 'repetitions' => 1],
        ]);

        app(RunExperimentAction::class)->execute($experiment->id, $user->id);

        $this->assertSame(ExperimentStatus::Failed, $experiment->refresh()->status);
        $job = GenerationJob::query()->firstOrFail();
        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertSame('INTERNAL_ERROR', $job->error);
        $this->assertNotNull($job->finished_at);
    }

    public function test_job_failure_marks_experiment_failed(): void
    {
        config(['app.debug' => true]);
        $experiment = Experiment::factory()->create();

        (new RunExperimentJob($experiment->id, 1))->failed(new \RuntimeException('worker timeout'));

        $experiment->refresh();
        $this->assertSame(ExperimentStatus::Failed, $experiment->status);
        // Raw detail only with APP_DEBUG=true (see ErrorMessageSanitizationTest for debug off).
        $this->assertStringContainsString('worker timeout', (string) ($experiment->metrics['error'] ?? ''));
    }

    /**
     * Regression (S10-01 r2): a non-API error inside the inline TestRun left it RUNNING forever.
     */
    public function test_inner_test_run_error_fails_the_run_and_the_experiment(): void
    {
        $this->bindTarget(fn (): TargetResponse => $this->response(200));
        $this->app->bind(ExecuteTestRunAction::class, fn (): ExecuteTestRunAction => new class(app(TestExecutor::class), app(AssertionEngine::class), app(TransitionTestRunStatusAction::class), app(TargetAllowlistService::class), app(CredentialInjector::class)) extends ExecuteTestRunAction
        {
            public function execute(int $testRunId, ?array $testCaseIds = null, ?AuthFixture $auth = null, ?Closure $betweenCases = null, ?Closure $beforeEachCase = null): void
            {
                TestRun::query()->whereKey($testRunId)->update(['status' => TestRunStatus::Running->value, 'started_at' => now()]);

                throw new \RuntimeException('database connection lost');
            }
        });
        $user = User::factory()->tester()->create();
        $experiment = Experiment::factory()->create([
            'benchmark_id' => $this->benchmark()->id,
            'strategy' => GenerationStrategy::Rule,
            'config' => ['model' => null, 'seed' => 42, 'temperature' => 0.2, 'repetitions' => 1],
        ]);

        app(RunExperimentAction::class)->execute($experiment->id, $user->id);

        $this->assertSame(ExperimentStatus::Failed, $experiment->refresh()->status);
        $run = TestRun::query()->firstOrFail();
        $this->assertSame(TestRunStatus::Failed, $run->status);
    }

    /**
     * Regression (S10-01 r2): a step killed by the worker (timeout/OOM) only failed the experiment;
     * its inner GenerationJob and variant TestRun stayed QUEUED/RUNNING forever.
     */
    public function test_step_job_failure_also_fails_in_flight_generation_jobs_and_runs(): void
    {
        $this->bindTarget(fn (): TargetResponse => $this->response(200));
        $user = User::factory()->tester()->create();
        $experiment = Experiment::factory()->create([
            'benchmark_id' => $this->benchmark()->id,
            'strategy' => GenerationStrategy::Rule,
            'config' => ['model' => null, 'seed' => 42, 'temperature' => 0.2, 'repetitions' => 2],
        ]);
        $action = app(RunExperimentAction::class);
        $steps = $action->start($experiment->id, $user->id);
        $this->assertNotNull($steps);
        $this->assertTrue($action->runStep($experiment->id, $user->id, $steps[0])); // generate repetition 1

        $suiteId = (int) ($experiment->refresh()->repetitions_data[0]['test_suite_id'] ?? 0);
        $completedJob = GenerationJob::query()->where('test_suite_id', $suiteId)->firstOrFail();
        // State left behind by a worker killed in the middle of the clean variant step.
        $stuckRun = TestRun::factory()->create([
            'test_suite_id' => $suiteId,
            'benchmark_variant' => 'clean',
            'status' => TestRunStatus::Running,
            'started_at' => now(),
        ]);
        // ...and by a worker killed while generating repetition 2 (no repetitions_data entry yet).
        $suite2 = TestSuite::query()->create([
            'project_id' => $completedJob->project_id,
            'api_spec_id' => $completedJob->api_spec_id,
            'name' => 'rep 2',
            'description' => "Thực nghiệm #{$experiment->id}, lần lặp 2, seed 1.",
            'created_by' => $user->id,
        ]);
        $stuckJob = GenerationJob::factory()->create([
            'project_id' => $completedJob->project_id,
            'api_spec_id' => $completedJob->api_spec_id,
            'test_suite_id' => $suite2->id,
            'strategy' => GenerationStrategy::Rule,
            'status' => GenerationJobStatus::Running,
            'started_at' => now(),
            'created_by' => $user->id,
        ]);

        (new RunExperimentStepJob($experiment->id, $user->id, $steps[1]))->failed(new \RuntimeException('step timeout'));

        $this->assertSame(ExperimentStatus::Failed, $experiment->refresh()->status);
        $this->assertSame(TestRunStatus::Failed, $stuckRun->refresh()->status);
        $this->assertSame(GenerationJobStatus::Failed, $stuckJob->refresh()->status);
        $this->assertNotNull($stuckJob->finished_at);
        $this->assertSame(GenerationJobStatus::Succeeded, $completedJob->refresh()->status);
    }

    /**
     * Regression (S10-01 r2): every generation job FAILED, yet the experiment was COMPLETED with
     * all-zero metrics; M15 now shows FAILED with the job error codes and — for 0/0 ratios.
     */
    public function test_experiment_fails_when_every_repetition_generation_failed(): void
    {
        $this->bindTarget(fn (): TargetResponse => $this->response(200));
        $ai = new FakeAiGeneratorClient;
        $ai->setGenerateException(new ApiException(ErrorCode::LlmProviderError, 'LLM provider error: timed out'));
        $this->app->instance(AiGeneratorClient::class, $ai);
        $user = User::factory()->tester()->create();
        $experiment = Experiment::factory()->create([
            'benchmark_id' => $this->benchmark()->id,
            'strategy' => GenerationStrategy::Llm,
            'config' => ['model' => null, 'seed' => 42, 'seeds' => [42, 1024], 'temperature' => 0.2, 'repetitions' => 2],
        ]);

        app(RunExperimentAction::class)->execute($experiment->id, $user->id);

        $experiment->refresh();
        $this->assertSame(ExperimentStatus::Failed, $experiment->status);
        $error = (string) ($experiment->metrics['error'] ?? '');
        $this->assertStringContainsString('lần 1: LLM_PROVIDER_ERROR', $error);
        $this->assertStringContainsString('lần 2: LLM_PROVIDER_ERROR', $error);
        $this->assertCount(2, $experiment->repetitions_data ?? []);
        $this->assertArrayNotHasKey('validity_rate', $experiment->metrics ?? []);

        $this->actingAs($user)->get("/experiments/{$experiment->id}")
            ->assertOk()
            ->assertSee('Thực nghiệm thất bại')
            ->assertSee('Sinh test thất bại: LLM_PROVIDER_ERROR')
            // Executable / False Positive with a 0 denominator, and the absent aggregates: no 0,0%.
            ->assertDontSee('0,0%<span class="text-slate-500"> ± 0,0%</span>', false);
    }

    public function test_experiment_completes_when_only_some_repetitions_failed_generation(): void
    {
        $this->bindTarget(fn (?string $bug): TargetResponse => $this->response($bug === 'BUG-001' ? 500 : 200));
        // First repetition: provider error; second: the normal fake response.
        $ai = new class implements AiGeneratorClient
        {
            private int $calls = 0;

            private FakeAiGeneratorClient $inner;

            public function __construct()
            {
                $this->inner = new FakeAiGeneratorClient;
            }

            public function status(): AiServiceStatus
            {
                return $this->inner->status();
            }

            public function generate(AiGenerateRequest $request): AiGenerateResponse
            {
                if ($this->calls++ === 0) {
                    throw new ApiException(ErrorCode::LlmProviderError, 'LLM provider error: timed out');
                }

                return $this->inner->generate($request);
            }
        };
        $this->app->instance(AiGeneratorClient::class, $ai);
        $user = User::factory()->tester()->create();
        $experiment = Experiment::factory()->create([
            'benchmark_id' => $this->benchmark()->id,
            'strategy' => GenerationStrategy::Llm,
            'config' => ['model' => null, 'seed' => 42, 'seeds' => [42, 1024], 'temperature' => 0.2, 'repetitions' => 2],
        ]);

        app(RunExperimentAction::class)->execute($experiment->id, $user->id);

        $experiment->refresh();
        $this->assertSame(ExperimentStatus::Completed, $experiment->status);
        $this->actingAs($user)->get("/experiments/{$experiment->id}")
            ->assertOk()
            ->assertSee('Sinh test thất bại: LLM_PROVIDER_ERROR');
    }

    /**
     * Regression (S10-01 audit r3): a HYBRID repetition whose LLM job FAILED was still scored as
     * HYBRID with only the RULE seed cases, reporting RULE's numbers and hiding the failure.
     */
    public function test_hybrid_repetition_with_failed_llm_job_is_scored_as_a_generation_failure(): void
    {
        $this->bindTarget(fn (?string $bug): TargetResponse => $this->response($bug === 'BUG-001' ? 500 : 200));
        // First repetition: provider error; second: the normal fake response.
        $ai = new class implements AiGeneratorClient
        {
            private int $calls = 0;

            private FakeAiGeneratorClient $inner;

            public function __construct()
            {
                $this->inner = new FakeAiGeneratorClient;
            }

            public function status(): AiServiceStatus
            {
                return $this->inner->status();
            }

            public function generate(AiGenerateRequest $request): AiGenerateResponse
            {
                if ($this->calls++ === 0) {
                    throw new ApiException(ErrorCode::LlmProviderError, 'LLM provider error: timed out');
                }

                return $this->inner->generate($request);
            }
        };
        $this->app->instance(AiGeneratorClient::class, $ai);
        $user = User::factory()->tester()->create();
        $experiment = Experiment::factory()->create([
            'benchmark_id' => $this->benchmark()->id,
            'strategy' => GenerationStrategy::Hybrid,
            'config' => ['model' => null, 'seed' => 42, 'seeds' => [42, 1024], 'temperature' => 0.2, 'repetitions' => 2],
        ]);

        app(RunExperimentAction::class)->execute($experiment->id, $user->id);

        $experiment->refresh();
        $this->assertSame(ExperimentStatus::Completed, $experiment->status);
        $first = ($experiment->repetitions_data ?? [])[0];
        // The RULE seed cases were persisted and run, as for any HYBRID repetition...
        $ruleCases = TestCaseModel::query()
            ->where('test_suite_id', $first['test_suite_id'])
            ->where('source', TestCaseSource::Rule)
            ->count();
        $this->assertGreaterThan(0, $ruleCases);
        $strategyJob = GenerationJob::query()->find($first['generation_job_id']);
        $this->assertInstanceOf(GenerationJob::class, $strategyJob);
        $this->assertSame(GenerationJobStatus::Failed, $strategyJob->status);

        // ...but the repetition is scored as a failed generation, not as RULE under HYBRID.
        $metrics = $experiment->metrics ?? [];
        /** @var list<array<string, mixed>> $repetitionRows */
        $repetitionRows = $metrics['repetitions'] ?? [];
        $rows = array_column($repetitionRows, null, 'repetition');
        $failed = $rows[1];
        $this->assertSame(0, $failed['generated']);
        $this->assertSame(0, $failed['valid']);
        $this->assertSame(0, $failed['clean_pass'] + $failed['clean_fail'] + $failed['clean_error']);
        $this->assertSame(0, $failed['bugs_detected']);
        $this->assertEquals(0.0, $failed['validity_rate']);
        $this->assertEquals(0.0, $failed['endpoint_coverage']);

        $succeeded = $rows[2];
        $this->assertGreaterThan($ruleCases, $succeeded['generated']);
        $this->assertSame(1, $succeeded['bugs_detected']);
        $this->assertEquals(0.25, $metrics['bug_detection_rate'] ?? null);

        $this->actingAs($user)->get("/experiments/{$experiment->id}")
            ->assertOk()
            ->assertSee('Sinh test thất bại: LLM_PROVIDER_ERROR');
    }
}
