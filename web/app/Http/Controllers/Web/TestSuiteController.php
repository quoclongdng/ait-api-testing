<?php

namespace App\Http\Controllers\Web;

use App\Actions\Generation\ListRecentSuiteGenerationJobsAction;
use App\Actions\Runs\ListRecentSuiteRunsAction;
use App\Actions\TestCases\BulkTransitionTestCasesAction;
use App\Actions\TestSuites\CreateTestSuiteAction;
use App\Actions\TestSuites\DeleteTestSuiteAction;
use App\Actions\TestSuites\ListSuiteTestCasesAction;
use App\Actions\TestSuites\ListTestSuitesAction;
use App\Enums\ApiSpecStatus;
use App\Enums\TestCaseStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\TestSuites\BulkTestCaseActionRequest;
use App\Http\Requests\TestSuites\StoreTestSuiteRequest;
use App\Http\Requests\TestSuites\TestSuiteIndexRequest;
use App\Models\Project;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class TestSuiteController extends Controller
{
    public function index(Project $project, ListTestSuitesAction $action): View
    {
        Gate::authorize('viewAny', [TestSuite::class, $project]);

        return view('suites.index', [
            'project' => $project,
            'suites' => $action->execute($project),
            'apiSpecs' => $project->apiSpecs()->where('status', ApiSpecStatus::Parsed)->latest('id')->get(),
        ]);
    }

    public function store(
        StoreTestSuiteRequest $request,
        Project $project,
        CreateTestSuiteAction $action
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        /** @var array{api_spec_id: int, name: string, description?: string|null} $validated */
        $validated = $request->validated();

        $suite = $action->execute($user, $project, $validated);

        return redirect()->route('suites.show', $suite)
            ->with('success', "Đã tạo bộ kiểm thử {$suite->name} thành công.");
    }

    public function show(
        TestSuiteIndexRequest $request,
        TestSuite $suite,
        ListSuiteTestCasesAction $action,
        ListRecentSuiteRunsAction $recentRunsAction,
        ListRecentSuiteGenerationJobsAction $recentJobsAction
    ): View {
        $suite->load(['project', 'apiSpec.endpoints', 'creator']);

        /** @var array{search?: string|null, source?: string|null, technique?: string|null, status?: string|null, per_page?: int|null} $validated */
        $validated = $request->validated();

        $result = $action->execute($suite, $validated);

        return view('suites.show', [
            'suite' => $suite,
            'testCases' => $result['cases'],
            'stats' => $result['stats'],
            'recentRuns' => $recentRunsAction->execute($suite),
            'recentGenerationJobs' => $recentJobsAction->execute($suite),
            'filters' => [
                'search' => $validated['search'] ?? '',
                'source' => $validated['source'] ?? '',
                'technique' => $validated['technique'] ?? '',
                'status' => $validated['status'] ?? '',
            ],
        ]);
    }

    public function destroy(TestSuite $suite, DeleteTestSuiteAction $action): RedirectResponse
    {
        Gate::authorize('delete', $suite);

        $project = $suite->project;
        $name = $suite->name;

        try {
            $action->execute($suite);
        } catch (ApiException $e) {
            return redirect()->route('suites.show', $suite)->with('error', $e->getMessage());
        }

        return redirect()->route('projects.suites.index', $project)
            ->with('success', "Đã xóa bộ kiểm thử {$name}.");
    }

    public function bulkAction(
        BulkTestCaseActionRequest $request,
        TestSuite $suite,
        BulkTransitionTestCasesAction $action
    ): RedirectResponse {
        /** @var array{action: string, test_case_ids: list<int>} $validated */
        $validated = $request->validated();

        $ids = array_map('intval', $validated['test_case_ids']);

        if ($validated['action'] === 'approve') {
            $count = $action->execute($suite, $ids, TestCaseStatus::Approved);

            // Nothing changed is not a success (S14-06): only VALID cases can be approved.
            return $count === 0
                ? redirect()->route('suites.show', $suite)
                    ->with('error', 'Không có test case nào thay đổi: chỉ duyệt được test case ở trạng thái VALID.')
                : redirect()->route('suites.show', $suite)
                    ->with('success', "Đã duyệt thành công {$count} test case.");
        }

        $count = $action->execute($suite, $ids, TestCaseStatus::Archived);

        return $count === 0
            ? redirect()->route('suites.show', $suite)
                ->with('error', 'Không có test case nào thay đổi: chỉ lưu trữ được test case ở trạng thái VALID hoặc APPROVED.')
            : redirect()->route('suites.show', $suite)
                ->with('success', "Đã lưu trữ thành công {$count} test case.");
    }
}
