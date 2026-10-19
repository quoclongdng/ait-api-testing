<?php

namespace App\Actions\Experiments;

use App\Actions\ApiSpecs\CreateApiSpecAction;
use App\Actions\Generation\ExecuteAiGenerationAction;
use App\Actions\Generation\ExecuteRuleGenerationAction;
use App\Actions\Generation\TransitionGenerationJobAction;
use App\Actions\Runs\ExecuteTestRunAction;
use App\Contracts\BenchmarkSandbox;
use App\Data\Experiments\AuthFixture;
use App\Data\Experiments\ExperimentStep;
use App\Data\Experiments\GroundTruthBug;
use App\Data\Experiments\RepetitionObservation;
use App\Enums\ApiSpecStatus;
use App\Enums\ErrorCode;
use App\Enums\ExperimentStatus;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\TestCaseStatus;
use App\Enums\TestRunStatus;
use App\Enums\TestVerdict;
use App\Exceptions\ApiException;
use App\Models\ApiSpec;
use App\Models\Benchmark;
use App\Models\BenchmarkBug;
use App\Models\Endpoint;
use App\Models\Experiment;
use App\Models\ExperimentBugDetection;
use App\Models\GenerationJob;
use App\Models\LlmCallLog;
use App\Models\Project;
use App\Models\TestCase;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\Benchmark\GroundTruthLoader;
use App\Services\Experiments\BugDetectionEvaluator;
use App\Services\Experiments\ExperimentMetricsCalculator;
use App\Services\Experiments\ExperimentSeedPlanner;
use App\Services\Experiments\SpecCoverageAnalyzer;
use App\Services\OpenApi\OpenApiParser;
use App\Services\Support\GenerationJobError;
use App\Services\Support\SafeErrorMessage;
use App\Services\Target\TargetAllowlistService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Executes one experiment inside the worker, following experiment-design.md §4.3 and ADR-0002 §4:
 *
 * for each repetition r (seed = config.seeds[r-1], protocol 42, 1024, 2026 — ExperimentSeedPlanner):
 *   1. generate a fresh suite with the experiment strategy (validate + dedup as usual, D04);
 *      HYBRID first persists the full RULE suite, then adds the LLM cases seeded from it (§10);
 *   2. reset the clean sandbox (POST /__admin/reset) and run the whole suite on it (must end COMPLETED);
 *   3. for each seeded bug listed in ground-truth.yaml when the experiment started, reset the
 *      single-bug sandbox and run the whole suite on it (must end COMPLETED), then apply D14
 *      (PASS on clean and FAIL or ERROR on the bug variant);
 *   4. record the repetition (repetitions_data) and its bug detections.
 * Finally aggregate every §11 metric (mean and stddev over repetitions) and complete.
 *
 * The work is split into ExperimentStep units (start() returns the plan, runStep() executes one),
 * so the queue runs every step as its own job and no job exceeds the worker timeout (S11-02).
 * execute() runs all steps inline (tests, sync queue).
 *
 * Generated suites and runs live in a project "Thực nghiệm: <benchmark>" owned by the user who
 * started the experiment, so they remain visible on M09/M12 under the normal policies.
 */
class RunExperimentAction
{
    public function __construct(
        private readonly TransitionExperimentAction $transition,
        private readonly CreateApiSpecAction $createSpec,
        private readonly ExecuteRuleGenerationAction $ruleGeneration,
        private readonly ExecuteAiGenerationAction $aiGeneration,
        private readonly ExecuteTestRunAction $executeRun,
        private readonly BenchmarkSandbox $sandbox,
        private readonly GroundTruthLoader $loader,
        private readonly TargetAllowlistService $allowlist,
        private readonly TransitionGenerationJobAction $transitionJob,
        private readonly BugDetectionEvaluator $detector,
        private readonly SpecCoverageAnalyzer $coverage,
        private readonly ExperimentMetricsCalculator $calculator,
        private readonly ExperimentSeedPlanner $seedPlanner,
    ) {}

    /**
     * Runs the whole experiment inline (every step in order).
     */
    public function execute(int $experimentId, int $userId): void
    {
        $steps = $this->start($experimentId, $userId);
        foreach ($steps ?? [] as $step) {
            if (! $this->runStep($experimentId, $userId, $step)) {
                return;
            }
        }
    }

