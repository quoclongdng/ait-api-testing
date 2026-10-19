<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Generation\StartGenerationJobAction;
use App\Actions\Generation\SummarizeGenerationJobAction;
use App\Data\Generation\GenerationJobInput;
use App\Http\Controllers\Controller;
use App\Http\Requests\Generation\CreateGenerationJobRequest;
use App\Http\Resources\GenerationJobResource;
use App\Models\GenerationJob;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class GenerationJobController extends Controller
{
    public function store(
        CreateGenerationJobRequest $request,
        TestSuite $suite,
        StartGenerationJobAction $action,
    ): JsonResponse {
        /** @var array{strategy: string, params: array<string, mixed>} $validated */
        $validated = $request->validated();
        /** @var User $user */
        $user = $request->user();
        $generationJob = $action->execute(
            $user,
            $suite,
            GenerationJobInput::fromArray($validated['strategy'], $validated['params']),
        );

        return response()->json([
            'data' => (new GenerationJobResource($generationJob->refresh()))->resolve($request),
        ], Response::HTTP_ACCEPTED);
    }

    public function show(Request $request, GenerationJob $generationJob, SummarizeGenerationJobAction $summarize): JsonResponse
    {
        Gate::authorize('view', $generationJob);

        return response()->json([
            'data' => (new GenerationJobResource($generationJob, $summarize->execute($generationJob)))->resolve($request),
        ]);
    }
}
