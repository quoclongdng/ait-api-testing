<?php

namespace App\Http\Controllers\Web;

use App\Actions\Runs\ShowRunReportAction;
use App\Http\Controllers\Controller;
use App\Models\TestRun;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * M13: run report screen (S8-01). File downloads go through GET /api/v1/runs/{id}/export.
 */
class TestRunReportController extends Controller
{
    public function show(TestRun $run, ShowRunReportAction $action): View
    {
        Gate::authorize('view', $run);

        $report = $action->execute($run);

        return view('runs.report', [
            'report' => $report,
            'run' => $report->run,
            'suite' => $report->run->testSuite,
            'project' => $report->run->testSuite->project,
        ]);
    }
}
