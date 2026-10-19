<?php

namespace App\Http\Controllers;

use App\Actions\Experiments\CreateExperimentAction;
use App\Actions\Projects\CountProjectsAction;
use App\Actions\Projects\FindLatestProjectAction;
use App\Actions\Runs\ComputeRecentPassRateAction;
use App\Actions\Runs\ListRecentRunsAction;
use App\Actions\System\BuildAdminOverviewAction;
use App\Actions\System\CheckHealthAction;
use App\Actions\TestCases\CountApprovedTestCasesAction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Show the dashboard of the signed-in actor (M02): an ADMIN gets the system-wide
     * "Tổng quan quản trị" (S14-04), a TESTER the personal overview.
     */
    public function index(
        Request $request,
        CheckHealthAction $checkHealthAction,
        CountProjectsAction $countProjectsAction,
        CountApprovedTestCasesAction $countApprovedTestCasesAction,
        ComputeRecentPassRateAction $computeRecentPassRateAction,
        ListRecentRunsAction $listRecentRunsAction,
        FindLatestProjectAction $findLatestProjectAction,
        BuildAdminOverviewAction $buildAdminOverviewAction,
    ): View {
        /** @var User $user */
        $user = $request->user();
        // EXPERIMENTS_ENABLED=false (ADR-0007): no "run experiment" quick action.
        $experimentsAvailable = CreateExperimentAction::unavailableReason() === null;

        if ($user->isAdmin()) {
            return view('admin.dashboard', [
                'overview' => $buildAdminOverviewAction->execute($user),
                'experimentsAvailable' => $experimentsAvailable,
            ]);
        }

        $passRate = $computeRecentPassRateAction->execute($user);

        return view('dashboard', [
            'stats' => [
                'projects_count' => $countProjectsAction->execute($user),
                'approved_test_cases' => $countApprovedTestCasesAction->execute($user),
                'pass_rate_7d' => $passRate === null ? 'N/A' : number_format($passRate * 100, 1, ',', '.').'%',
                'health' => $checkHealthAction->execute(),
            ],
            'recentRuns' => $listRecentRunsAction->execute($user),
            // "Tải lên OpenAPI" opens the M06 screen of the latest project (the list when there is none).
            'latestProject' => $findLatestProjectAction->execute($user),
            'experimentsAvailable' => $experimentsAvailable,
        ]);
    }
}
