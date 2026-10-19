<?php

namespace App\Services\Experiments;

use App\Enums\ExperimentStatus;
use App\Models\Experiment;
use App\Services\Reports\RunReportExporter;
use App\Support\MetricFormat;
use RuntimeException;

/**
 * Summary table of the §11 metrics for `make experiment` (S12-02): one row per
 * (benchmark × strategy × model × temperature), mean ± sample standard deviation over every
 * repetition of the COMPLETED experiments of that group.
 *
 * The per-repetition values come from `experiments.metrics.repetitions` (computed by
 * ExperimentMetricsCalculator), so the summary never recomputes a formula. As in
 * ExperimentMetricsCalculator::aggregate, the lower-is-better rates (False Positive Rate,
 * Redundancy) skip repetitions whose denominator is 0; a metric with no applicable repetition
 * has no value (empty cell in CSV, "—" in Markdown) rather than a perfect 0.
 */
final class ExperimentSummaryBuilder
{
    /** Summary metrics: §11 rates, cost metrics, then the generated case and detected bug counts. */
    public const METRIC_KEYS = [
        ...ExperimentMetricsCalculator::RATE_KEYS,
        ...ExperimentMetricsCalculator::COST_KEYS,
        'generated',
        'bugs_detected',
    ];

    public const CSV_HEADER_PREFIX = [
        'benchmark', 'strategy', 'model', 'temperature', 'experiment_ids', 'experiments',
        'experiments_completed', 'repetitions', 'bugs_total',
    ];

    private const LABELS = [
        'validity_rate' => 'VR',
        'executable_rate' => 'ER',
        'false_positive_rate' => 'FPR',
        'bug_detection_rate' => 'BDR',
        'mutation_score' => 'MS',
        'endpoint_coverage' => 'EC',
        'status_code_coverage' => 'SCC',
        'redundancy' => 'Redundancy',
        'generation_time_ms' => 'Sinh (s)',
        'execution_time_ms' => 'Chạy (s)',
        'prompt_tokens' => 'Prompt tokens',
        'completion_tokens' => 'Completion tokens',
        'generated' => 'Case sinh ra',
        'bugs_detected' => 'Bug phát hiện',
    ];

    public function __construct(private readonly ExperimentMetricsCalculator $calculator) {}