    /**
     * PLANNED → RUNNING, checks the benchmark spec, and returns the ordered steps to execute.
     * Returns null when the experiment is missing, already picked up, or failed while starting.
     *
     * @return list<ExperimentStep>|null
     */
    public function start(int $experimentId, int $userId): ?array
    {
        $experiment = Experiment::query()->with('benchmark.bugs')->find($experimentId);
        if (! $experiment instanceof Experiment || $experiment->status !== ExperimentStatus::Planned) {
            return null; // missing or already picked up
        }

        try {
            $this->transition->execute($experiment, ExperimentStatus::Running);
        } catch (ApiException) {
            return null;
        }

        try {
            $user = User::query()->findOrFail($userId);
            $this->prepareSpec($experiment->benchmark, $user);

            return $this->plan($experiment);
        } catch (Throwable $e) {
            $this->fail($experiment, $e);

            return null;
        }
    }

    /**
     * Executes one step. Returns false when the experiment is no longer RUNNING (failed earlier,
     * or this step failed and marked it FAILED), so the remaining steps must be skipped.
     */
    public function runStep(int $experimentId, int $userId, ExperimentStep $step): bool
    {
        $experiment = Experiment::query()->with('benchmark.bugs')->find($experimentId);
        if (! $experiment instanceof Experiment || $experiment->status !== ExperimentStatus::Running) {
            return false;
        }

        try {
            $user = User::query()->findOrFail($userId);
            match ($step->kind) {
                ExperimentStep::GENERATE => $this->generateRepetition($experiment, $user, $step->repetition),
                ExperimentStep::VARIANT => $this->runVariantStep($experiment, $user, $step->repetition, $step->bugId),
                ExperimentStep::FINALIZE => $this->finalize($experiment),
                default => throw new ApiException(ErrorCode::ValidationFailed, "Bước thực nghiệm không hợp lệ: {$step->kind}."),
            };
        } catch (Throwable $e) {
            $this->fail($experiment, $e, $step);

            return false;
        }

        return true;
    }

    /**
     * Moves a PLANNED/RUNNING experiment to FAILED (via RUNNING, §4.4) and keeps the reason.
     */
    public function markFailed(int $experimentId, string $message): void
    {
        $experiment = Experiment::query()->find($experimentId);
        if (! $experiment instanceof Experiment) {
            return;
        }

        try {
            if ($experiment->status === ExperimentStatus::Planned) {
                $this->transition->execute($experiment, ExperimentStatus::Running);
            }
            if ($experiment->status === ExperimentStatus::Running) {
                $metrics = $experiment->metrics ?? [];
                $metrics['error'] = mb_substr($message, 0, 2000);
                $this->transition->execute($experiment, ExperimentStatus::Failed, ['metrics' => $metrics]);
            }
        } catch (ApiException) {
            // Reached a terminal state concurrently.
        }

        // A step killed by the worker (timeout, OOM, restart) never reaches the inline guards of
        // generate()/runVariant(): close the inner GenerationJob/TestRun it left behind (§4.1/§4.3).
        $this->failInFlightChildren($experiment);
    }

    /**
     * Fails every QUEUED/RUNNING generation job and variant test run of the suites this experiment
     * created. Suites are found through the description marker written by generateRepetition()
     * (the repetitions_data entry is only written once generation finished).
     */
    private function failInFlightChildren(Experiment $experiment): void
    {
        $suiteIds = TestSuite::query()
            ->where('description', 'like', $this->suiteMarker($experiment->id).'%')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        foreach ($experiment->repetitions_data ?? [] as $entry) {
            if (is_numeric($entry['test_suite_id'] ?? null)) {
                $suiteIds[] = (int) $entry['test_suite_id'];
            }
        }
        $suiteIds = array_values(array_unique($suiteIds));
        if ($suiteIds === []) {
            return;
        }

        $jobs = GenerationJob::query()
            ->whereIn('test_suite_id', $suiteIds)
            ->whereIn('status', [GenerationJobStatus::Queued->value, GenerationJobStatus::Running->value])
            ->get();
        foreach ($jobs as $job) {
            $this->failRunningJob($job);
        }

        $runIds = TestRun::query()
            ->whereIn('test_suite_id', $suiteIds)
            ->whereNotNull('benchmark_variant')
            ->whereIn('status', [TestRunStatus::Queued->value, TestRunStatus::Running->value])
            ->pluck('id');
        foreach ($runIds as $runId) {
            $this->executeRun->markFailed((int) $runId);
        }
    }

    private function suiteMarker(int $experimentId): string
    {
        return "Thực nghiệm #{$experimentId}, ";
    }

