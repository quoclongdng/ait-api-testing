<?php

namespace App\Actions\Benchmarks;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Benchmark;
use App\Services\Benchmark\GroundTruthLoader;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registers a benchmark (POST /admin/benchmarks). When `benchmark/<slug>/ground-truth.yaml`
 * exists its seeded bugs are attached right away, so the benchmark is usable for experiments.
 *
 * The version is normalised (Benchmark::normalizeVersion) before the uniqueness check, so "1.0" and
 * "V1.0" are the same benchmark as the imported "v1.0" (409). A slug that has a ground truth can only
 * be registered at the ground-truth version: any other version would get a copy of the same bugs
 * under another name (422 on `version`, S14-06). A new version comes from editing ground-truth.yaml
 * and synchronising it on M14.
 */
class CreateBenchmarkAction
{
    public function __construct(private readonly GroundTruthLoader $loader) {}

    /**
     * @param  array{slug: string, name: string, description?: string|null, base_url: string, version: string}  $data
     *
     * @throws ApiException
     * @throws ValidationException
     */
    public function execute(array $data): Benchmark
    {
        $version = Benchmark::normalizeVersion($data['version']);

        // Rows written before the normalisation may still hold another spelling ("1.0").
        $exists = Benchmark::query()
            ->where('slug', $data['slug'])
            ->pluck('version')
            ->contains(fn (mixed $stored): bool => Benchmark::normalizeVersion((string) $stored) === $version);
        if ($exists) {
            throw new ApiException(
                ErrorCode::Conflict,
                "Benchmark '{$data['slug']}' phiên bản {$version} đã tồn tại.",
                ['slug' => $data['slug'], 'version' => $version]
            );
        }

        $groundTruth = in_array($data['slug'], $this->loader->availableSlugs(), true)
            ? $this->loader->load($data['slug'])
            : null;

        if ($groundTruth !== null && $groundTruth->version !== $version) {
            throw ValidationException::withMessages([
                'version' => "Ground truth của benchmark '{$data['slug']}' là phiên bản {$groundTruth->version}, nên chỉ đăng ký được phiên bản này. Muốn thêm phiên bản mới, sửa benchmark/{$data['slug']}/ground-truth.yaml rồi bấm “Đồng bộ từ ground-truth.yaml”.",
            ]);
        }

        return DB::transaction(function () use ($data, $version, $groundTruth): Benchmark {
            $benchmark = Benchmark::create([
                'slug' => $data['slug'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'base_url' => rtrim($data['base_url'], '/'),
                'version' => $version,
            ]);

            foreach ($groundTruth->bugs ?? [] as $bug) {
                $benchmark->bugs()->create([
                    'code' => $bug->code,
                    'method' => $bug->method,
                    'path' => $bug->path,
                    'category' => $bug->category,
                    'description' => $bug->description,
                    'toggle_flag' => $bug->toggleFlag,
                ]);
            }

            return $benchmark->loadCount('bugs');
        });
    }
}