    /**
     * Experiments must have `benchmark` loaded.
     *
     * @param  list<Experiment>  $experiments
     * @return list<array{benchmark: string, strategy: string, model: string, temperature: float|null, experiment_ids: list<int>, experiments: int, experiments_completed: int, repetitions: int, bugs_total: int, metrics: array<string, array{mean: float, std: float}|null>}>
     */
    public function rows(array $experiments): array
    {
        /** @var array<string, array{benchmark: string, strategy: string, model: string, temperature: float|null, experiment_ids: list<int>, experiments: int, experiments_completed: int, bugs_total: int, observations: list<array<string, mixed>>}> $groups */
        $groups = [];
        foreach ($experiments as $experiment) {
            $config = $experiment->config;
            $model = is_string($config['model'] ?? null) && $config['model'] !== '' ? $config['model'] : 'mặc định ai-service';
            $temperature = is_numeric($config['temperature'] ?? null) ? round((float) $config['temperature'], 4) : null;
            $key = implode('|', [$experiment->benchmark->slug, $experiment->strategy->value, $model, (string) $temperature]);

            $groups[$key] ??= [
                'benchmark' => $experiment->benchmark->slug,
                'strategy' => $experiment->strategy->value,
                'model' => $model,
                'temperature' => $temperature,
                'experiment_ids' => [],
                'experiments' => 0,
                'experiments_completed' => 0,
                'bugs_total' => 0,
                'observations' => [],
            ];
            $groups[$key]['experiment_ids'][] = $experiment->id;
            $groups[$key]['experiments']++;

            $metrics = $experiment->metrics;
            if ($experiment->status !== ExperimentStatus::Completed || ! is_array($metrics)) {
                continue;
            }
            $groups[$key]['experiments_completed']++;
            if (is_numeric($metrics['bugs_total'] ?? null)) {
                $groups[$key]['bugs_total'] = max($groups[$key]['bugs_total'], (int) $metrics['bugs_total']);
            }
            foreach (is_array($metrics['repetitions'] ?? null) ? $metrics['repetitions'] : [] as $repetition) {
                if (is_array($repetition)) {
                    /** @var array<string, mixed> $repetition */
                    $groups[$key]['observations'][] = $repetition;
                }
            }
        }

        $order = ['RULE' => 0, 'LLM' => 1, 'HYBRID' => 2];
        uasort($groups, fn (array $a, array $b): int => [$a['benchmark'], $order[$a['strategy']] ?? 9, $a['model'], $a['temperature']]
            <=> [$b['benchmark'], $order[$b['strategy']] ?? 9, $b['model'], $b['temperature']]);

        $rows = [];
        foreach ($groups as $group) {
            $metrics = [];
            foreach (self::METRIC_KEYS as $metric) {
                $values = [];
                foreach ($group['observations'] as $observation) {
                    if (! $this->applicable($metric, $observation) || ! is_numeric($observation[$metric] ?? null)) {
                        continue;
                    }
                    $values[] = (float) $observation[$metric];
                }
                $metrics[$metric] = $values === [] ? null : [
                    'mean' => round($this->calculator->mean($values), 4),
                    'std' => round($this->calculator->sampleStddev($values), 4),
                ];
            }

            $rows[] = [
                'benchmark' => $group['benchmark'],
                'strategy' => $group['strategy'],
                'model' => $group['model'],
                'temperature' => $group['temperature'],
                'experiment_ids' => $group['experiment_ids'],
                'experiments' => $group['experiments'],
                'experiments_completed' => $group['experiments_completed'],
                'repetitions' => count($group['observations']),
                'bugs_total' => $group['bugs_total'],
                'metrics' => $metrics,
            ];
        }

        return $rows;
    }

