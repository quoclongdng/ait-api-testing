<?php

namespace App\Actions\Demo;

use App\Services\Benchmark\GroundTruthLoader;
use App\Services\Benchmark\RecordedLlmJobLoader;
use App\Services\Demo\DemoShowcaseFixture;
use App\Services\Support\PgDumpReader;
use App\Support\Demo\DemoShowcase;
use Illuminate\Support\Carbon;
use JsonException;
use RuntimeException;

/**
 * Derives the compact demo showcase fixture (DemoShowcaseFixture, S14-05) from the official results
 * benchmark/results/benchmark-v1.0/<slug>/: `db.sql.gz` (recorded LLM/HYBRID jobs seed 42 and the
 * totals of every experiment run) and `experiments.json` (config, metrics, D14 detections).
 *
 * Read-only on the results; the output is deterministic for the same results, so
 * DemoShowcaseFixtureTest can check that the committed fixture is up to date.
 */
class BuildDemoShowcaseFixtureAction
{
    public function __construct(
        private readonly GroundTruthLoader $groundTruth,
        private readonly RecordedLlmJobLoader $recordedJobs,
        private readonly PgDumpReader $reader,
    ) {}

    /**
     * @return array{format_version: int, source: string, benchmarks: array<string, array{recorded_jobs: list<array<string, mixed>>, experiments: list<array<string, mixed>>}>}
     *
     * @throws RuntimeException When a result file is missing or inconsistent.
     */
    public function execute(): array
    {
        $benchmarks = [];
        foreach (DemoShowcase::projects() as $project) {
            $slug = $project['slug'];
            $recorded = [];
            foreach (DemoShowcase::RECORDED_STRATEGIES as $strategy) {
                $recorded[] = $this->recordedJobs->record($slug, $strategy, DemoShowcase::RECORDED_SEED);
            }
            $benchmarks[$slug] = [
                'recorded_jobs' => $recorded,
                'experiments' => $this->experiments($slug),
            ];
        }

        return [
            'format_version' => DemoShowcaseFixture::FORMAT_VERSION,
            'source' => RecordedLlmJobLoader::RESULTS_DIR,
            'benchmarks' => $benchmarks,
        ];
    }

    /**
     * Gzip-compressed JSON of execute() (what the command writes).
     *
     * @throws RuntimeException|JsonException
     */
    public function encode(): string
    {
        $json = json_encode($this->execute(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $gz = gzencode($json, 9);
        if ($gz === false) {
            throw new RuntimeException('Không nén được fixture demo.');
        }

        return $gz;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function experiments(string $slug): array
    {
        $dir = rtrim($this->groundTruth->rootPath(), '/').'/'.RecordedLlmJobLoader::RESULTS_DIR.'/'.$slug;
        $raw = is_file($dir.'/experiments.json') ? file_get_contents($dir.'/experiments.json') : false;
        if (! is_string($raw)) {
            throw new RuntimeException("Không đọc được {$dir}/experiments.json.");
        }
        try {
            $export = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("{$dir}/experiments.json không hợp lệ: {$e->getMessage()}", 0, $e);
        }
        $experiments = is_array($export) && is_array($export['experiments'] ?? null) ? $export['experiments'] : [];
        if ($experiments === []) {
            throw new RuntimeException("{$dir}/experiments.json không có thực nghiệm nào.");
        }

        $runs = [];
        foreach ($this->reader->tables($dir.'/db.sql.gz', ['test_runs'])['test_runs'] as $row) {
            $runs[(int) $row['id']] = $row;
        }

        $result = [];
        foreach ($experiments as $experiment) {
            $repetitions = [];
            foreach ((array) ($experiment['repetitions'] ?? []) as $repetition) {
                $bugRuns = [];
                foreach ((array) ($repetition['bug_runs'] ?? []) as $code => $runId) {
                    $bugRuns[(string) $code] = $this->run($runs, $runId, $slug);
                }
                ksort($bugRuns);
                $repetitions[] = [
                    'repetition' => (int) $repetition['repetition'],
                    'seed' => $repetition['seed'] ?? null,
                    'source_generation_job_ids' => array_values((array) ($repetition['generation_job_ids'] ?? [])),
                    'source_test_suite_id' => $repetition['test_suite_id'] ?? null,
                    'clean_run' => $this->run($runs, $repetition['clean_test_run_id'] ?? null, $slug),
                    'bug_runs' => $bugRuns,
                ];
            }

            $result[] = [
                'source_id' => (int) $experiment['id'],
                'name' => (string) $experiment['name'],
                'strategy' => (string) $experiment['strategy'],
                'status' => (string) $experiment['status'],
                'config' => $experiment['config'],
                'metrics' => $experiment['metrics'],
                'started_at' => $experiment['started_at'] ?? null,
                'finished_at' => $experiment['finished_at'] ?? null,
                'repetitions' => $repetitions,
                'bug_detections' => array_map(static fn (array $d): array => [
                    'bug_code' => (string) $d['bug_code'],
                    'repetition' => (int) $d['repetition'],
                    'detected' => (bool) $d['detected'],
                    'detecting_test_case_ids' => array_values((array) ($d['detecting_test_case_ids'] ?? [])),
                ], (array) ($experiment['bug_detections'] ?? [])),
            ];
        }
        usort($result, static fn (array $a, array $b): int => $a['source_id'] <=> $b['source_id']);

        return $result;
    }

    /**
     * @param  array<int, array<string, string|null>>  $runs
     * @return array<string, mixed>
     */
    private function run(array $runs, mixed $id, string $slug): array
    {
        $row = is_numeric($id) ? ($runs[(int) $id] ?? null) : null;
        if ($row === null) {
            throw new RuntimeException("Dump của {$slug} không có lượt chạy #".(is_scalar($id) ? (string) $id : '?').'.');
        }
        $totals = json_decode((string) $row['totals'], true);

        return [
            'source_id' => (int) $row['id'],
            'target_base_url' => (string) $row['target_base_url'],
            'benchmark_variant' => $row['benchmark_variant'],
            'status' => (string) $row['status'],
            'totals' => is_array($totals) ? $totals : null,
            'started_at' => $this->timestamp($row['started_at']),
            'finished_at' => $this->timestamp($row['finished_at']),
        ];
    }

    private function timestamp(?string $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->utc()->toIso8601ZuluString();
    }
}
