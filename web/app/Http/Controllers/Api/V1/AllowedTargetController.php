<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AllowedTargets\CreateAllowedTargetAction;
use App\Actions\AllowedTargets\DeleteAllowedTargetAction;
use App\Actions\AllowedTargets\ListAllowedTargetsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\AllowedTargets\StoreAllowedTargetRequest;
use App\Http\Resources\AllowedTargetResource;
use App\Models\AllowedTarget;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class AllowedTargetController extends Controller
{
    public function index(Request $request, ListAllowedTargetsAction $action): JsonResponse
    {
        Gate::authorize('viewAny', AllowedTarget::class);

        $data = $action->execute()
            ->map(fn (AllowedTarget $target): array => (new AllowedTargetResource($target))->resolve($request))
            ->values()
            ->all();

        return response()->json(['data' => $data]);
    }

    public function store(StoreAllowedTargetRequest $request, CreateAllowedTargetAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{host: string, port?: int|null, note?: string|null} $validated */
        $validated = $request->validated();
        $target = $action->execute($user, $validated);

        return response()->json([
            'data' => (new AllowedTargetResource($target))->resolve($request),
        ], Response::HTTP_CREATED);
    }

    public function destroy(Request $request, AllowedTarget $allowedTarget, DeleteAllowedTargetAction $action): Response
    {
        Gate::authorize('delete', $allowedTarget);
        $action->execute($allowedTarget);

        return response()->noContent();
    }
}