    /**
     * @return list<ExperimentStep>
     */
    public function plan(Experiment $experiment): array
    {
        $repetitions = max(1, (int) ($experiment->config['repetitions'] ?? 3));
        $bugIds = array_map(fn (BenchmarkBug $bug): int => (int) $bug->id, $this->activeBugs($experiment->benchmark));

        $steps = [];
        for ($repetition = 1; $repetition <= $repetitions; $repetition++) {
            $steps[] = ExperimentStep::generate($repetition);
            $steps[] = ExperimentStep::variant($repetition, null);
            foreach ($bugIds as $bugId) {
                $steps[] = ExperimentStep::variant($repetition, $bugId);
            }
        }
        $steps[] = ExperimentStep::finalize();

        return $steps;
    }

    /**
     * Seed of repetition r: config.seeds (stored at creation) or the protocol plan.
     */
    public function seedFor(Experiment $experiment, int $repetition): int
    {
        $config = $experiment->config;
        $repetitions = max(1, (int) ($config['repetitions'] ?? 3), $repetition);
        $explicit = is_array($config['seeds'] ?? null)
            ? array_values(array_map(fn (mixed $seed): int => is_numeric($seed) ? (int) $seed : 0, $config['seeds']))
            : null;
        $first = isset($config['seed']) && is_numeric($config['seed']) ? (int) $config['seed'] : null;

        return $this->seedPlanner->plan($repetitions, $first, $explicit)[$repetition - 1];
    }

    private function fail(Experiment $experiment, Throwable $e, ?ExperimentStep $step = null): void
    {
        Log::warning('Experiment failed', [
            'experiment_id' => $experiment->id,
            'step' => $step?->label(),
            'exception' => $e::class,
        ]);
        $this->markFailed($experiment->id, SafeErrorMessage::from(
            $e,
            'Thực nghiệm thất bại do lỗi hệ thống (INTERNAL_ERROR).',
            ['experiment_id' => $experiment->id, 'step' => $step?->label()],
        ));
        $this->restoreCleanVariant($experiment);
    }

    private function generateRepetition(Experiment $experiment, User $user, int $repetition): void
    {
        $spec = $this->prepareSpec($experiment->benchmark, $user);
        $seed = $this->seedFor($experiment, $repetition);

        $suite = TestSuite::create([
            'project_id' => $spec->project_id,
            'api_spec_id' => $spec->id,
            'name' => mb_substr("{$experiment->name} · {$experiment->strategy->value} · lần {$repetition}", 0, 255),
            'description' => $this->suiteMarker($experiment->id)."lần lặp {$repetition}, seed {$seed}.",
            'created_by' => $user->id,
        ]);

        $jobs = $this->generate($experiment, $spec, $suite, $user, $seed);
        $job = $jobs[array_key_last($jobs)];

        $this->updateRepetition($experiment, $repetition, fn (array $entry): array => [
            'repetition' => $repetition,
            'seed' => $seed,
            'generation_job_id' => $job->id,
            // Every job that filled the suite (HYBRID: RULE seed job, then the HYBRID job).
            'generation_job_ids' => array_map(fn (GenerationJob $j): int => $j->id, $jobs),
            'test_suite_id' => $suite->id,
            'clean_test_run_id' => null,
            'bug_runs' => [],
        ]);
    }

