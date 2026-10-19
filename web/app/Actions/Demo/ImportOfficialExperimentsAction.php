<?php

namespace App\Actions\Demo;

use App\Enums\ExperimentStatus;
use App\Enums\GenerationStrategy;
use App\Enums\TestRunStatus;
use App\Exceptions\ApiException;
use App\Models\Benchmark;
use App\Models\Experiment;
use App\Models\ExperimentBugDetection;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\Benchmark\GroundTruthLoader;
use App\Services\Demo\DemoShowcaseFixture;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Imports the 9 official experiments of `benchmark-v1.0` (3 strategies × 3 benchmarks) into the demo
 * showcase so M15 shows the RULE/LLM/HYBRID comparison read-only (S14-05). Nothing is re-executed:
 *
 * - the experiment keeps the official config, metrics (mean ± std of §11) and timestamps;
 * - every clean/bug run of every repetition becomes a COMPLETED test run carrying only the official
 *   totals (no per-case results), in a "Thực nghiệm <STRATEGY> (bản ghi chính thức)" suite of the demo
 *   project of the benchmark;
 * - the D14 detections are stored by bug code with those runs. The official test case ids do not exist
 *   in this database, so `detecting_test_case_ids` stays empty.
 *
 * The three strategies of one benchmark share one name, as the experiments the M15 form starts together
 * do, so the comparison of M15 (BuildExperimentComparisonAction, grouped by benchmark and name) shows
 * RULE, LLM and HYBRID side by side (QA S14-06).
 *
 * Idempotent and converging: an experiment of the same name and strategy is skipped (a visitor cannot
 * delete experiments). Data of an earlier import is brought to the current form on every call: the
 * per-strategy names of the first showcase ("… · RULE (bản ghi chính thức)") are renamed, the record
 * suites get the current description, and the record runs are dated when they ran (created_at =
 * official started_at), so they never outrank a visitor's own runs on M02.
 */
class ImportOfficialExperimentsAction
{
    public const NAME_SUFFIX = ' (bản ghi chính thức)';

    /** Description of the record suites (no internal screen codes, QA S14-06). */
    public const SUITE_DESCRIPTION = 'Các lần chạy (chỉ có số liệu tổng) của thực nghiệm chính thức benchmark-v1.0, xem so sánh ở màn hình Thực nghiệm.';

    /** Earlier canonical descriptions of the record suites, replaced by SUITE_DESCRIPTION when still unchanged. */
    public const LEGACY_SUITE_DESCRIPTIONS = [
        'Các lần chạy (chỉ có số liệu tổng) của thực nghiệm chính thức benchmark-v1.0, nhập để xem ở M15.',
    ];

    public function __construct(
        private readonly DemoShowcaseFixture $fixture,
        private readonly GroundTruthLoader $groundTruth,
    ) {}

    /**
     * @param  array<string, TestSuite>  $anchorSuites  A suite of the demo project of each benchmark (by slug):
     *                                                  its project and spec host the imported runs.
     * @return list<array{slug: string, name: string, strategy: string, created: bool}>
     *
     * @throws RuntimeException When the fixture or the imported benchmark is missing.
     * @throws ApiException When the ground truth of a benchmark is missing or invalid.
     */
    public function execute(User $admin, array $anchorSuites): array
    {
        $report = [];
        foreach ($anchorSuites as $slug => $anchor) {
            // The official benchmark is the one of the ground-truth version, never a newer row
            // registered by hand under the same slug (S14-06).
            $version = $this->groundTruth->load($slug)->version;
            $benchmark = Benchmark::query()->where('slug', $slug)->where('version', $version)->first();
            if (! $benchmark instanceof Benchmark) {
                throw new RuntimeException("Chưa import benchmark {$slug} phiên bản {$version} (ait:benchmarks:import).");
            }
            $bugIds = $benchmark->bugs()->pluck('id', 'code')->map(static fn ($id): int => (int) $id)->all();

            foreach ($this->fixture->experiments($slug) as $official) {
                $strategy = GenerationStrategy::from((string) $official['strategy']);
                $name = self::experimentName((string) $official['name']);
                $this->renameLegacy((string) $official['name'], $strategy, $name);

                $existing = Experiment::query()->where('name', $name)->where('strategy', $strategy->value)->first();
                if ($existing instanceof Experiment) {
                    $this->converge($existing);
                } else {
                    DB::transaction(fn () => $this->import($admin, $benchmark, $anchor, $strategy, $name, $official, $bugIds));
                }
                $report[] = ['slug' => $slug, 'name' => $name, 'strategy' => $strategy->value, 'created' => ! $existing instanceof Experiment];
            }
        }

        return $report;
    }

    /**
     * Name shared by the RULE, LLM and HYBRID experiments of one official `make experiment` batch.
     */
    public static function experimentName(string $officialName): string
    {
        return $officialName.self::NAME_SUFFIX;
    }

    /**
     * The first showcase import named each strategy separately ("<official> · RULE (bản ghi chính thức)"),
     * which split the M15 comparison into one board per strategy: give such an experiment the shared name.
     */
    private function renameLegacy(string $officialName, GenerationStrategy $strategy, string $name): void
    {
        Experiment::query()
            ->where('name', $officialName.' · '.$strategy->value.self::NAME_SUFFIX)
            ->where('strategy', $strategy->value)
            ->update(['name' => $name]);
    }

