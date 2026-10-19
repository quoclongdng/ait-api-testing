<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\TestCases\TransitionTestCaseStatusAction;
use App\Actions\TestCases\UpdateTestCaseDslAction;
use App\Actions\TestSuites\ListSuiteTestCasesAction;
use App\Enums\TestCaseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\TestCases\ListTestCasesRequest;
use App\Http\Requests\TestCases\UpdateTestCaseRequest;
use App\Http\Resources\TestCaseResource;
use App\Models\TestCase;
use App\Models\TestSuite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Test case lifecycle API (docs/DESIGN.md §4.2): list, view, manual DSL edit, approve, archive.
 */
class TestCaseController extends Controller
{
    public function index(ListTestCasesRequest $request, TestSuite $suite, ListSuiteTestCasesAction $action): JsonResponse
    {
        /** @var array{per_page?: int|string, source?: string, technique?: string, status?: string} $validated */
        $validated = $request->validated();

        $cases = $action->execute($suite, [
            'source' => $validated['source'] ?? null,
            'technique' => $validated['technique'] ?? null,
            'status' => $validated['status'] ?? null,
            'per_page' => (int) ($validated['per_page'] ?? 20),
        ])['cases'];

        return response()->json([
            'data' => collect($cases->items())
                ->map(fn (TestCase $testCase): array => (new TestCaseResource($testCase))->resolve($request))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $cases->currentPage(),
                'per_page' => $cases->perPage(),
                'total' => $cases->total(),
            ],
        ]);
    }

    public function show(Request $request, TestCase $testCase): JsonResponse
    {
        Gate::authorize('view', $testCase);

        return $this->respond($request, $testCase);
    }

    public function update(UpdateTestCaseRequest $request, TestCase $testCase, UpdateTestCaseDslAction $action): JsonResponse
    {
        /** @var array{title?: string, dsl: array<string, mixed>} $validated */
        $validated = $request->validated();

        return $this->respond($request, $action->execute($testCase, $request->dslObject(), $validated['title'] ?? null));
    }

    public function approve(Request $request, TestCase $testCase, TransitionTestCaseStatusAction $action): JsonResponse
    {
        Gate::authorize('approve', $testCase);

        return $this->respond($request, $action->execute($testCase, TestCaseStatus::Approved));
    }

    public function archive(Request $request, TestCase $testCase, TransitionTestCaseStatusAction $action): JsonResponse
    {
        Gate::authorize('archive', $testCase);

        return $this->respond($request, $action->execute($testCase, TestCaseStatus::Archived));
    }

    private function respond(Request $request, TestCase $testCase): JsonResponse
    {
        return response()->json([
            'data' => (new TestCaseResource($testCase))->resolve($request),
        ]);
    }
}
