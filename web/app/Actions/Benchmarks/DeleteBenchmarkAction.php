<?php

namespace App\Actions\Benchmarks;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Benchmark;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a benchmark and its seeded bugs (DELETE /admin/benchmarks/{id}, M14, S14-06), e.g. a
 * duplicate registered by mistake. A benchmark used by an experiment is kept (409 CONFLICT): its
 * results, bug detections and the official numbers must stay traceable.
 */
class DeleteBenchmarkAction
{
    /**
     * @throws ApiException
     */
    public function execute(Benchmark $benchmark): void
    {
        DB::transaction(function () use ($benchmark): void {
            // Lock the row so an experiment cannot be created on it between the check and the delete.
            Benchmark::query()->whereKey($benchmark->id)->lockForUpdate()->first();

            $experiments = $benchmark->experiments()->count();
            if ($experiments > 0) {
                throw new ApiException(
                    ErrorCode::Conflict,
                    "Không thể xóa benchmark '{$benchmark->slug}' phiên bản {$benchmark->version} vì đã có {$experiments} thực nghiệm dùng benchmark này.",
                    ['benchmark_id' => $benchmark->id, 'experiments' => $experiments],
                );
            }

            // benchmark_bugs.benchmark_id cascades.
            $benchmark->delete();
        });
    }
}
