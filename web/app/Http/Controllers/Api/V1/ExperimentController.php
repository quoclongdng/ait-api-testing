<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Experiments\CreateExperimentAction;
use App\Actions\Experiments\ExportExperimentsAction;
use App\Actions\Experiments\ListExperimentsAction;
use App\Actions\Experiments\ShowExperimentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Experiments\ExportExperimentRequest;
use App\Http\Requests\Experiments\ListExperimentsRequest;
use App\Http\Requests\Experiments\StoreExperimentRequest;
use App\Http\Resources\ExperimentResource;
use App\Models\Experiment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class ExperimentController extends Controller
{
    public function index(ListExperimentsRequest $request, ListExperimentsAction $action): JsonResponse
    {
        /** @var array{per_page?: int} $validated */
        $validated = $request->validated();
        $experiments = $action->execute((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => collect($experiments->items())
                ->map(fn (Experiment $experiment): array => (new ExperimentResource($experiment))->resolve($request))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $experiments->currentPage(),
                'per_page' => $experiments->perPage(),
                'total' => $experiments->total(),
            ],
        ]);
    }

    public function store(StoreExperimentRequest $request, CreateExperimentAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{name: string, benchmark_id: int, strategy: string, config: array{model?: string|null, seed: int, seeds?: list<int>|null, temperature: float|int|string, repetitions: int}} $validated */
        $validated = $request->validated();
        $experiment = $action->execute($user, $validated);

        return response()->json([
            'data' => (new ExperimentResource($experiment->refresh()))->resolve($request),
        ], Response::HTTP_ACCEPTED);
    }

    public function show(Request $request, Experiment $experiment, ShowExperimentAction $action): JsonResponse
    {
        Gate::authorize('view', $experiment);

        return response()->json([
            'data' => (new ExperimentResource($action->execute($experiment)))->resolve($request),
        ]);
    }

    public function export(ExportExperimentRequest $request, Experiment $experiment, ExportExperimentsAction $action): HttpResponse
    {
        $file = $action->single($experiment, $request->exportFormat());

        return response($file->content, Response::HTTP_OK, [
            'Content-Type' => $file->contentType,
            'Content-Disposition' => 'attachment; filename="'.$file->filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
