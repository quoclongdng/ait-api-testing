<?php

namespace App\Actions\Benchmarks;

use App\Data\Experiments\GroundTruthBenchmark;
use App\Exceptions\ApiException;
use App\Models\Benchmark;
use App\Models\BenchmarkBug;
use App\Services\Benchmark\GroundTruthLoader;
use Illuminate\Support\Facades\DB;

/**
 * Synchronises benchmarks and their seeded bugs from `benchmark/<slug>/ground-truth.yaml` (M14).
 *
 * Idempotent: benchmarks are matched by (slug, version) and bugs by (benchmark, code). Bugs that
 * disappeared from the file are kept, because experiment results reference them; they are only
 * reported as stale.
 */
class ImportGroundTruthAction
{
    public function __construct(private readonly GroundTruthLoader $loader) {}

    /**
     * @param  list<string>|null  $slugs  null imports every benchmark found on disk
     * @return list<array{slug: string, benchmark_id: int, created: bool, bugs_created: int, bugs_updated: int, stale_bugs: list<string>}>
     *
     * @throws ApiException
     */
    public function execute(?array $slugs = null): array
    {
        $slugs ??= $this->loader->availableSlugs();

        $documents = array_map(fn (string $slug): GroundTruthBenchmark => $this->loader->load($slug), $slugs);

        return DB::transaction(fn (): array => array_map(fn (GroundTruthBenchmark $doc): array => $this->import($doc), $documents));
    }

    /**
     * @return array{slug: string, benchmark_id: int, created: bool, bugs_created: int, bugs_updated: int, stale_bugs: list<string>}
     */
    private function import(GroundTruthBenchmark $doc): array
    {
        $benchmark = Benchmark::query()->firstOrNew(['slug' => $doc->slug, 'version' => $doc->version]);
        $created = ! $benchmark->exists;
        $benchmark->fill([
            'name' => $doc->name,
            'description' => $doc->description ?? $benchmark->description,
            'base_url' => $created ? $doc->baseUrl : $benchmark->base_url,
        ]);
        $benchmark->save();

        $bugsCreated = 0;
        $bugsUpdated = 0;
        $codes = [];
        foreach ($doc->bugs as $bug) {
            $codes[] = $bug->code;
            $model = BenchmarkBug::query()->firstOrNew(['benchmark_id' => $benchmark->id, 'code' => $bug->code]);
            $isNew = ! $model->exists;
            $model->fill([
                'method' => $bug->method,
                'path' => $bug->path,
                'category' => $bug->category,
                'description' => $bug->description,
                'toggle_flag' => $bug->toggleFlag,
            ]);
            if ($isNew) {
                $model->save();
                $bugsCreated++;
            } elseif ($model->isDirty()) {
                $model->save();
                $bugsUpdated++;
            }
        }

        /** @var list<string> $stale */
        $stale = $benchmark->bugs()->whereNotIn('code', $codes)->orderBy('code')->pluck('code')->map(fn (mixed $c): string => (string) $c)->values()->all();

        return [
            'slug' => $doc->slug,
            'benchmark_id' => $benchmark->id,
            'created' => $created,
            'bugs_created' => $bugsCreated,
            'bugs_updated' => $bugsUpdated,
            'stale_bugs' => $stale,
        ];
    }
}
