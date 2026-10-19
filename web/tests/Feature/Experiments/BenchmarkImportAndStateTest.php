<?php

namespace Tests\Feature\Experiments;

use App\Actions\Benchmarks\ImportGroundTruthAction;
use App\Actions\Experiments\TransitionExperimentAction;
use App\Enums\ErrorCode;
use App\Enums\ExperimentStatus;
use App\Exceptions\ApiException;
use App\Models\Benchmark;
use App\Models\BenchmarkBug;
use App\Models\Experiment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class BenchmarkImportAndStateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function command(string $name, array $parameters): PendingCommand
    {
        $command = $this->artisan($name, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    public function test_import_is_idempotent_and_keeps_stale_bugs(): void
    {
        $action = app(ImportGroundTruthAction::class);

        $first = $action->execute(['simple-store']);
        $this->assertTrue($first[0]['created']);
        $this->assertSame(10, $first[0]['bugs_created']);

        $benchmark = Benchmark::query()->findOrFail($first[0]['benchmark_id']);
        // Clean variant must be reachable inside Docker (network alias of the clean service).
        $this->assertSame('http://simple-store:8000', $benchmark->base_url);
        $benchmark->update(['base_url' => 'http://benchmark:8000']);
        BenchmarkBug::factory()->create(['benchmark_id' => $benchmark->id, 'code' => 'BUG-999']);
        BenchmarkBug::query()->where('code', 'BUG-001')->update(['description' => 'changed']);

        $second = $action->execute(['simple-store']);
        $this->assertFalse($second[0]['created']);
        $this->assertSame(0, $second[0]['bugs_created']);
        $this->assertSame(1, $second[0]['bugs_updated']);
        $this->assertSame(['BUG-999'], $second[0]['stale_bugs']);
        $this->assertSame(11, $benchmark->bugs()->count());
        // An admin-adjusted base URL is not overwritten by the file.
        $this->assertSame('http://benchmark:8000', $benchmark->refresh()->base_url);
    }

    public function test_import_command_and_seeder(): void
    {
        $this->command('ait:benchmarks:import', ['slugs' => ['ecommerce-order']])
            ->assertSuccessful();
        $this->assertSame(12, BenchmarkBug::query()->count());

        $this->command('ait:benchmarks:import', ['slugs' => ['missing-benchmark']])
            ->assertFailed();

        $this->seed();
        $this->assertSame(3, Benchmark::query()->count());
        $this->assertSame(32, BenchmarkBug::query()->count());
    }

    public function test_summarize_runner_command_prints_metrics(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'runner');
        file_put_contents((string) $file, (string) json_encode([
            'name' => 'exp', 'benchmark' => 'simple-store', 'strategy' => 'WITNESS', 'status' => 'COMPLETED',
            'repetitions_data' => [['repetition' => 1, 'seed' => 42, 'total_cases' => 4, 'clean_pass_count' => 4, 'clean_fail_count' => 0, 'clean_error_count' => 0, 'bugs_total' => 2, 'bugs_detected' => 1]],
        ]));

        $this->command('ait:experiments:summarize-runner', ['files' => [(string) $file]])
            ->expectsOutputToContain('50.0% ± 0.0')
            ->assertSuccessful();

        @unlink((string) $file);
    }

    public function test_experiment_state_machine(): void
    {
        $transition = app(TransitionExperimentAction::class);
        $experiment = Experiment::factory()->create();

        $transition->execute($experiment, ExperimentStatus::Running);
        $this->assertSame(ExperimentStatus::Running, $experiment->status);
        $this->assertNotNull($experiment->refresh()->started_at);

        $transition->execute($experiment, ExperimentStatus::Completed, ['metrics' => ['validity_rate' => 1.0]]);
        $this->assertSame(ExperimentStatus::Completed, $experiment->refresh()->status);
        $this->assertNotNull($experiment->finished_at);

        try {
            $transition->execute($experiment, ExperimentStatus::Running);
            $this->fail('COMPLETED → RUNNING must be rejected');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::InvalidStateTransition, $e->errorCode);
        }

        $planned = Experiment::factory()->create();
        $this->expectException(ApiException::class);
        $transition->execute($planned, ExperimentStatus::Completed);
    }
}