    /**
     * Wide CSV (UTF-8 BOM, CSV-injection safe): rates as fractions in [0, 1], times in ms.
     *
     * @param  list<Experiment>  $experiments
     */
    public function toCsv(array $experiments): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new RuntimeException('Không thể tạo bộ đệm CSV.');
        }

        $header = self::CSV_HEADER_PREFIX;
        foreach (self::METRIC_KEYS as $metric) {
            $header[] = "{$metric}_mean";
            $header[] = "{$metric}_std";
        }
        $this->writeRow($handle, $header);

        foreach ($this->rows($experiments) as $row) {
            $cells = [
                $row['benchmark'], $row['strategy'], $row['model'], $row['temperature'],
                implode(';', $row['experiment_ids']), $row['experiments'], $row['experiments_completed'],
                $row['repetitions'], $row['bugs_total'],
            ];
            foreach (self::METRIC_KEYS as $metric) {
                $cells[] = $row['metrics'][$metric]['mean'] ?? null;
                $cells[] = $row['metrics'][$metric]['std'] ?? null;
            }
            $this->writeRow($handle, $cells);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return RunReportExporter::UTF8_BOM.$csv;
    }

    /**
     * Markdown table (Vietnamese) for README / report: rates in %, times in seconds.
     *
     * @param  list<Experiment>  $experiments
     */
    public function toMarkdown(array $experiments, string $generatedAt): string
    {
        $rows = $this->rows($experiments);

        $lines = [
            '# Tóm tắt thực nghiệm (make experiment)',
            '',
            "Xuất lúc (UTC): {$generatedAt}",
            '',
            'Giá trị = trung bình ± độ lệch chuẩn mẫu qua các lần lặp của thực nghiệm COMPLETED (docs/DESIGN.md §11).',
            'Tỷ lệ tính bằng %, thời gian bằng giây; "—" = không áp dụng (mẫu số bằng 0 ở mọi lần lặp hoặc chưa có lần lặp hoàn tất).',
            '',
        ];

        $header = ['Benchmark', 'Chiến lược', 'Model', 'T', 'Lần lặp'];
        foreach (self::METRIC_KEYS as $metric) {
            $header[] = self::LABELS[$metric];
        }
        $lines[] = '| '.implode(' | ', $header).' |';
        $lines[] = '|'.str_repeat('---|', 4).str_repeat('---:|', count($header) - 4);

        foreach ($rows as $row) {
            $cells = [
                $row['benchmark'],
                $row['strategy'],
                $row['model'],
                $row['temperature'] === null ? '—' : (string) $row['temperature'],
                $row['experiments_completed'] < $row['experiments']
                    ? "{$row['repetitions']} ({$row['experiments_completed']}/{$row['experiments']} thực nghiệm COMPLETED)"
                    : (string) $row['repetitions'],
            ];
            foreach (self::METRIC_KEYS as $metric) {
                $cells[] = $this->formatCell($metric, $row['metrics'][$metric] ?? null, $row['bugs_total']);
            }
            $lines[] = '| '.implode(' | ', array_map(fn (string $cell): string => str_replace(['|', "\n", "\r"], ['\\|', ' ', ' '], $cell), $cells)).' |';
        }

        if ($rows === []) {
            $lines[] = '';
            $lines[] = '_Không có thực nghiệm nào._';
        }

        $lines[] = '';
        $lines[] = 'Chú thích: VR Validity Rate, ER Executable Rate, FPR False Positive Rate, BDR Bug Detection Rate (D14),';
        $lines[] = 'MS Mutation Score, EC Endpoint Coverage, SCC Status Code Coverage, T temperature.';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param  array{mean: float, std: float}|null  $value
     */
    private function formatCell(string $metric, ?array $value, int $bugsTotal): string
    {
        if ($value === null) {
            return '—';
        }

        // Same half-up rounding as M15 (MetricFormat, QA S14-06), on the 4-decimal values of summary.csv.
        $cell = static fn (float $mean, float $std, int $decimals): string => MetricFormat::number($mean, $decimals, '.', '')
            .' ± '.MetricFormat::number($std, $decimals, '.', '');

        return match (true) {
            in_array($metric, ExperimentMetricsCalculator::RATE_KEYS, true) => MetricFormat::percent($value['mean'], 1, '.', '')
                .' ± '.MetricFormat::percent($value['std'], 1, '.', ''),
            str_ends_with($metric, '_time_ms') => $cell($value['mean'] / 1000, $value['std'] / 1000, 1),
            $metric === 'bugs_detected' => $cell($value['mean'], $value['std'], 1).' / '.$bugsTotal,
            $metric === 'generated' => $cell($value['mean'], $value['std'], 1),
            default => $cell($value['mean'], $value['std'], 0),
        };
    }

    /**
     * @param  array<string, mixed>  $observation
     */
    private function applicable(string $metric, array $observation): bool
    {
        $denominator = match ($metric) {
            'false_positive_rate' => $this->int($observation['clean_pass'] ?? 0) + $this->int($observation['clean_fail'] ?? 0),
            'redundancy' => $this->int($observation['generated'] ?? 0),
            default => 1,
        };

        return $denominator > 0;
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param  resource  $handle
     * @param  list<mixed>  $cells
     */
    private function writeRow($handle, array $cells): void
    {
        $safe = array_map(function (mixed $cell): string {
            $text = match (true) {
                $cell === null => '',
                is_float($cell) => rtrim(rtrim(sprintf('%.6F', $cell), '0'), '.'),
                is_scalar($cell) => (string) $cell,
                default => (string) json_encode($cell),
            };

            return RunReportExporter::sanitizeCsvCell(mb_scrub($text, 'UTF-8'));
        }, $cells);
        fputcsv($handle, $safe, ',', '"', '', "\r\n");
    }
}
