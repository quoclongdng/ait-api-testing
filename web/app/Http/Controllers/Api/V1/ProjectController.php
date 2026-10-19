<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Projects\CreateProjectAction;
use App\Actions\Projects\DeleteProjectAction;
use App\Actions\Projects\ListProjectsAction;
use App\Actions\Projects\ShowProjectAction;
use App\Actions\Projects\UpdateProjectAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\ListProjectsRequest;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class ProjectController extends Controller
{
    public function index(ListProjectsRequest $request, ListProjectsAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{per_page?: int} $validated */
        $validated = $request->validated();
        $projects = $action->execute($user, $validated['per_page'] ?? 20);

        $data = collect($projects->items())
            ->map(fn (Project $project): array => (new ProjectResource($project))->resolve($request))
            ->values()
            ->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $projects->currentPage(),
                'per_page' => $projects->perPage(),
                'total' => $projects->total(),
            ],
        ]);
    }

    public function store(StoreProjectRequest $request, CreateProjectAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{name: string, description?: string|null, default_target_url?: string|null} $validated */
        $validated = $request->validated();
        $project = $action->execute($user, $validated);

        return response()->json([
            'data' => (new ProjectResource($project))->resolve($request),
        ], Response::HTTP_CREATED);
    }

    public function show(Request $request, Project $project, ShowProjectAction $action): JsonResponse
    {
        Gate::authorize('view', $project);
        $project = $action->execute($project);

        return response()->json([
            'data' => (new ProjectResource($project))->resolve($request),
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project, UpdateProjectAction $action): JsonResponse
    {
        /** @var array{name?: string, description?: string|null, default_target_url?: string|null} $validated */
        $validated = $request->validated();
        $project = $action->execute($project, $validated);

        return response()->json([
            'data' => (new ProjectResource($project))->resolve($request),
        ]);
    }

    public function destroy(Request $request, Project $project, DeleteProjectAction $action): Response
    {
        Gate::authorize('delete', $project);
        $action->execute($project);

        return response()->noContent();
    }
}