    /**
     * Brings the suite description and the run timestamps of an earlier import to the current form.
     */
    private function converge(Experiment $experiment): void
    {
        $runIds = [];
        $suiteIds = [];
        foreach ($experiment->repetitions_data ?? [] as $repetition) {
            if (is_numeric($repetition['test_suite_id'] ?? null)) {
                $suiteIds[] = (int) $repetition['test_suite_id'];
            }
            if (is_numeric($repetition['clean_test_run_id'] ?? null)) {
                $runIds[] = (int) $repetition['clean_test_run_id'];
            }
            foreach (is_array($repetition['bug_runs'] ?? null) ? $repetition['bug_runs'] : [] as $runId) {
                if (is_numeric($runId)) {
                    $runIds[] = (int) $runId;
                }
            }
        }

        if ($suiteIds !== []) {
            TestSuite::query()
                ->whereIn('id', array_values(array_unique($suiteIds)))
                ->whereIn('description', self::LEGACY_SUITE_DESCRIPTIONS)
                ->update(['description' => self::SUITE_DESCRIPTION]);
        }

        if ($runIds !== []) {
            // Record runs carry the official started_at/finished_at but were stamped with the import time.
            // created_at keeps whole seconds (timestamptz(0)) while started_at keeps microseconds: compare
            // and copy the value truncated to the second, so a converged run is not updated again.
            TestRun::query()
                ->whereIn('id', array_values(array_unique($runIds)))
                ->whereNotNull('started_at')
                ->whereRaw("created_at <> date_trunc('second', started_at)")
                ->toBase()
                ->update([
                    'created_at' => DB::raw("date_trunc('second', started_at)"),
                    'updated_at' => DB::raw("date_trunc('second', coalesce(finished_at, started_at))"),
                ]);
        }
    }

    /**
     * @param  array<string, mixed>  $official
     * @param  array<string, int>  $bugIds  Benchmark bug id by code.
     */
    private function import(User $admin, Benchmark $benchmark, TestSuite $anchor, GenerationStrategy $strategy, string $name, array $official, array $bugIds): void
    {
        $suite = TestSuite::query()->firstOrCreate(
            ['project_id' => $anchor->project_id, 'name' => 'Thực nghiệm '.$strategy->value.self::NAME_SUFFIX],
            [
                'api_spec_id' => $anchor->api_spec_id,
                'description' => self::SUITE_DESCRIPTION,
                'created_by' => $admin->id,
            ],
        );
        if (in_array($suite->description, self::LEGACY_SUITE_DESCRIPTIONS, true)) {
            $suite->update(['description' => self::SUITE_DESCRIPTION]);
        }

        $repetitions = [];
        $runIds = [];
        foreach ($this->rows($official['repetitions'] ?? []) as $repetition) {
            $number = (int) ($repetition['repetition'] ?? 0);
            $clean = $this->run($suite, $admin, $this->row($repetition['clean_run'] ?? []));
            $bugRuns = [];
            foreach ($this->row($repetition['bug_runs'] ?? []) as $code => $bugRun) {
                $bugRuns[(string) $code] = $this->run($suite, $admin, $this->row($bugRun))->id;
            }
            $runIds[$number] = ['clean' => $clean->id, 'bugs' => $bugRuns];
            $repetitions[] = [
                'repetition' => $number,
                'seed' => (int) ($repetition['seed'] ?? 0),
                'generation_job_id' => null,
                'generation_job_ids' => [],
                'test_suite_id' => $suite->id,
                'clean_test_run_id' => $clean->id,
                'bug_runs' => $bugRuns,
            ];
        }

        $experiment = Experiment::create([
            'name' => $name,
            'benchmark_id' => $benchmark->id,
            'strategy' => $strategy,
            'config' => $this->row($official['config'] ?? []),
            'status' => ExperimentStatus::Completed,
            'metrics' => $this->row($official['metrics'] ?? []),
            'repetitions_data' => $repetitions,
            'started_at' => $official['started_at'] ?? null,
            'finished_at' => $official['finished_at'] ?? null,
        ]);

        foreach ($this->rows($official['bug_detections'] ?? []) as $detection) {
            $code = (string) ($detection['bug_code'] ?? '');
            $number = (int) ($detection['repetition'] ?? 0);
            $bugRun = $runIds[$number]['bugs'][$code] ?? null;
            if (! isset($bugIds[$code]) || $bugRun === null) {
                continue; // a bug or run absent from this import cannot be linked
            }
            ExperimentBugDetection::create([
                'experiment_id' => $experiment->id,
                'benchmark_bug_id' => $bugIds[$code],
                'repetition' => $number,
                'detected' => (bool) ($detection['detected'] ?? false),
                'clean_test_run_id' => $runIds[$number]['clean'],
                'bug_test_run_id' => $bugRun,
                'detecting_test_case_ids' => [],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $official
     */
    private function run(TestSuite $suite, User $admin, array $official): TestRun
    {
        $run = new TestRun([
            'test_suite_id' => $suite->id,
            'target_base_url' => (string) ($official['target_base_url'] ?? ''),
            'benchmark_variant' => isset($official['benchmark_variant']) ? (string) $official['benchmark_variant'] : null,
            'status' => TestRunStatus::Completed,
            'totals' => $this->row($official['totals'] ?? []),
            'triggered_by' => $admin->id,
            'started_at' => $official['started_at'] ?? null,
            'finished_at' => $official['finished_at'] ?? null,
        ]);
        // Dated when the official run happened, not when it was imported (recent runs of M02, QA S14-06).
        // Whole seconds, as converge() writes them (created_at is timestamptz(0)).
        if ($run->started_at !== null) {
            $run->created_at = $run->started_at->copy()->startOfSecond();
            $run->updated_at = ($run->finished_at ?? $run->started_at)->copy()->startOfSecond();
        }
        $run->save();

        return $run;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
}