    /**
     * Resets the variant sandbox and runs the suite of the repetition on it; for a bug variant
     * the D14 detection is recorded right away.
     */
    private function runVariantStep(Experiment $experiment, User $user, int $repetition, ?int $bugId): void
    {
        $entry = $this->repetitionEntry($experiment, $repetition);
        $suite = TestSuite::query()->findOrFail($this->intValue($entry['test_suite_id'] ?? null));
        $benchmark = $experiment->benchmark;

        if ($bugId === null) {
            $run = $this->runVariant($suite, $user, $benchmark, null, $this->authFixture($experiment), $this->resetPerCase($experiment));
            if ($run->status !== TestRunStatus::Completed) {
                // A cancelled/failed baseline has SKIPPED/missing results: D14, Executable Rate and
                // False Positive Rate computed from it would be silently wrong.
                throw new ApiException(
                    ErrorCode::InvalidStateTransition,
                    "Lượt chạy bản sạch #{$run->id} kết thúc ở trạng thái {$run->status->value}, không thể tính chỉ số cho lần lặp {$repetition}.",
                    ['test_run_id' => $run->id]
                );
            }
            $this->updateRepetition($experiment, $repetition, function (array $current) use ($run): array {
                $current['clean_test_run_id'] = $run->id;

                return $current;
            });

            return;
        }

        $bug = $benchmark->bugs->firstWhere('id', $bugId);
        if (! $bug instanceof BenchmarkBug) {
            throw new ApiException(ErrorCode::ValidationFailed, "Seeded bug #{$bugId} không thuộc benchmark {$benchmark->slug}.");
        }
        $cleanRun = TestRun::query()->find($this->intValue($entry['clean_test_run_id'] ?? null));
        if (! $cleanRun instanceof TestRun) {
            throw new ApiException(ErrorCode::InvalidStateTransition, "Lần lặp {$repetition} chưa có lượt chạy trên biến thể sạch.");
        }

        $run = $this->runVariant($suite, $user, $benchmark, $bug, $this->authFixture($experiment), $this->resetPerCase($experiment));
        if ($run->status !== TestRunStatus::Completed) {
            // A cancelled/failed bug run was never evaluated: folding it into "not detected" would
            // lower Bug Detection Rate while Mutation Score ignores it. An unreachable sandbox
            // already fails the experiment at reset; an ERROR on a completed run counts (D14).
            throw new ApiException(
                ErrorCode::InvalidStateTransition,
                "Lượt chạy biến thể {$bug->code} #{$run->id} kết thúc ở trạng thái {$run->status->value}, không thể tính chỉ số cho lần lặp {$repetition}.",
                ['test_run_id' => $run->id]
            );
        }

        $detecting = $this->detector->detectingCases($this->verdicts($cleanRun), $this->verdicts($run));
        ExperimentBugDetection::create([
            'experiment_id' => $experiment->id,
            'benchmark_bug_id' => $bug->id,
            'repetition' => $repetition,
            'detected' => $detecting !== [],
            'clean_test_run_id' => $cleanRun->id,
            'bug_test_run_id' => $run->id,
            'detecting_test_case_ids' => $detecting,
        ]);

        $this->updateRepetition($experiment, $repetition, function (array $current) use ($bug, $run): array {
            $bugRuns = is_array($current['bug_runs'] ?? null) ? $current['bug_runs'] : [];
            $bugRuns[$bug->code] = $run->id;
            $current['bug_runs'] = $bugRuns;

            return $current;
        });
    }

    private function finalize(Experiment $experiment): void
    {
        $repetitionsData = $this->sortedRepetitions($experiment->repetitions_data ?? []);

        $observations = [];
        foreach ($repetitionsData as $entry) {
            $observations[] = $this->observeRepetition($experiment, $entry);
        }

        $metrics = $this->calculator->aggregate($observations);
        $failures = $this->generationFailures($repetitionsData);
        if ($repetitionsData !== [] && count($failures) === count($repetitionsData)) {
            // Every repetition's generation FAILED: the all-zero means are not a measurement (§4.4).
            // Keep only the per-repetition counters (partial data) and the reason.
            $metrics = ['repetitions' => $metrics['repetitions']];
            $metrics['error'] = mb_substr('Sinh test thất bại ở mọi lần lặp, không có chỉ số để đánh giá: '
                .implode('; ', array_map(
                    fn (int $repetition, string $error): string => "lần {$repetition}: {$error}",
                    array_keys($failures),
                    $failures,
                )).'.', 0, 2000);
            $this->transition->execute($experiment, ExperimentStatus::Failed, [
                'metrics' => $metrics,
                'repetitions_data' => $repetitionsData,
            ]);
            $this->restoreCleanVariant($experiment);

            return;
        }

        $this->transition->execute($experiment, ExperimentStatus::Completed, [
            'metrics' => $metrics,
            'repetitions_data' => $repetitionsData,
        ]);
        $this->restoreCleanVariant($experiment);
    }

