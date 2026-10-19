<?php

namespace Tests\Feature\Experiments;

use App\Actions\Experiments\RunExperimentAction;
use App\Contracts\BenchmarkSandbox;
use App\Contracts\TargetHttpClient;
use App\Data\TargetRequest;
use App\Data\TargetResponse;
use App\Enums\BenchmarkBugCategory;
use App\Enums\ExperimentStatus;
use App\Enums\GenerationStrategy;
use App\Enums\HttpMethod;
use App\Enums\TestRunStatus;
use App\Models\Benchmark;
use App\Models\BenchmarkBug;
use App\Models\Experiment;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\User;
use App\Services\Benchmark\FakeBenchmarkSandbox;
use App\Services\Experiments\ExperimentExporter;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ADR-0006 addendum 2026-10-12: with experiments.config.reset_per_case every test case of an
 * experiment run (clean and bug variants, every strategy) starts from the sandbox seed data.
 */
class ExperimentCaseIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{method: string, path: string, status: int}> */
    private array $answered = [];

    private function sandbox(): FakeBenchmarkSandbox
    {
        /** @var FakeBenchmarkSandbox $sandbox */
        $sandbox = app(BenchmarkSandbox::class);

        return $sandbox;
    }

    /**
     * Stateful double: a product created since the last sandbox reset makes the next create
     * conflict (409), like a unique SKU/slot; everything else answers 200.
     *
     * @param  (Closure(): void)|null  $onRequest
     */
    private function bindStatefulTarget(?Closure $onRequest = null): void
    {
        $createdAtReset = null;
        $respond = function (TargetRequest $request) use (&$createdAtReset, $onRequest): TargetResponse {
            if ($onRequest !== null) {
                $onRequest();
            }
            $path = (string) parse_url($request->url, PHP_URL_PATH);
            $resets = count($this->sandbox()->resets);
            $status = 200;
            if ($request->method === 'POST' && $path === '/products') {
                $status = $createdAtReset === $resets ? 409 : 201;
                $createdAtReset = $resets;
            }
            $this->answered[] = ['method' => $request->method, 'path' => $path, 'status' => $status];

            return new TargetResponse($status, ['Content-Type' => ['application/json']], '{}', 1);
        };

        $this->app->instance(TargetHttpClient::class, new class($respond) implements TargetHttpClient
        {
            /** @param Closure(TargetRequest): TargetResponse $respond */
            public function __construct(private Closure $respond) {}

            public function send(TargetRequest $request): TargetResponse
            {
                return ($this->respond)($request);
            }
        });
    }

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

        return $benchmark;
    }

    private function experiment(bool $resetPerCase, ?bool $key = true): Experiment
    {
        $config = ['model' => null, 'seed' => 42, 'seeds' => [42], 'temperature' => 0.2, 'repetitions' => 1];
        if ($key === true) {
            $config['reset_per_case'] = $resetPerCase;
        }

        return Experiment::factory()->create([
            'benchmark_id' => $this->benchmark()->id,
            'strategy' => GenerationStrategy::Rule,
            'config' => $config,
        ]);
    }

    public function test_reset_per_case_resets_the_sandbox_before_every_case_of_every_variant(): void
    {
        $this->bindStatefulTarget();
        $user = User::factory()->tester()->create();
        $experiment = $this->experiment(true);

        app(RunExperimentAction::class)->execute($experiment->id, $user->id);

        $this->assertSame(ExperimentStatus::Completed, $experiment->refresh()->status);
        $runs = TestRun::query()->orderBy('id')->get();
        $this->assertSame(['clean', 'BUG-001'], $runs->pluck('benchmark_variant')->all());

        // One reset per case: the run-level reset covers the first case, then one between cases.
        $expected = [];
        foreach ($runs as $run) {
            $cases = TestResult::query()->where('test_run_id', $run->id)->count();
            $this->assertGreaterThan(1, $cases);
            array_push($expected, ...array_fill(0, $cases, $run->benchmark_variant === 'clean' ? null : 'BUG-001'));
        }
        $this->assertSame($expected, array_column($this->sandbox()->resets, 'bug'));

        // No create ever sees the product of an earlier case.
        $creates = array_filter($this->answered, fn (array $a): bool => $a['method'] === 'POST' && $a['path'] === '/products');
        $this->assertGreaterThan(1, count($creates));
        $this->assertNotContains(409, array_column($creates, 'status'));
    }

    public function test_without_reset_per_case_cases_share_state_as_before(): void
    {
        $this->bindStatefulTarget();
        $user = User::factory()->tester()->create();
        // Experiments created before the setting existed have no key: no per-case reset.
        $experiment = $this->experiment(false, key: false);

        app(RunExperimentAction::class)->execute($experiment->id, $user->id);

        $this->assertSame(ExperimentStatus::Completed, $experiment->refresh()->status);
        $this->assertSame([null, 'BUG-001'], array_column($this->sandbox()->resets, 'bug'));
        $creates = array_filter($this->answered, fn (array $a): bool => $a['method'] === 'POST' && $a['path'] === '/products');
        $this->assertContains(409, array_column($creates, 'status'));
    }

    public function test_a_failing_per_case_reset_fails_the_run_and_the_experiment(): void
    {
        // The run-level reset succeeds; the sandbox dies during the first case.
        $this->bindStatefulTarget(function (): void {
            $this->sandbox()->failResetWith = 'Reset sandbox thất bại (HTTP 500).';
        });
        $user = User::factory()->tester()->create();
        $experiment = $this->experiment(true);

        app(RunExperimentAction::class)->execute($experiment->id, $user->id);

        $experiment->refresh();
        $this->assertSame(ExperimentStatus::Failed, $experiment->status);
        $this->assertSame('Reset sandbox thất bại (HTTP 500).', $experiment->metrics['error'] ?? null);
        $run = TestRun::query()->sole();
        $this->assertSame(TestRunStatus::Failed, $run->status);
        // The first case ran; the cases never reached are stored as SKIPPED.
        $this->assertSame(1, TestResult::query()->where('test_run_id', $run->id)->where('verdict', '!=', 'SKIPPED')->count());
    }

    public function test_creation_snapshots_the_setting_and_export_reports_it(): void
    {
        Queue::fake();
        $user = User::factory()->tester()->create();
        $benchmark = $this->benchmark();
        $payload = [
            'name' => 'isolation',
            'benchmark_id' => $benchmark->id,
            'strategy' => 'RULE',
            'config' => ['seed' => 42, 'temperature' => 0.2, 'repetitions' => 3],
        ];

        $on = (int) $this->actingAs($user)->postJson('/api/v1/experiments', $payload)->assertStatus(202)->json('data.id');
        config(['ait.experiments.reset_per_case' => false]);
        $off = (int) $this->actingAs($user)->postJson('/api/v1/experiments', $payload)->assertStatus(202)->json('data.id');

        $this->assertTrue(Experiment::query()->findOrFail($on)->config['reset_per_case']);
        $this->assertFalse(Experiment::query()->findOrFail($off)->config['reset_per_case']);
        $this->actingAs($user)->getJson("/api/v1/experiments/{$on}")->assertOk()->assertJsonPath('data.config.reset_per_case', true);

        // Clients cannot choose it: the snapshot comes from EXPERIMENT_RESET_PER_CASE only.
        $this->actingAs($user)->postJson('/api/v1/experiments', ['config' => $payload['config'] + ['reset_per_case' => false]] + $payload)
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $exporter = app(ExperimentExporter::class);
        $legacy = Experiment::factory()->create(['benchmark_id' => $benchmark->id, 'config' => ['seed' => 42, 'temperature' => 0.2, 'repetitions' => 3]]);
        $document = $exporter->document([Experiment::query()->findOrFail($on), $legacy->refresh()]);
        $this->assertSame([true, false], array_map(fn (array $item): bool => $item['config']['reset_per_case'], $document['experiments']));
    }
}
