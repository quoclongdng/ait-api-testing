<?php

namespace App\Actions\Experiments;

use App\Enums\GenerationStrategy;
use App\Models\Benchmark;
use App\Models\Experiment;
use App\Models\ExperimentBugDetection;

/**
 * Builds the RULE / LLM / HYBRID comparison of M15.
 *
 * Experiments started together share a name and a benchmark (the M15 form creates one experiment
 * per selected strategy), so they are grouped by (benchmark, name). Within a group the newest
 * experiment of each strategy is shown with mean ± stddev over its repetitions, plus the D14 bug
 * matrix per repetition.
 */
class BuildExperimentComparisonAction
{
    /** Metric rows of the comparison table: key => [label, format]. Vietnamese label, §11 term in parentheses (S14-06). */
    public const ROWS = [
        'validity_rate' => ['Tỉ lệ hợp lệ (Validity Rate)', 'rate'],
        'executable_rate' => ['Tỉ lệ thực thi được (Executable Rate)', 'rate'],
        'false_positive_rate' => ['Tỉ lệ dương tính giả (False Positive Rate)', 'rate'],
        'bug_detection_rate' => ['Tỉ lệ phát hiện bug (Bug Detection Rate)', 'rate'],
        'mutation_score' => ['Tỉ lệ biến thể bị phát hiện (Mutation Score)', 'rate'],
        'endpoint_coverage' => ['Độ phủ endpoint (Endpoint Coverage)', 'rate'],
        'status_code_coverage' => ['Độ phủ mã trạng thái (Status Code Coverage)', 'rate'],
        'redundancy' => ['Tỉ lệ trùng lặp (Redundancy)', 'rate'],
        'generation_time_ms' => ['Thời gian sinh trung bình', 'ms'],
        'execution_time_ms' => ['Thời gian chạy trung bình', 'ms'],
        'prompt_tokens' => ['Token đầu vào (prompt)', 'tokens'],
        'completion_tokens' => ['Token đầu ra (completion)', 'tokens'],
    ];

    /**
     * @return list<array{
     *     name: string,
     *     benchmark: Benchmark,
     *     config: array<string, mixed>,
     *     experiments: array<string, Experiment|null>,
     *     rows: list<array{key: string, label: string, format: string, cells: array<string, array{mean: float|int|null, stddev: float|null}>}>,
     *     bug_matrix: list<array{code: string, category: string, cells: array<string, list<bool>|null>}>
     * }>
     */
    public function execute(int $maxGroups = 10): array
    {
        $experiments = Experiment::query()
            ->with(['benchmark.bugs' => fn ($query) => $query->orderBy('code'), 'bugDetections'])
            ->orderByDesc('id')
            ->limit(300)
            ->get();

        /** @var array<string, list<Experiment>> $groups */
        $groups = [];
        foreach ($experiments as $experiment) {
            $groups[$experiment->benchmark_id.'|'.$experiment->name][] = $experiment;
        }

        $result = [];
        foreach (array_slice($groups, 0, $maxGroups) as $members) {
            $result[] = $this->buildGroup($members);
        }

        return $result;
    }

    /**
     * @param  list<Experiment>  $members  newest first
     * @return array{
     *     name: string,
     *     benchmark: Benchmark,
     *     config: array<string, mixed>,
     *     experiments: array<string, Experiment|null>,
     *     rows: list<array{key: string, label: string, format: string, cells: array<string, array{mean: float|int|null, stddev: float|null}>}>,
     *     bug_matrix: list<array{code: string, category: string, cells: array<string, list<bool>|null>}>
     * }
     */
    private function buildGroup(array $members): array
    {
        /** @var array<string, Experiment|null> $byStrategy */
        $byStrategy = [];
        foreach (GenerationStrategy::cases() as $strategy) {
            $byStrategy[$strategy->value] = null;
        }
        foreach ($members as $experiment) {
            $byStrategy[$experiment->strategy->value] ??= $experiment;
        }

        $rows = [];
        foreach (self::ROWS as $key => [$label, $format]) {
            $cells = [];
            foreach ($byStrategy as $strategy => $experiment) {
                $metrics = $experiment?->metrics;
                $mean = is_array($metrics) && is_numeric($metrics[$key] ?? null) ? $metrics[$key] + 0 : null;
                $stddev = is_array($metrics) && is_array($metrics['stddev'] ?? null) && is_numeric($metrics['stddev'][$key] ?? null)
                    ? (float) $metrics['stddev'][$key]
                    : null;
                $cells[$strategy] = ['mean' => $mean, 'stddev' => $stddev];
            }
            $rows[] = ['key' => $key, 'label' => $label, 'format' => $format, 'cells' => $cells];
        }

        $first = $members[0];
        $bugMatrix = [];
        foreach ($first->benchmark->bugs as $bug) {
            $cells = [];
            foreach ($byStrategy as $strategy => $experiment) {
                if ($experiment === null || $experiment->bugDetections->isEmpty()) {
                    $cells[$strategy] = null;

                    continue;
                }
                $cells[$strategy] = array_values($experiment->bugDetections
                    ->where('benchmark_bug_id', $bug->id)
                    ->sortBy('repetition')
                    ->map(fn (ExperimentBugDetection $d): bool => $d->detected)
                    ->all());
            }
            $bugMatrix[] = ['code' => $bug->code, 'category' => $bug->category->value, 'cells' => $cells];
        }

        return [
            'name' => $first->name,
            'benchmark' => $first->benchmark,
            'config' => $first->config,
            'experiments' => $byStrategy,
            'rows' => $rows,
            'bug_matrix' => $bugMatrix,
        ];
    }
}