    /**
     * Repetitions whose scored generation job (the last one) ended FAILED, with its error code.
     *
     * @param  list<array<string, mixed>>  $repetitionsData
     * @return array<int, string>
     */
    private function generationFailures(array $repetitionsData): array
    {
        $failures = [];
        foreach ($repetitionsData as $entry) {
            $job = GenerationJob::query()->find($this->intValue($entry['generation_job_id'] ?? null));
            if ($job instanceof GenerationJob && $job->status === GenerationJobStatus::Failed) {
                $failures[$this->intValue($entry['repetition'] ?? null)] = mb_substr(GenerationJobError::oneLine($job->error ?: ErrorCode::InternalError->value), 0, 300);
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function observeRepetition(Experiment $experiment, array $entry): RepetitionObservation
    {
        $repetition = $this->intValue($entry['repetition'] ?? null);
        $jobIds = is_array($entry['generation_job_ids'] ?? null) && $entry['generation_job_ids'] !== []
            ? array_map(fn (mixed $id): int => $this->intValue($id), array_values($entry['generation_job_ids']))
            : [$this->intValue($entry['generation_job_id'] ?? null)];
        $jobs = [];
        foreach ($jobIds as $jobId) {
            $jobs[] = GenerationJob::query()->findOrFail($jobId);
        }
        // HYBRID whose own (LLM) job FAILED: the suite only holds the RULE seed cases, so scoring
        // them would report RULE's numbers under HYBRID and hide the failure. Score the repetition
        // as a generation failure, like a failed LLM repetition: only the failed strategy job counts.
        $scoredJob = $jobs[array_key_last($jobs)];
        if ($scoredJob->status === GenerationJobStatus::Failed && count($jobs) > 1) {
            $jobs = [$scoredJob];
        }
        /** @var list<int> $scoredCaseIds */
        $scoredCaseIds = TestCase::query()
            ->whereIn('generation_job_id', array_map(fn (GenerationJob $job): int => $job->id, $jobs))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $suite = TestSuite::query()->findOrFail($this->intValue($entry['test_suite_id'] ?? null));
        $spec = ApiSpec::query()->findOrFail($suite->api_spec_id);
        $cleanRun = TestRun::query()->find($this->intValue($entry['clean_test_run_id'] ?? null));
        if (! $cleanRun instanceof TestRun) {
            throw new ApiException(ErrorCode::InvalidStateTransition, "Lần lặp {$repetition} chưa có lượt chạy trên biến thể sạch.");
        }

        $detections = ExperimentBugDetection::query()
            ->where('experiment_id', $experiment->id)
            ->where('repetition', $repetition)
            ->get();
        $bugRuns = TestRun::query()
            ->whereIn('id', $detections->pluck('bug_test_run_id')->all())
            ->get()
            ->keyBy('id');

        // The bug set is the one planned at start() and executed (one detection per bug, every
        // bug run COMPLETED). It is the denominator of both Bug Detection Rate and Mutation Score
        // (one single-bug variant per seeded bug), so editing ground-truth.yaml while the
        // experiment runs cannot change it.
        /** @var array<int, bool> $detectedByBug */
        $detectedByBug = [];
        $runIds = [$cleanRun->id];
        foreach ($detections as $detection) {
            $bugId = (int) $detection->benchmark_bug_id;
            // A detection counts only through a case of a scored job (D14 on the scored suite).
            $scoredDetection = $detection->detected
                && array_intersect(array_map('intval', $detection->detecting_test_case_ids ?? []), $scoredCaseIds) !== [];
            $detectedByBug[$bugId] = ($detectedByBug[$bugId] ?? false) || $scoredDetection;
            $bugRun = $bugRuns->get($detection->bug_test_run_id);
            if ($bugRun instanceof TestRun) {
                $runIds[] = $bugRun->id;
            }
        }
        // Sum of the measured per-case durations (test_results.duration_ms, millisecond precision).
        // test_runs.started_at/finished_at are timestamp(0): a sub-second run used to count as 0 or
        // 1000 ms depending on where the second boundary fell (S10-01 audit round 3).
        $executionMs = (int) TestResult::query()
            ->whereIn('test_run_id', array_values(array_unique($runIds)))
            ->sum('duration_ms');
        $totalBugs = count($detectedByBug);
        $detected = count(array_filter($detectedByBug));

        $cleanVerdicts = array_intersect_key($this->verdicts($cleanRun), array_flip($scoredCaseIds));

        return $this->observe($jobs, $spec, $cleanVerdicts, [
            'repetition' => $repetition,
            'seed' => $this->intValue($entry['seed'] ?? null),
            'total_bugs' => $totalBugs,
            'detected' => $detected,
            'mutants' => $totalBugs,
            'killed' => $detected,
            'execution_ms' => $executionMs,
        ]);
    }

    private function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param  array<int, array<string, mixed>>  $data
     * @return list<array<string, mixed>>
     */
    private function sortedRepetitions(array $data): array
    {
        $data = array_values($data);
        usort($data, fn (array $a, array $b): int => $this->intValue($a['repetition'] ?? null) <=> $this->intValue($b['repetition'] ?? null));

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function repetitionEntry(Experiment $experiment, int $repetition): array
    {
        foreach ($experiment->repetitions_data ?? [] as $entry) {
            if ($this->intValue($entry['repetition'] ?? null) === $repetition) {
                return $entry;
            }
        }

        throw new ApiException(ErrorCode::InvalidStateTransition, "Lần lặp {$repetition} chưa được sinh test.");
    }

    /**
     * Read-modify-write of one repetitions_data entry under a row lock; progress stays visible on M15.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    private function updateRepetition(Experiment $experiment, int $repetition, callable $mutate): void
    {
        DB::transaction(function () use ($experiment, $repetition, $mutate): void {
            $locked = Experiment::query()->lockForUpdate()->whereKey($experiment->getKey())->firstOrFail();
            $data = array_values($locked->repetitions_data ?? []);

            $index = null;
            foreach ($data as $i => $entry) {
                if ($this->intValue($entry['repetition'] ?? null) === $repetition) {
                    $index = $i;
                    break;
                }
            }

            if ($index === null) {
                $data[] = $mutate([]);
            } else {
                $data[$index] = $mutate($data[$index]);
            }

            $locked->forceFill(['repetitions_data' => $this->sortedRepetitions($data)])->save();
            $experiment->setRawAttributes($locked->getAttributes(), true);
        });
    }

    private function prepareSpec(Benchmark $benchmark, User $user): ApiSpec
    {
        $content = $this->loader->openApiContent($benchmark->slug);
        if ($content === null) {
            throw new ApiException(ErrorCode::ValidationFailed, "Không tìm thấy benchmark/{$benchmark->slug}/openapi.yaml.");
        }

        $project = Project::query()->firstOrCreate(
            ['owner_id' => $user->id, 'name' => mb_substr("Thực nghiệm: {$benchmark->name} ({$benchmark->version})", 0, 255)],
            [
                'description' => "Không gian làm việc do thực nghiệm tạo tự động cho benchmark {$benchmark->slug}.",
                'default_target_url' => $benchmark->base_url,
            ],
        );

        $existing = ApiSpec::query()
            ->where('project_id', $project->id)
            ->where('checksum', OpenApiParser::checksum($content))
            ->first();
        if ($existing instanceof ApiSpec) {
            if ($existing->status !== ApiSpecStatus::Parsed) {
                throw new ApiException(ErrorCode::ValidationFailed, "Đặc tả OpenAPI của benchmark {$benchmark->slug} không phân tích được.");
            }

            return $existing;
        }

        return $this->createSpec->execute($project, $benchmark->version, $content, "{$benchmark->slug}-openapi.yaml");
    }

    /**
     * Generates the repetition suite. Returns every generation job that filled it, the strategy's
     * own job last. HYBRID first persists the RULE suite (same parameters as a RULE experiment), so
     * the scored HYBRID suite is "RULE seed + LLM additions" (docs/DESIGN.md §10, experiment-design §4.2);
     * the LLM job then takes its seed_cases from those persisted RULE cases.
     *
     * Params follow the GenerationParams contract: model/temperature/seed are top-level keys.
     *
     * @return non-empty-list<GenerationJob>
     */
    private function generate(Experiment $experiment, ApiSpec $spec, TestSuite $suite, User $user, int $seed): array
    {
        /** @var list<int> $endpointIds */
        $endpointIds = $spec->endpoints()->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $config = $experiment->config;
        $params = [
            'endpoint_ids' => $endpointIds,
            // Explicit empty list: RULE uses every technique and ai-service applies its per-strategy
            // defaults (LLM: all techniques, HYBRID: BUSINESS_RULE/SEQUENCE/AUTH) instead of
            // AiGenerateRequest's HAPPY_PATH fallback, so RULE/LLM/HYBRID compare fairly.
            'techniques' => [],
            'max_cases_per_endpoint' => 10,
        ];
        // ADR-0006: generators get the role names of the snapshotted auth fixture (never tokens);
        // RULE adds AUTH cases from it, LLM/HYBRID reference {{auth_token_<role>}} placeholders.
        $auth = $this->authFixture($experiment);
        if ($auth !== null) {
            $params['auth'] = $auth->toPublicArray();
        }

        $jobs = [];
        if ($experiment->strategy !== GenerationStrategy::Llm) {
            $ruleJob = $this->createJob($spec, $suite, $user, GenerationStrategy::Rule, $params);
            try {
                $jobs[] = $this->ruleGeneration->execute($ruleJob->id);
            } catch (Throwable $e) {
                $this->failRunningJob($ruleJob);

                throw $e;
            }
            if ($experiment->strategy === GenerationStrategy::Rule) {
                return $jobs;
            }
        }

        $aiParams = $params;
        if (array_key_exists('business_rules', $config) && is_array($config['business_rules'])) {
            // ADR-0006: the snapshot taken at creation, not the shared project's live rules.
            $aiParams['business_rules'] = array_values($config['business_rules']);
        }
        $job = $this->createJob($spec, $suite, $user, $experiment->strategy, $aiParams + [
            'model' => $config['model'] ?? null,
            'temperature' => (float) ($config['temperature'] ?? 0.2),
            'seed' => $seed,
        ]);

        try {
            $jobs[] = $this->aiGeneration->executeEndToEnd($job);
        } catch (ApiException) {
            // The job is FAILED with its error code; the repetition still counts as a generation
            // failure (validity 0). For HYBRID the RULE seed cases are still run, but
            // observeRepetition() scores only the failed strategy job, not those seeds.
            $jobs[] = $job->refresh();
        } catch (Throwable $e) {
            // A non-API failure after QUEUED -> RUNNING (e.g. while persisting cases) must not
            // leave the job RUNNING forever (§4.1); the experiment itself then fails.
            $this->failRunningJob($job);

            throw $e;
        }

        return $jobs;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function createJob(ApiSpec $spec, TestSuite $suite, User $user, GenerationStrategy $strategy, array $params): GenerationJob
    {
        return GenerationJob::create([
            'project_id' => $spec->project_id,
            'api_spec_id' => $spec->id,
            'test_suite_id' => $suite->id,
            'strategy' => $strategy,
            'status' => GenerationJobStatus::Queued,
            'params' => $params,
            'stats' => null,
            'created_by' => $user->id,
        ]);
    }

    private function failRunningJob(GenerationJob $job): void
    {
        $job->refresh();
        if (! in_array($job->status, [GenerationJobStatus::Queued, GenerationJobStatus::Running], true)) {
            return;
        }

        try {
            $this->transitionJob->execute($job, GenerationJobStatus::Failed, [
                'error' => ErrorCode::InternalError->value,
                'finished_at' => Carbon::now(),
            ]);
        } catch (ApiException) {
            // Reached a terminal state concurrently.
        }
    }

    /**
     * Bugs still listed in the benchmark's ground-truth.yaml. Rows kept in the DB for bugs removed
     * from ground truth (ImportGroundTruthAction reports them as stale) would otherwise be run and
     * counted in the Bug Detection Rate and Mutation Score denominators.
     *
     * @return list<BenchmarkBug>
     */
    private function activeBugs(Benchmark $benchmark): array
    {
        $bugs = $benchmark->bugs->sortBy('code')->values();

        try {
            $codes = array_map(fn (GroundTruthBug $bug): string => $bug->code, $this->loader->load($benchmark->slug)->bugs);
        } catch (ApiException $e) {
            // Falling back to the stored rows would run and count stale bugs: refuse to start.
            throw new ApiException(
                ErrorCode::ValidationFailed,
                "Không đọc được ground-truth.yaml của benchmark {$benchmark->slug}: {$e->getMessage()}",
                ['benchmark_id' => $benchmark->id]
            );
        }

        $active = array_values($bugs->filter(fn (BenchmarkBug $bug): bool => in_array($bug->code, $codes, true))->all());
        if ($active === []) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                "Benchmark {$benchmark->slug} không còn seeded bug nào khớp ground-truth.yaml. Hãy đồng bộ lại ground truth.",
                ['benchmark_id' => $benchmark->id]
            );
        }

        return $active;
    }

    /**
     * Runs the whole suite (VALID/APPROVED cases, in id order) against one variant.
     */
    private function runVariant(TestSuite $suite, User $user, Benchmark $benchmark, ?BenchmarkBug $bug, ?AuthFixture $auth = null, bool $resetPerCase = false): TestRun
    {
        $baseUrl = $this->sandbox->variantBaseUrl($benchmark, $bug);
        $this->allowlist->assertAllowed($baseUrl); // D07: fail the experiment instead of storing ERRORs
        // ADR-0002 §4: every clean and bug-variant run starts from the sandbox seed data.
        $this->sandbox->reset($baseUrl);

        /** @var TestRun $run */
        $run = $suite->testRuns()->create([
            'target_base_url' => $baseUrl,
            'benchmark_variant' => $bug === null ? 'clean' : $bug->code,
            'status' => TestRunStatus::Queued,
            'totals' => null,
            'triggered_by' => $user->id,
        ]);

        // ADR-0006 addendum 2026-10-12: with config.reset_per_case every case starts from the seed
        // data (the run-level reset above covers the first case), for every strategy and variant.
        $betweenCases = $resetPerCase ? function () use ($baseUrl): void {
            $this->sandbox->reset($baseUrl);
        } : null;

        try {
            // ADR-0006: the same credential rule for every strategy (tokens from the snapshot).
            $this->executeRun->execute($run->id, null, $auth, $betweenCases);
        } catch (Throwable $e) {
            // ExecuteTestRunAction has no outer guard: never leave the inner run RUNNING (§4.3).
            $this->executeRun->markFailed($run->id);

            throw $e;
        }

        return $run->refresh();
    }

    /**
     * @return array<int, TestVerdict>
     */
    private function verdicts(TestRun $run): array
    {
        $verdicts = [];
        foreach (TestResult::query()->where('test_run_id', $run->id)->get(['test_case_id', 'verdict']) as $result) {
            $verdicts[$result->test_case_id] = $result->verdict;
        }

        return $verdicts;
    }

    /**
     * @param  non-empty-list<GenerationJob>  $jobs
     * @param  array<int, TestVerdict>  $cleanVerdicts
     * @param  array{repetition: int, seed: int, total_bugs: int, detected: int, mutants: int, killed: int, execution_ms: int}  $counts
     */
    private function observe(array $jobs, ApiSpec $spec, array $cleanVerdicts, array $counts): RepetitionObservation
    {
        $jobIds = array_map(fn (GenerationJob $job): int => $job->id, $jobs);
        /** @var array<string, int> $byStatus */
        $byStatus = TestCase::query()
            ->whereIn('generation_job_id', $jobIds)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $n): int => (int) $n)
            ->all();
        $generated = array_sum($byStatus);

        $validDsls = TestCase::query()
            ->whereIn('generation_job_id', $jobIds)
            ->where('status', TestCaseStatus::Valid->value)
            ->get(['dsl'])
            ->map(fn (TestCase $case): array => $case->dsl)
            ->values()
            ->all();

        $specEndpoints = $spec->endpoints()->get()
            ->map(fn (Endpoint $endpoint): array => [
                'method' => $endpoint->method->value,
                'path' => $endpoint->path,
                'responses' => (array) $endpoint->responses,
            ])
            ->values()
            ->all();
        $coverage = $this->coverage->analyze(array_values($specEndpoints), array_values($validDsls));

        $verdictCounts = array_count_values(array_map(fn (TestVerdict $v): string => $v->value, $cleanVerdicts));

        $tokens = LlmCallLog::query()
            ->whereIn('generation_job_id', $jobIds)
            ->selectRaw('coalesce(sum(prompt_tokens), 0) as prompt, coalesce(sum(completion_tokens), 0) as completion')
            ->first();

        return new RepetitionObservation(
            repetition: $counts['repetition'],
            seed: $counts['seed'],
            generated: $generated,
            valid: $byStatus[TestCaseStatus::Valid->value] ?? 0,
            duplicate: $byStatus[TestCaseStatus::Duplicate->value] ?? 0,
            cleanPass: $verdictCounts[TestVerdict::Pass->value] ?? 0,
            cleanFail: $verdictCounts[TestVerdict::Fail->value] ?? 0,
            cleanError: $verdictCounts[TestVerdict::Error->value] ?? 0,
            totalBugs: $counts['total_bugs'],
            detectedBugs: $counts['detected'],
            totalMutants: $counts['mutants'],
            killedMutants: $counts['killed'],
            totalEndpoints: $coverage['total_endpoints'],
            coveredEndpoints: $coverage['covered_endpoints'],
            totalStatusPairs: $coverage['total_status_pairs'],
            assertedStatusPairs: $coverage['asserted_status_pairs'],
            generationTimeMs: array_sum(array_map(fn (GenerationJob $job): int => $this->durationMs($job->started_at, $job->finished_at), $jobs)),
            executionTimeMs: $counts['execution_ms'],
            promptTokens: (int) ($tokens?->getAttribute('prompt') ?? 0),
            completionTokens: (int) ($tokens?->getAttribute('completion') ?? 0),
        );
    }

    private function durationMs(?Carbon $start, ?Carbon $end): int
    {
        if ($start === null || $end === null) {
            return 0;
        }

        return max(0, (int) round($start->diffInMilliseconds($end)));
    }

    /**
     * experiments.config.reset_per_case (ADR-0006 addendum 2026-10-12). Experiments created before
     * the setting existed ran without per-case reset, so a missing key means false.
     */
    private function resetPerCase(Experiment $experiment): bool
    {
        return ($experiment->config['reset_per_case'] ?? false) === true;
    }

    /**
     * Auth fixture snapshotted into experiments.config.auth (ADR-0006), with tokens; null for
     * benchmarks without secured endpoints and experiments created before ADR-0006.
     */
    private function authFixture(Experiment $experiment): ?AuthFixture
    {
        $raw = $experiment->config['auth'] ?? null;
        if ($raw === null) {
            return null;
        }

        try {
            return AuthFixture::fromArray($raw, requireTokens: true);
        } catch (InvalidArgumentException $e) {
            throw new ApiException(ErrorCode::ValidationFailed, "Cấu hình xác thực (config.auth) của thực nghiệm không hợp lệ: {$e->getMessage()}");
        }
    }

    private function restoreCleanVariant(Experiment $experiment): void
    {
        try {
            $this->sandbox->variantBaseUrl($experiment->benchmark, null);
        } catch (Throwable) {
            // Best effort only.
        }
    }
}
