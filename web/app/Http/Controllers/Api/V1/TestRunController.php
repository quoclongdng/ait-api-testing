<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Runs\CancelTestRunAction;
use App\Actions\Runs\CreateTestRunAction;
use App\Actions\Runs\ExportRunReportAction;
use App\Actions\Runs\ListTestResultsAction;
use App\Actions\Runs\ShowTestRunAction;
use App\Enums\TestVerdict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Runs\ExportRunReportRequest;
use App\Http\Requests\Runs\ListTestResultsRequest;
use App\Http\Requests\Runs\StoreTestRunRequest;
use App\Http\Resources\TestResultResource;
use App\Http\Resources\TestRunResource;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class TestRunController extends Controller
{
    public function store(StoreTestRunRequest $request, TestSuite $suite, CreateTestRunAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{target_base_url: string, benchmark_variant?: string|null, test_case_ids?: list<int>|null} $validated */
        $validated = $request->validated();

        $testRun = $action->execute($user, $suite, $validated);

        return response()->json([
            'data' => (new TestRunResource($testRun))->resolve($request),
        ], Response::HTTP_ACCEPTED);
    }

    public function show(Request $request, TestRun $run, ShowTestRunAction $action): JsonResponse
    {
        Gate::authorize('view', $run);

        return response()->json([
            'data' => (new TestRunResource($action->execute($run)))->resolve($request),
        ]);
    }

    public function results(ListTestResultsRequest $request, TestRun $run, ListTestResultsAction $action): JsonResponse
    {
        /** @var array{per_page?: int|string, verdict?: string} $validated */
        $validated = $request->validated();

        $results = $action->execute(
            $run,
            (int) ($validated['per_page'] ?? 20),
            isset($validated['verdict']) ? TestVerdict::from($validated['verdict']) : null,
        );

        return response()->json([
            'data' => collect($results->items())
                ->map(fn (TestResult $result): array => (new TestResultResource($result))->resolve($request))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $results->currentPage(),
                'per_page' => $results->perPage(),
                'total' => $results->total(),
            ],
        ]);
    }

    public function cancel(Request $request, TestRun $run, CancelTestRunAction $action): JsonResponse
    {
        Gate::authorize('cancel', $run);

        return response()->json([
            'data' => (new TestRunResource($action->execute($run)))->resolve($request),
        ]);
    }

    public function export(ExportRunReportRequest $request, TestRun $run, ExportRunReportAction $action): HttpResponse
    {
        $file = $action->execute($run, $request->reportFormat());

        return response($file->content, Response::HTTP_OK, [
            'Content-Type' => $file->contentType,
            'Content-Disposition' => 'attachment; filename="'.$file->filename.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
        ]);
    }
}
