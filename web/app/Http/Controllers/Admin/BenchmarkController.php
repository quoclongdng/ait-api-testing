<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Benchmarks\DeleteBenchmarkAction;
use App\Actions\Benchmarks\DescribeBenchmarkFixturesAction;
use App\Actions\Benchmarks\ImportGroundTruthAction;
use App\Actions\Benchmarks\ListBenchmarksAction;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Benchmark;
use App\Services\Benchmark\GroundTruthLoader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * M14 — Quản lý Benchmark & Bug Ground Truth (ADMIN).
 */
class BenchmarkController extends Controller
{
    public function index(ListBenchmarksAction $action, GroundTruthLoader $loader, DescribeBenchmarkFixturesAction $fixtures): View
    {
        Gate::authorize('viewAny', Benchmark::class);

        $benchmarks = $action->all();

        return view('admin.benchmarks.index', [
            'benchmarks' => $benchmarks,
            'benchmarkNumbers' => $action->numbers($benchmarks),
            'availableSlugs' => $loader->availableSlugs(),
            'variantTemplate' => (string) config('ait.benchmark.variant_url_template', ''),
            'sandboxDriver' => (string) config('ait.benchmark.sandbox_driver', 'url_template'),
            // ADR-0006: auth roles (no token) and business rules snapshotted by new experiments.
            'fixtures' => $fixtures->execute(),
        ]);
    }

    public function import(ImportGroundTruthAction $action): RedirectResponse
    {
        Gate::authorize('import', Benchmark::class);

        try {
            $results = $action->execute();
        } catch (ApiException $exception) {
            return redirect()->route('admin.benchmarks.index')->with('error', $exception->getMessage());
        }

        if ($results === []) {
            return redirect()->route('admin.benchmarks.index')
                ->with('warning', 'Không tìm thấy tệp ground-truth.yaml nào trong thư mục benchmark.');
        }

        $bugs = array_sum(array_map(fn (array $r): int => $r['bugs_created'] + $r['bugs_updated'], $results));

        $redirect = redirect()->route('admin.benchmarks.index')
            ->with('success', 'Đã đồng bộ '.count($results)." benchmark từ ground truth ({$bugs} bug được thêm/cập nhật).");

        // Bugs removed from ground-truth.yaml stay in the DB for history but are skipped by experiments.
        $stale = [];
        foreach ($results as $result) {
            foreach ($result['stale_bugs'] as $code) {
                $stale[] = "{$result['slug']}/{$code}";
            }
        }
        if ($stale !== []) {
            $redirect->with('warning', 'Các bug không còn trong ground-truth.yaml (giữ lại để tra cứu, thực nghiệm sẽ bỏ qua): '.implode(', ', $stale).'.');
        }

        return $redirect;
    }

    /**
     * Delete a benchmark no experiment uses, e.g. a duplicate registered by mistake (S14-06).
     */
    public function destroy(Benchmark $benchmark, DeleteBenchmarkAction $action): RedirectResponse
    {
        Gate::authorize('delete', $benchmark);
        $label = "{$benchmark->name} ({$benchmark->version})";

        try {
            $action->execute($benchmark);
        } catch (ApiException $exception) {
            return redirect()->route('admin.benchmarks.index')->with('error', $exception->getMessage());
        }

        return redirect()->route('admin.benchmarks.index')
            ->with('success', "Đã xóa benchmark {$label} cùng các seeded bug của nó.");
    }
}
