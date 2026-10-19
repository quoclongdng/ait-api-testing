<?php

namespace Tests\Feature\Experiments;

use App\Actions\Benchmarks\AllowBenchmarkTargetsAction;
use App\Actions\Experiments\BuildExperimentComparisonAction;
use App\Contracts\BenchmarkSandbox;
use App\Enums\ExperimentStatus;
use App\Enums\GenerationStrategy;
use App\Jobs\RunExperimentJob;
use App\Models\AllowedTarget;
use App\Models\Benchmark;
use App\Models\BenchmarkBug;
use App\Models\Experiment;
use App\Models\User;
use App\Services\Benchmark\UrlTemplateBenchmarkSandbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class ExperimentCommandsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function command(string $name, array $parameters = []): PendingCommand
    {
        $command = $this->artisan($name, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    private function benchmark(string $slug, string $baseUrl): Benchmark
    {
        $benchmark = Benchmark::factory()->create(['slug' => $slug, 'base_url' => $baseUrl]);
        BenchmarkBug::factory()->create(['benchmark_id' => $benchmark->id, 'code' => 'BUG-001', 'toggle_flag' => 'BUG_001']);
        BenchmarkBug::factory()->create(['benchmark_id' => $benchmark->id, 'code' => 'BUG-002', 'toggle_flag' => 'BUG_002']);

        return $benchmark;
    }

    public function test_run_creates_and_queues_one_experiment_per_benchmark_and_strategy(): void
    {
        Queue::fake();
        $tester = User::factory()->tester()->create(['email' => 'tester@example.test']);
        $this->benchmark('simple-store', 'http://benchmark:8002');
        $this->benchmark('ecommerce-order', 'http://benchmark-order:8003');

        $this->command('ait:experiments:run', [
            '--strategies' => 'RULE,llm',
            '--benchmarks' => 'all',
            '--repetitions' => '3',
            '--model' => 'qwen2.5-coder:7b',
            '--temperature' => '0.3',
            '--user' => 'TESTER@example.test',
        ])->expectsOutputToContain('EXPERIMENT_IDS=')->assertSuccessful();

        $experiments = Experiment::query()->with('benchmark')->orderBy('id')->get();
        $this->assertCount(4, $experiments);
        $this->assertSame(
            ['ecommerce-order/RULE', 'ecommerce-order/LLM', 'simple-store/RULE', 'simple-store/LLM'],
            $experiments->map(fn (Experiment $e): string => "{$e->benchmark->slug}/{$e->strategy->value}")->all(),
        );
        foreach ($experiments as $experiment) {
            $this->assertSame(ExperimentStatus::Planned, $experiment->status);
            $this->assertSame([42, 1024, 2026], $experiment->config['seeds']);
            $this->assertSame(42, $experiment->config['seed']);
            $this->assertSame('qwen2.5-coder:7b', $experiment->config['model']);
            $this->assertEquals(0.3, $experiment->config['temperature']);
            $this->assertSame(3, $experiment->config['repetitions']);
        }
        Queue::assertPushed(RunExperimentJob::class, 4);
        Queue::assertPushed(RunExperimentJob::class, fn (RunExperimentJob $job): bool => $job->userId === $tester->id);

        // The benchmark sandboxes were added to the allowlist (D07) on behalf of that user.
        $this->assertDatabaseHas('allowed_targets', ['host' => 'benchmark', 'port' => 8002, 'created_by' => $tester->id]);
        $this->assertDatabaseHas('allowed_targets', ['host' => 'benchmark-order', 'port' => 8003]);
    }

    public function test_cli_batch_strategies_share_one_m15_comparison_group(): void
    {
        Queue::fake();
        User::factory()->admin()->create();
        $this->benchmark('simple-store', 'http://benchmark:8002');

        $this->command('ait:experiments:run', [
            '--strategies' => 'RULE,LLM,HYBRID',
            '--benchmarks' => 'simple-store',
            '--skip-allow-targets' => true,
        ])->assertSuccessful();

        $this->assertCount(3, Experiment::query()->get());
        $groups = app(BuildExperimentComparisonAction::class)->execute();
        $this->assertCount(1, $groups);
        $this->assertSame('make experiment · simple-store', $groups[0]['name']);
        foreach (GenerationStrategy::cases() as $strategy) {
            $this->assertNotNull($groups[0]['experiments'][$strategy->value], $strategy->value);
        }
    }

    public function test_run_with_selected_benchmarks_and_explicit_seeds(): void
    {
        Queue::fake();
        User::factory()->admin()->create();
        $this->benchmark('simple-store', 'http://benchmark:8002');
        $this->benchmark('ecommerce-order', 'http://benchmark-order:8003');

        $this->command('ait:experiments:run', [
            '--strategies' => 'HYBRID',
            '--benchmarks' => 'simple-store',
            '--repetitions' => '4',
            '--seeds' => '7,8',
            '--skip-allow-targets' => true,
        ])->assertSuccessful();

        $experiment = Experiment::query()->sole();
        $this->assertSame(GenerationStrategy::Hybrid, $experiment->strategy);
        $this->assertSame([7, 8, 9, 10], $experiment->config['seeds']);
        $this->assertNull($experiment->config['model']);
        $this->assertSame(0, AllowedTarget::query()->count());
    }

    public function test_run_rejects_invalid_input(): void
    {
        Queue::fake();
        $this->benchmark('simple-store', 'http://benchmark:8002');

        $this->command('ait:experiments:run', ['--strategies' => 'RULE'])->assertFailed(); // no ADMIN
        User::factory()->admin()->create();
        $this->command('ait:experiments:run', ['--strategies' => 'RANDOM'])->assertFailed();
        $this->command('ait:experiments:run', ['--benchmarks' => 'nope'])->assertFailed();
        $this->command('ait:experiments:run', ['--repetitions' => '2'])->assertFailed();
        $this->command('ait:experiments:run', ['--seeds' => '1,x'])->assertFailed();
        $this->command('ait:experiments:run', ['--user' => 'missing@example.test'])->assertFailed();

        $this->assertSame(0, Experiment::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_run_end_to_end_on_the_sync_queue_then_wait_succeeds(): void
    {
        User::factory()->admin()->create();
        $this->benchmark('simple-store', 'http://benchmark:8002');

        $this->command('ait:experiments:run', ['--strategies' => 'RULE', '--benchmarks' => 'simple-store'])->assertSuccessful();

        $experiment = Experiment::query()->sole();
        $this->assertSame(ExperimentStatus::Completed, $experiment->status);
        $this->assertSame([42, 1024, 2026], array_column($experiment->repetitions_data ?? [], 'seed'));

        $this->command('ait:experiments:wait', ['ids' => [(string) $experiment->id], '--interval' => '0'])
            ->expectsOutputToContain('Mọi thực nghiệm đã COMPLETED.')
            ->assertSuccessful();
    }

    public function test_wait_polls_until_experiments_finish(): void
    {
        Sleep::fake();
        $experiment = Experiment::factory()->create(['status' => ExperimentStatus::Running, 'started_at' => now()]);
        Sleep::whenFakingSleep(function () use ($experiment): void {
            $experiment->forceFill(['status' => ExperimentStatus::Completed, 'finished_at' => now()])->save();
        });

        $this->command('ait:experiments:wait', ['--interval' => '5'])->assertSuccessful();

        Sleep::assertSleptTimes(1);
    }

    public function test_wait_fails_when_an_experiment_failed_or_on_timeout(): void
    {
        Sleep::fake();
        $failed = Experiment::factory()->create(['status' => ExperimentStatus::Failed, 'metrics' => ['error' => 'sandbox down']]);
        $done = Experiment::factory()->create(['status' => ExperimentStatus::Completed]);

        $this->command('ait:experiments:wait', ['ids' => ["{$failed->id},{$done->id}"]])
            ->expectsOutputToContain('sandbox down')
            ->assertFailed();

        $running = Experiment::factory()->create(['status' => ExperimentStatus::Running]);
        $this->command('ait:experiments:wait', ['ids' => [(string) $running->id], '--interval' => '10', '--timeout' => '20'])
            ->expectsOutputToContain('Hết thời gian chờ')
            ->assertFailed();
        Sleep::assertSleptTimes(2);

        $this->command('ait:experiments:wait', ['ids' => ['999999']])->assertFailed();
        $this->command('ait:experiments:wait', ['ids' => ['x']])->assertFailed();
    }

    public function test_wait_without_pending_experiments_returns_immediately(): void
    {
        Experiment::factory()->create(['status' => ExperimentStatus::Completed]);

        $this->command('ait:experiments:wait')->expectsOutputToContain('Không có thực nghiệm nào')->assertSuccessful();
    }

    public function test_import_with_allow_targets_adds_clean_and_variant_hosts_idempotently(): void
    {
        $admin = User::factory()->admin()->create();
        $this->app->instance(BenchmarkSandbox::class, new UrlTemplateBenchmarkSandbox('http://{slug}-{toggle}:8000', 'http://{slug}:8000'));

        $this->command('ait:benchmarks:import', ['slugs' => ['simple-store'], '--allow-targets' => true])->assertSuccessful();

        $hosts = AllowedTarget::query()->orderBy('host')->get();
        $this->assertCount(11, $hosts); // clean + 10 seeded bugs
        $this->assertSame('simple-store', $hosts->first()?->host);
        $this->assertTrue($hosts->every(fn (AllowedTarget $t): bool => $t->port === 8000 && $t->created_by === $admin->id));
        $this->assertDatabaseHas('allowed_targets', ['host' => 'simple-store-bug_001', 'port' => 8000]);

        $this->command('ait:benchmarks:import', ['slugs' => ['simple-store'], '--allow-targets' => true])->assertSuccessful();
        $this->assertSame(11, AllowedTarget::query()->count());

        $this->command('ait:benchmarks:import', ['slugs' => ['simple-store'], '--allow-targets' => true, '--user' => 'nobody@example.test'])->assertFailed();
    }

    public function test_allow_targets_skips_hosts_already_allowed_for_any_port_and_handles_missing_variant_template(): void
    {
        $admin = User::factory()->admin()->create();
        $benchmark = $this->benchmark('simple-store', 'http://benchmark:8002');
        AllowedTarget::factory()->create(['host' => 'simple-store', 'port' => null, 'created_by' => $admin->id]);
        $this->app->instance(BenchmarkSandbox::class, new UrlTemplateBenchmarkSandbox('', 'https://{slug}'));

        $rows = app(AllowBenchmarkTargetsAction::class)->execute($admin, [$benchmark->id]);

        $this->assertSame([['benchmark' => 'simple-store', 'host' => 'simple-store', 'port' => 443, 'created' => false]], $rows);
        $this->assertSame(1, AllowedTarget::query()->count());
    }
}
