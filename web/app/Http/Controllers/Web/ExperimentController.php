<?php

namespace App\Http\Controllers\Web;

use App\Actions\Benchmarks\ListBenchmarksAction;
use App\Actions\Experiments\BuildExperimentComparisonAction;
use App\Actions\Experiments\CreateExperimentAction;
use App\Actions\Experiments\ListExperimentsAction;
use App\Actions\Experiments\ShowExperimentAction;
use App\Http\Controllers\Controller;
use App\Models\Experiment;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * M15 — Thực nghiệm so sánh định lượng (TESTER, ADMIN).
 */
class ExperimentController extends Controller
{
    public function index(
        ListExperimentsAction $list,
        ListBenchmarksAction $benchmarks,
        BuildExperimentComparisonAction $comparison,
    ): View {
        Gate::authorize('viewAny', Experiment::class);

        return view('experiments.index', [
            'experiments' => $list->execute(20),
            'benchmarks' => $benchmarks->all(),
            'comparisons' => $comparison->execute(),
            // ADR-0007: Vietnamese reason why new experiments cannot be created here, null when they can.
            'unavailableReason' => CreateExperimentAction::unavailableReason(),
            'defaults' => [
                'model' => (string) config('ait.ai_service.default_model', 'qwen2.5-coder:7b'),
                'temperature' => (float) config('ait.ai_service.default_temperature', 0.2),
                'seed' => (int) config('ait.ai_service.default_seed', 42),
            ],
        ]);
    }

    public function show(Experiment $experiment, ShowExperimentAction $action): View
    {
        Gate::authorize('view', $experiment);

        return view('experiments.show', [
            'experiment' => $action->execute($experiment),
            'generationFailures' => $action->generationFailures($experiment),
            'rows' => BuildExperimentComparisonAction::ROWS,
        ]);
    }
}
