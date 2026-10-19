<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\TestSuites\CreateTestSuiteAction;
use App\Actions\TestSuites\ListTestSuitesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\ProjectPageRequest;
use App\Http\Requests\TestSuites\StoreTestSuiteRequest;
use App\Http\Resources\TestSuiteResource;
use App\Models\Project;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test suites of a project over /api/v1 (web-api.openapi.yaml, tag "Test suites").
 */
class TestSuiteController extends Controller
{
    public function index(ProjectPageRequest $request, Project $project, ListTestSuitesAction $action): JsonResponse
    {
        Gate::authorize('viewAny', [TestSuite::class, $project]);
        /** @var array{per_page?: int} $validated */
        $validated = $request->validated();
        $suites = $action->execute($project, (int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => collect($suites->items())
                ->map(fn (TestSuite $suite): array => (new TestSuiteResource($suite))->resolve($request))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $suites->currentPage(),
                'per_page' => $suites->perPage(),
                'total' => $suites->total(),
            ],
        ]);
    }

    public function store(StoreTestSuiteRequest $request, Project $project, CreateTestSuiteAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{api_spec_id: int, name: string, description?: string|null} $validated */
        $validated = $request->validated();

        return response()->json([
            'data' => (new TestSuiteResource($action->execute($user, $project, $validated)))->resolve($request),
        ], Response::HTTP_CREATED);
    }

    public function show(Request $request, TestSuite $suite): JsonResponse
    {
        Gate::authorize('view', $suite);

        return response()->json([
            'data' => (new TestSuiteResource($suite))->resolve($request),
        ]);
    }
}
