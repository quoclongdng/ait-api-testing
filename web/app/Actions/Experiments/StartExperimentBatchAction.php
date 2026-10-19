<?php

namespace App\Actions\Experiments;

use App\Enums\ErrorCode;
use App\Enums\GenerationStrategy;
use App\Exceptions\ApiException;
use App\Models\Benchmark;
use App\Models\Experiment;
use App\Models\User;
use App\Services\Experiments\ExperimentSeedPlanner;

/**
 * Plans and queues one experiment per (benchmark × strategy) for `make experiment`
 * (`php artisan ait:experiments:run`, S11-02). Every experiment goes through
 * CreateExperimentAction, exactly like M15.
 */
class StartExperimentBatchAction
{
    public function __construct(
        private readonly CreateExperimentAction $create,
        private readonly ExperimentSeedPlanner $seedPlanner,
    ) {}

    /**
     * @param  list<string>  $strategies  RULE | LLM | HYBRID
     * @param  list<string>|null  $slugs  null = every benchmark (latest version of each slug)
     * @param  list<int>|null  $seeds  per-repetition seeds (null = protocol EXPERIMENT_SEEDS)
     * @return list<Experiment>
     *
     * @throws ApiException
     */
    public function execute(
        User $user,
        array $strategies,
        ?array $slugs,
        int $repetitions,
        ?string $model,
        float $temperature,
        ?array $seeds = null,
        string $namePrefix = 'make experiment',
    ): array {
        $parsedStrategies = [];
        foreach ($strategies as $strategy) {
            $value = GenerationStrategy::tryFrom(strtoupper(trim($strategy)));
            if ($value === null) {
                throw new ApiException(ErrorCode::ValidationFailed, "Chiến lược không hợp lệ: {$strategy}.", ['strategies' => ['Chỉ nhận RULE, LLM, HYBRID.']]);
            }
            $parsedStrategies[$value->value] = $value;
        }
        if ($parsedStrategies === []) {
            throw new ApiException(ErrorCode::ValidationFailed, 'Cần ít nhất một chiến lược.', ['strategies' => ['Cần ít nhất một chiến lược.']]);
        }
        if ($repetitions < 3 || $repetitions > 10) {
            throw new ApiException(ErrorCode::ValidationFailed, 'Số lần lặp phải từ 3 đến 10 (docs/DESIGN.md §11).', ['repetitions' => ['Số lần lặp phải từ 3 đến 10.']]);
        }
        if ($temperature < 0 || $temperature > 2) {
            throw new ApiException(ErrorCode::ValidationFailed, 'Temperature phải trong khoảng 0..2.', ['temperature' => ['Temperature phải trong khoảng 0..2.']]);
        }

        $benchmarks = $this->benchmarks($slugs);
        $firstSeed = $seeds !== null && $seeds !== [] ? $seeds[0] : $this->seedPlanner->protocol()[0];

        $experiments = [];
        foreach ($benchmarks as $benchmark) {
            foreach ($parsedStrategies as $strategy) {
                $experiments[] = $this->create->execute($user, [
                    // Same name for every strategy of one benchmark, like the M15 form, so
                    // BuildExperimentComparisonAction groups RULE/LLM/HYBRID into one table.
                    'name' => mb_substr("{$namePrefix} · {$benchmark->slug}", 0, 255),
                    'benchmark_id' => $benchmark->id,
                    'strategy' => $strategy->value,
                    'config' => [
                        'model' => $model,
                        'seed' => $firstSeed,
                        'seeds' => $seeds,
                        'temperature' => $temperature,
                        'repetitions' => $repetitions,
                    ],
                ]);
            }
        }

        return $experiments;
    }

    /**
     * @param  list<string>|null  $slugs
     * @return list<Benchmark>
     *
     * @throws ApiException
     */
    private function benchmarks(?array $slugs): array
    {
        $latest = [];
        foreach (Benchmark::query()->orderBy('slug')->orderBy('id')->get() as $benchmark) {
            $latest[$benchmark->slug] = $benchmark; // highest id = most recently registered version
        }

        if ($slugs === null) {
            if ($latest === []) {
                throw new ApiException(ErrorCode::NotFound, 'Chưa có benchmark nào; hãy chạy php artisan ait:benchmarks:import trước.');
            }

            return array_values($latest);
        }

        $selected = [];
        foreach ($slugs as $slug) {
            if (! isset($latest[$slug])) {
                throw new ApiException(ErrorCode::NotFound, "Không tìm thấy benchmark '{$slug}'.", ['benchmarks' => [$slug]]);
            }
            $selected[$slug] = $latest[$slug];
        }

        return array_values($selected);
    }
}
