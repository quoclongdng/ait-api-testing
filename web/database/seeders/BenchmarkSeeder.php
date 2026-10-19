<?php

namespace Database\Seeders;

use App\Actions\Benchmarks\ImportGroundTruthAction;
use App\Exceptions\ApiException;
use Illuminate\Database\Seeder;

/**
 * Seeds benchmarks and their seeded bugs from benchmark/<slug>/ground-truth.yaml (M14, D13).
 * Skips silently when the benchmark directory is not mounted.
 */
class BenchmarkSeeder extends Seeder
{
    public function run(ImportGroundTruthAction $action): void
    {
        try {
            $action->execute();
        } catch (ApiException $e) {
            logger()->warning('Bỏ qua seed benchmark: '.$e->getMessage());
        }
    }
}
