<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Benchmarks\CreateBenchmarkAction;
use App\Actions\Benchmarks\DeleteBenchmarkAction;
use App\Actions\Benchmarks\ListBenchmarksAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Benchmarks\ListBenchmarksRequest;
use App\Http\Requests\Benchmarks\StoreBenchmarkRequest;
use App\Http\Resources\BenchmarkResource;
use App\Models\Benchmark;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class BenchmarkController extends Controller
{
    public function index(ListBenchmarksRequest $request, ListBenchmarksAction $action): JsonResponse
    {
        /** @var array{per_page?: int} $validated */
        $validated = $request->validated();
        $benchmarks = $action->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => collect($benchmarks->items())
                ->map(fn (Benchmark $benchmark): array => (new BenchmarkResource($benchmark))->resolve($request))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $benchmarks->currentPage(),
                'per_page' => $benchmarks->perPage(),
                'total' => $benchmarks->total(),
            ],
        ]);
    }

    public function store(StoreBenchmarkRequest $request, CreateBenchmarkAction $action): JsonResponse
    {
        /** @var array{slug: string, name: string, description?: string|null, base_url: string, version: string} $validated */
        $validated = $request->validated();
        $benchmark = $action->execute($validated);

        return response()->json([
            'data' => (new BenchmarkResource($benchmark))->resolve($request),
        ], Response::HTTP_CREATED);
    }

    public function destroy(Benchmark $benchmark, DeleteBenchmarkAction $action): Response
    {
        Gate::authorize('delete', $benchmark);
        $action->execute($benchmark);

        return response()->noContent();
    }
}
