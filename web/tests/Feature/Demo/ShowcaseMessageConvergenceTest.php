<?php

namespace Tests\Feature\Demo;

use App\Contracts\TargetHostResolver;
use App\Contracts\TargetHttpClient;
use App\Enums\TestCaseStatus;
use App\Models\GenerationJob;
use App\Models\LlmCallLog;
use App\Models\TestCase as TestCaseModel;
use App\Services\Target\FakeTargetHostResolver;
use App\Services\Target\FakeTargetHttpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * QA S14-06 round 3 (r3-showcase-messages): replayed LLM/HYBRID jobs imported by an earlier showcase
 * kept English and duplicated validation messages. The next `ait:demo:showcase` converges them to
 * what an import stores today, without touching llm_call_logs, and a further run changes nothing.
 */
class ShowcaseMessageConvergenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(TargetHostResolver::class, new FakeTargetHostResolver([
            'simple-store' => ['127.0.0.1'],
            'ecommerce-order' => ['127.0.0.1'],
            'appointment-booking' => ['127.0.0.1'],
        ]));
        $this->app->instance(TargetHttpClient::class, new FakeTargetHttpClient);
        config(['ait.demo_showcase.enabled' => true]);
    }

    private function showcase(): void
    {
        $pending = $this->artisan('ait:demo:showcase');
        $this->assertInstanceOf(PendingCommand::class, $pending);
        $pending->assertSuccessful()->run();
    }

    public function test_showcase_rerun_converges_old_messages_of_imported_replays_idempotently(): void
    {
        $this->showcase();

        $replayIds = GenerationJob::query()->get()->filter(fn (GenerationJob $job): bool => $job->isReplayed())->values()->modelKeys();
        $this->assertNotEmpty($replayIds);
        $invalid = TestCaseModel::query()->whereIn('generation_job_id', $replayIds)
            ->where('status', TestCaseStatus::Invalid->value)->orderBy('id')->get();
        $this->assertNotEmpty($invalid, 'the official records contain INVALID cases');
        $current = $invalid->mapWithKeys(fn (TestCaseModel $c): array => [$c->id => $c->validation_errors])->all();

        // What the first showcase stored: raw English opis text next to the web errors.
        foreach ($invalid as $case) {
            $case->forceFill(['validation_errors' => [
                ...($case->validation_errors ?? []),
                'The properties must match schema: steps',
                'All array items must match schema',
            ]])->save();
        }
        $job = GenerationJob::query()->findOrFail($replayIds[0]);
        $job->forceFill(['stats' => [...($job->stats ?? []), 'legacy' => 1]])->save();
        $logs = LlmCallLog::query()->orderBy('id')->get()->toArray();

        $this->showcase();

        foreach ($invalid as $case) {
            $this->assertSame($current[$case->id], $case->refresh()->validation_errors, "case #{$case->id} converged");
        }
        $this->assertArrayNotHasKey('legacy', $job->refresh()->stats ?? []);
        $this->assertSame($logs, LlmCallLog::query()->orderBy('id')->get()->toArray(), 'llm_call_logs untouched');

        $stamps = fn (): array => [
            'cases' => TestCaseModel::query()->whereIn('generation_job_id', $replayIds)->orderBy('id')
                ->pluck('updated_at', 'id')->map(fn ($t): string => (string) $t)->all(),
            'jobs' => GenerationJob::query()->whereIn('id', $replayIds)->orderBy('id')
                ->get(['id', 'updated_at', 'stats', 'error'])->map(fn (GenerationJob $j): array => [$j->id, (string) $j->updated_at, $j->stats, $j->error])->all(),
            'llm_call_logs' => LlmCallLog::query()->count(),
        ];
        $before = $stamps();
        $this->travel(1)->hours();
        $this->showcase();
        $this->assertSame($before, $stamps(), 'a further run changes nothing');
    }
}
