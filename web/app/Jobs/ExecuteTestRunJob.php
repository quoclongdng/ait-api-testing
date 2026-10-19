<?php

namespace App\Jobs;

use App\Actions\Runs\ExecuteTestRunAction;
use App\Models\TestRun;
use App\Services\Benchmark\BenchmarkRunAuthResolver;
use App\Services\Benchmark\BenchmarkTargetReset;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Queue worker entry point for a TestRun (docs/DESIGN.md §2 D10, §4.3).
 *
 * The job is a thin shell: the use case lives in ExecuteTestRunAction. A run is executed
 * at most once (no automatic retry) because test_results are insert-only.
 */
class ExecuteTestRunJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * A run executes every case sequentially, each request bounded by TARGET_DEFAULT_TIMEOUT_MS
     * (10 s): 600 s killed legitimate large runs against slow targets. Same budget as an experiment
     * step, below the worker --timeout (1800 s) < DB_QUEUE_RETRY_AFTER (1900 s), guarded by
     * QueueTimeoutConsistencyTest. A run killed anyway is closed by failed() with the unexecuted
     * cases stored as SKIPPED and totals filled.
     */
    public const TIMEOUT = 1500;

    public int $timeout = self::TIMEOUT;

    /**
     * @param  list<int>|null  $testCaseIds  Optional subset of the suite's runnable cases.
     */
    public function __construct(
        public int $testRunId,
        public ?array $testCaseIds = null,
    ) {}

    /**
     * QA S13-03 (ADR-0006 addendum 2026-10-13): a run on one of the platform's benchmark sandboxes
     * (RUN_RESET_BENCHMARK_HOSTS) resets it to the seed data before every case, so the shared clean
     * benchmark gives the same verdicts on every re-run. Any other target is never reset.
     *
     * QA S14-06 (ADR-0006 addendum 2026-10-14): such a run also uses the benchmark's public synthetic
     * auth fixture from its ground truth, like an experiment run, so a suite generated with credentials
     * (the demo suites, experiment cases with `{{auth_token_<role>}}`) gives the recorded verdicts on
     * re-run. Any other target never gets a fixture.
     */
    public function handle(ExecuteTestRunAction $action, BenchmarkTargetReset $benchmarkReset, BenchmarkRunAuthResolver $benchmarkAuth): void
    {
        $target = TestRun::query()->whereKey($this->testRunId)->value('target_base_url');
        $target = is_string($target) ? $target : null;

        $action->execute(
            $this->testRunId,
            $this->testCaseIds,
            auth: $benchmarkAuth->fixtureFor($target),
            beforeEachCase: $benchmarkReset->resetterFor($target),
        );
    }

    /**
     * Called by the worker when handle() throws or the job times out.
     */
    public function failed(?Throwable $exception): void
    {
        app(ExecuteTestRunAction::class)->markFailed($this->testRunId, $this->testCaseIds);
    }
}
