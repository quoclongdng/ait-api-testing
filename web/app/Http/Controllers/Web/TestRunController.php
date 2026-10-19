<?php

namespace App\Http\Controllers\Web;

use App\Actions\Runs\CountRunnableTestCasesAction;
use App\Actions\Runs\ListRunCaseSummariesAction;
use App\Actions\Runs\ListRunTargetOptionsAction;
use App\Actions\Runs\ListSuiteTestRunsAction;
use App\Actions\Runs\ShowTestRunAction;
use App\Http\Controllers\Controller;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Services\Benchmark\BenchmarkTargetReset;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Screen M12: start a test run, follow its progress and inspect per-case results.
 * Starting, cancelling and polling go through /api/v1 (S4-03) from Alpine.
 */
class TestRunController extends Controller
{
    public function index(
        TestSuite $suite,
        ListSuiteTestRunsAction $listRuns,
        ListRunTargetOptionsAction $listTargets,
        CountRunnableTestCasesAction $countRunnable,
        BenchmarkTargetReset $benchmarkReset,
    ): View {
        Gate::authorize('view', $suite);

        $suite->load(['project', 'apiSpec']);

        return view('runs.index', [
            'suite' => $suite,
            'project' => $suite->project,
            'runs' => $listRuns->execute($suite),
            'targets' => $listTargets->execute(),
            'runnableCount' => $countRunnable->execute($suite),
            'resetBenchmarkHosts' => $benchmarkReset->hosts(),
        ]);
    }

    public function show(TestRun $run, ShowTestRunAction $showRun, ListRunCaseSummariesAction $summaries): View
    {
        Gate::authorize('view', $run);

        $run = $showRun->execute($run);
        $summary = $summaries->execute($run);

        return view('runs.show', [
            'run' => $run,
            'suite' => $run->testSuite,
            'project' => $run->testSuite->project,
            'cases' => $summary['cases'],
            'runnableCount' => $summary['runnable'],
        ]);
    }
}
