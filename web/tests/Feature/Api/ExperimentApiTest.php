<?php

namespace Tests\Feature\Api;

use App\Contracts\BenchmarkSandbox;
use App\Enums\ExperimentStatus;
use App\Enums\GenerationStrategy;
use App\Jobs\RunExperimentJob;
use App\Models\Benchmark;
use App\Models\BenchmarkBug;
use App\Models\Experiment;
use App\Models\ExperimentBugDetection;
use App\Models\TestRun;
use App\Models\User;
use App\Services\Benchmark\UrlTemplateBenchmarkSandbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class ExperimentApiTest extends TestCase
{
    use RefreshDatabase;

    private function benchmarkWithBugs(string $slug = 'simple-store'): Benchmark
    {
        $benchmark = Benchmark::factory()->create(['slug' => $slug]);
        BenchmarkBug::factory()->count(2)->create(['benchmark_id' => $benchmark->id]);

        return $benchmark;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Benchmark $benchmark, string $strategy = 'RULE'): array
    {
        return [
            'name' => 'So sánh đợt 1',
            'benchmark_id' => $benchmark->id,
            'strategy' => $strategy,
            'config' => ['model' => 'qwen2.5-coder:7b', 'seed' => 42, 'temperature' => 0.2, 'repetitions' => 3],
        ];
    }

    public function test_guest_gets_401_envelope(): void
    {
        $this->getJson('/api/v1/experiments')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->postJson('/api/v1/experiments', [])->assertStatus(401);
    }

    public function test_tester_creates_planned_experiment_and_job_is_queued(): void
    {
        Queue::fake();
        $tester = User::factory()->tester()->create();
        $benchmark = $this->benchmarkWithBugs();

        $response = $this->actingAs($tester)->postJson('/api/v1/experiments', $this->payload($benchmark, 'HYBRID'));

        $response->assertStatus(202)
            ->assertJsonPath('data.status', 'PLANNED')
            ->assertJsonPath('data.strategy', 'HYBRID')
            ->assertJsonPath('data.benchmark_id', $benchmark->id)
            ->assertJsonPath('data.config.repetitions', 3)
            ->assertJsonPath('data.config.seed', 42)
            ->assertJsonPath('data.metrics', null);

        Queue::assertPushed(RunExperimentJob::class, fn (RunExperimentJob $job): bool => $job->experimentId === $response->json('data.id') && $job->userId === $tester->id);
        // S14-06: experiments run on the llm queue with LLM/HYBRID generation.
        Queue::assertPushedOn('llm', RunExperimentJob::class);
    }

    public function test_created_experiment_stores_protocol_seeds_per_repetition(): void
    {
        Queue::fake();
        $tester = User::factory()->tester()->create();
        $benchmark = $this->benchmarkWithBugs();

        $this->actingAs($tester)->postJson('/api/v1/experiments', $this->payload($benchmark))
            ->assertStatus(202)
            ->assertJsonPath('data.config.seeds', [42, 1024, 2026]);

        $payload = $this->payload($benchmark);
        $payload['config']['repetitions'] = 4;
        $payload['config']['seed'] = 7;
        $this->actingAs($tester)->postJson('/api/v1/experiments', $payload)
            ->assertStatus(202)
            ->assertJsonPath('data.config.seed', 7)
            ->assertJsonPath('data.config.seeds', [7, 42, 1024, 2026]);

        $payload['config']['seeds'] = [5, 9, 11, 13];
        $this->actingAs($tester)->postJson('/api/v1/experiments', $payload)
            ->assertStatus(202)
            ->assertJsonPath('data.config.seed', 5)
            ->assertJsonPath('data.config.seeds', [5, 9, 11, 13]);

        $payload['config']['seeds'] = [5, 5];
        $this->actingAs($tester)->postJson('/api/v1/experiments', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_validation_errors_use_envelope(): void
    {
        Queue::fake();
        $tester = User::factory()->tester()->create();
        $payload = $this->payload($this->benchmarkWithBugs());
        $payload['config']['repetitions'] = 2;
        $payload['strategy'] = 'RANDOM';

        $this->actingAs($tester)->postJson('/api/v1/experiments', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(['config.repetitions', 'strategy'], 'error.details');

        Queue::assertNothingPushed();
    }

    public function test_benchmark_without_bugs_is_rejected(): void
    {
        Queue::fake();
        $tester = User::factory()->tester()->create();
        $benchmark = Benchmark::factory()->create(['slug' => 'simple-store']);

        $this->actingAs($tester)->postJson('/api/v1/experiments', $this->payload($benchmark))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertSame(0, Experiment::query()->count());
    }

    public function test_benchmark_without_openapi_file_is_rejected(): void
    {
        Queue::fake();
        $tester = User::factory()->tester()->create();

        $this->actingAs($tester)->postJson('/api/v1/experiments', $this->payload($this->benchmarkWithBugs('no-such-benchmark')))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_unconfigured_bug_variant_sandbox_is_rejected(): void
    {
        Queue::fake();
        $this->app->instance(BenchmarkSandbox::class, new UrlTemplateBenchmarkSandbox(''));
        $tester = User::factory()->tester()->create();

        $this->actingAs($tester)->postJson('/api/v1/experiments', $this->payload($this->benchmarkWithBugs()))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.message', 'Chưa cấu hình BENCHMARK_VARIANT_URL_TEMPLATE nên không thể chạy các biến thể có bug của benchmark.');
    }

    public function test_list_is_paginated_and_visible_to_every_tester(): void
    {
        $other = User::factory()->tester()->create();
        Experiment::factory()->count(3)->create();

        $this->actingAs($other)->getJson('/api/v1/experiments?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.current_page', 1);
    }

    public function test_show_returns_metrics_repetitions_and_bug_detections(): void
    {
        $admin = User::factory()->admin()->create();
        $bug = BenchmarkBug::factory()->create();
        $run = TestRun::factory()->create();
        $experiment = Experiment::factory()->create([
            'benchmark_id' => $bug->benchmark_id,
            'strategy' => GenerationStrategy::Llm,
            'status' => ExperimentStatus::Completed,
            'metrics' => ['validity_rate' => 0.9, 'stddev' => ['validity_rate' => 0.05]],
            'repetitions_data' => [[
                'repetition' => 1, 'seed' => 42, 'generation_job_id' => 1, 'test_suite_id' => $run->test_suite_id,
                'clean_test_run_id' => $run->id, 'bug_runs' => [$bug->code => $run->id],
            ]],
        ]);
        ExperimentBugDetection::factory()->create([
            'experiment_id' => $experiment->id,
            'benchmark_bug_id' => $bug->id,
            'repetition' => 1,
            'detected' => true,
            'clean_test_run_id' => $run->id,
            'bug_test_run_id' => $run->id,
            'detecting_test_case_ids' => [7],
        ]);

        $this->actingAs($admin)->getJson("/api/v1/experiments/{$experiment->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.metrics.validity_rate', 0.9)
            ->assertJsonPath('data.repetitions_data.0.bug_runs.'.$bug->code, $run->id)
            ->assertJsonPath('data.bug_detections.0.detected', true)
            ->assertJsonPath('data.bug_detections.0.detecting_test_case_ids', [7]);
    }

    public function test_show_missing_experiment_returns_404_envelope(): void
    {
        $tester = User::factory()->tester()->create();

        $this->actingAs($tester)->getJson('/api/v1/experiments/999999')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    /**
     * Regression (S10-01 audit round 3): GET /experiments and /experiments/{id} must match the
     * ExperimentRepetition contract (`additionalProperties: false`). Since ADR-0006 the contract
     * declares `generation_job_ids` (HYBRID: RULE seed job + HYBRID job); other internal keys are
     * still dropped, and entries stored before the key existed fall back to `[generation_job_id]`.
     */
    public function test_show_and_list_match_the_openapi_contract_with_every_generation_job(): void
    {
        $tester = User::factory()->tester()->create();
        $run = TestRun::factory()->create();
        $experiment = Experiment::factory()->create([
            'strategy' => GenerationStrategy::Hybrid,
            'status' => ExperimentStatus::Running,
            'config' => ['model' => 'qwen2.5-coder:7b', 'seed' => 42, 'seeds' => [42, 1024, 2026], 'temperature' => 0.2, 'repetitions' => 3],
            'metrics' => null,
            'repetitions_data' => [[
                'repetition' => 1, 'seed' => 42, 'generation_job_id' => 12, 'generation_job_ids' => [11, 12],
                'test_suite_id' => $run->test_suite_id, 'clean_test_run_id' => $run->id, 'bug_runs' => [],
                '_internal' => 'not in the contract',
            ], [
                'repetition' => 2, 'seed' => 1024, 'generation_job_id' => 14,
                'test_suite_id' => $run->test_suite_id, 'clean_test_run_id' => null, 'bug_runs' => [],
            ]],
        ]);

        $validator = new Validator;
        $validator->resolver()?->registerRaw(
            (string) json_encode(Yaml::parseFile(base_path('../docs/contracts/web-api.openapi.yaml'))),
            'https://ait.local/web-api.json',
        );

        foreach ([
            "/api/v1/experiments/{$experiment->id}" => 'ExperimentResponse',
            '/api/v1/experiments' => 'ExperimentCollectionResponse',
        ] as $uri => $schema) {
            $content = (string) $this->actingAs($tester)->getJson($uri)->assertOk()->getContent();
            $this->assertStringNotContainsString('_internal', $content);
            $result = $validator->validate(json_decode($content), "https://ait.local/web-api.json#/components/schemas/{$schema}");
            $this->assertTrue($result->isValid(), $uri.' '.json_encode($result->error() !== null ? (new ErrorFormatter)->format($result->error()) : null));
        }

        $this->actingAs($tester)->getJson("/api/v1/experiments/{$experiment->id}")
            ->assertJsonPath('data.repetitions_data.0.generation_job_id', 12)
            ->assertJsonPath('data.repetitions_data.0.generation_job_ids', [11, 12])
            ->assertJsonPath('data.repetitions_data.1.generation_job_ids', [14]);
    }
}
