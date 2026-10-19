<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\BusinessRules\CreateBusinessRuleAction;
use App\Actions\BusinessRules\DeleteBusinessRuleAction;
use App\Actions\BusinessRules\ListBusinessRulesAction;
use App\Actions\BusinessRules\UpdateBusinessRuleAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessRules\PatchBusinessRuleRequest;
use App\Http\Requests\BusinessRules\StoreBusinessRuleRequest;
use App\Http\Requests\Projects\ProjectPageRequest;
use App\Http\Resources\BusinessRuleResource;
use App\Models\BusinessRule;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Business rules of a project (M08) over /api/v1 (web-api.openapi.yaml, tag "Business rules").
 */
class BusinessRuleController extends Controller
{
    public function index(ProjectPageRequest $request, Project $project, ListBusinessRulesAction $action): JsonResponse
    {
        Gate::authorize('viewAny', [BusinessRule::class, $project]);
        /** @var array{per_page?: int} $validated */
        $validated = $request->validated();
        $rules = $action->execute($project, (int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => collect($rules->items())
                ->map(fn (BusinessRule $rule): array => (new BusinessRuleResource($rule))->resolve($request))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $rules->currentPage(),
                'per_page' => $rules->perPage(),
                'total' => $rules->total(),
            ],
        ]);
    }

    public function store(StoreBusinessRuleRequest $request, Project $project, CreateBusinessRuleAction $action): JsonResponse
    {
        /** @var array{code: string, endpoint_id?: int|null, description: string} $validated */
        $validated = $request->validated();

        return response()->json([
            'data' => (new BusinessRuleResource($action->execute($project, $validated)))->resolve($request),
        ], Response::HTTP_CREATED);
    }

    public function show(Request $request, BusinessRule $businessRule): JsonResponse
    {
        Gate::authorize('view', $businessRule);

        return response()->json([
            'data' => (new BusinessRuleResource($businessRule))->resolve($request),
        ]);
    }

    public function update(PatchBusinessRuleRequest $request, BusinessRule $businessRule, UpdateBusinessRuleAction $action): JsonResponse
    {
        /** @var array{code?: string, endpoint_id?: int|null, description?: string} $validated */
        $validated = $request->validated();

        return response()->json([
            'data' => (new BusinessRuleResource($action->execute($businessRule, $validated)))->resolve($request),
        ]);
    }

    public function destroy(BusinessRule $businessRule, DeleteBusinessRuleAction $action): Response
    {
        Gate::authorize('delete', $businessRule);
        $action->execute($businessRule);

        return response()->noContent();
    }
}
