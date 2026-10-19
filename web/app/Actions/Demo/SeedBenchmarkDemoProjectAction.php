<?php

namespace App\Actions\Demo;

use App\Actions\ApiSpecs\CreateApiSpecAction;
use App\Actions\BusinessRules\CreateBusinessRuleAction;
use App\Actions\Generation\ConvergeRecordedLlmJobsAction;
use App\Actions\Generation\ExecuteRuleGenerationAction;
use App\Actions\Generation\ImportRecordedLlmJobAction;
use App\Actions\Runs\ExecuteTestRunAction;
use App\Contracts\BenchmarkSandbox;
use App\Data\Demo\DemoProjectPlan;
use App\Data\Demo\DemoProjectResult;
use App\Data\Experiments\AuthFixture;
use App\Data\Experiments\GroundTruthBenchmark;
use App\Data\Generation\RecordedLlmJob;
use App\Enums\ApiSpecFormat;
use App\Enums\ErrorCode;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\TestCaseStatus;
use App\Enums\TestRunStatus;
use App\Exceptions\ApiException;
use App\Models\ApiSpec;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\Benchmark\GroundTruthLoader;
use App\Services\OpenApi\OpenApiParser;
use App\Services\Target\TargetAllowlistService;
use Throwable;

/**
 * One demo project on a benchmark, built through the normal pipeline (DemoSeeder `make demo`, S14-01,
 * and the public showcase `ait:demo:showcase`, S14-05):
 *
 * - the project (owned by `$owner`) with the benchmark's openapi.yaml parsed (M06/M07) and the
 *   ground-truth business rules (M08);
 * - a RULE suite generated synchronously like an M11 job over every endpoint, and one COMPLETED run
 *   on the clean benchmark (M12/M13);
 * - a suite replaying recorded LLM/HYBRID jobs of the official experiment without calling an LLM
 *   (cases validated again, deduplicated and stored by the web, verbatim llm_call_logs for M10/M11),
 *   and one COMPLETED run with a sandbox reset before every case, as in the experiment (ADR-0006).
 *   With `recordedRunUsesAuthFixture` that run also uses the experiment credential mechanism (the
 *   ground-truth auth fixture), since the recorded cases call secured operations with
 *   `{{auth_token_<role>}}` or rely on the default Authorization header.
 *
 * Idempotent and self-repairing: every part an earlier call created is kept, a missing or deleted
 * part is created again. A sandbox that cannot be reset skips the runs with a warning (next call).
 *
 * @throws ApiException When the ground truth or openapi.yaml of the benchmark cannot be read.
 */
class SeedBenchmarkDemoProjectAction
{
    public function __construct(
        private readonly GroundTruthLoader $loader,
        private readonly CreateApiSpecAction $createSpec,
        private readonly CreateBusinessRuleAction $createRule,
        private readonly ExecuteRuleGenerationAction $generate,
        private readonly ExecuteTestRunAction $executeRun,
        private readonly BenchmarkSandbox $sandbox,
        private readonly TargetAllowlistService $allowlist,
        private readonly ImportRecordedLlmJobAction $importRecorded,
        private readonly ConvergeRecordedLlmJobsAction $convergeRecorded,
    ) {}

    public function execute(User $owner, DemoProjectPlan $plan): DemoProjectResult
    {
        $benchmark = $this->loader->load($plan->benchmarkSlug);
        $specContent = $this->loader->openApiContent($plan->benchmarkSlug);
        if ($specContent === null) {
            throw new ApiException(ErrorCode::NotFound, "Không tìm thấy benchmark/{$plan->benchmarkSlug}/openapi.yaml.");
        }

        $warnings = [];
        $project = $this->project($owner, $plan, $benchmark->baseUrl);
        $spec = $this->spec($project, $specContent, $plan->specVersion);
        $this->businessRules($project, $spec, $benchmark);

        // With the ground-truth auth fixture (ADR-0006) RULE generates every technique on secured endpoints
        // (plus AUTH cases) and both runs send the default credentials, as in the official experiment.
        $auth = $plan->recordedRunUsesAuthFixture ? $benchmark->auth : null;

        $ruleSuite = $this->suite($project, $spec, $owner, $plan->ruleSuiteName, $plan->ruleSuiteDescription, $plan->legacySuiteDescriptions);
        $this->generateRuleCases($ruleSuite, $spec, $owner, $plan->ruleMaxCasesPerEndpoint, $auth, $warnings);
        $ran = $this->completedRun($ruleSuite, $owner, $benchmark->baseUrl, $plan->ruleRunResetPerCase, $auth, $plan->retryHint, $warnings);

        $recordedSuite = $this->suite($project, $spec, $owner, $plan->recordedSuiteName, $plan->recordedSuiteDescription, $plan->legacySuiteDescriptions);
        $this->importRecordedJobs($recordedSuite, $owner, $plan, $warnings);
        // Replays imported by an earlier run get today's messages too (QA S14-06 round 3).
        $this->convergeRecorded->execute($recordedSuite);
        $ran = $this->completedRun($recordedSuite, $owner, $benchmark->baseUrl, true, $auth, $plan->retryHint, $warnings) || $ran;

        if ($ran) {
            // The seeded runs changed the sandbox data: leave it as seeded for the next live run.
            try {
                $this->sandbox->reset(rtrim($benchmark->baseUrl, '/'));
            } catch (Throwable) {
                // A failing run already reported it.
            }
        }

        return new DemoProjectResult($project, [$ruleSuite, $recordedSuite], $warnings);
    }

    private function project(User $owner, DemoProjectPlan $plan, string $baseUrl): Project
    {
        $project = Project::withTrashed()->firstOrNew(['name' => $plan->projectName, 'owner_id' => $owner->id]);
        if (! $project->exists) {
            $project->fill(['description' => $plan->projectDescription, 'default_target_url' => $baseUrl])->save();
        } elseif ($project->trashed()) {
            $project->restore(); // a visitor deleted the demo project: bring it back with its data
        }

        return $project;
    }

    private function spec(Project $project, string $content, string $version): ApiSpec
    {
        $existing = ApiSpec::query()
            ->where('project_id', $project->id)
            ->where('checksum', OpenApiParser::checksum($content))
            ->first();

        return $existing instanceof ApiSpec
            ? $existing
            : $this->createSpec->execute($project, $version, $content, 'openapi.yaml', ApiSpecFormat::Yaml);
    }

    private function businessRules(Project $project, ApiSpec $spec, GroundTruthBenchmark $benchmark): void
    {
        foreach ($benchmark->businessRules as $rule) {
            if ($project->businessRules()->where('code', $rule['code'])->exists()) {
                continue;
            }
            $endpointId = null;
            if ($rule['method'] !== null && $rule['path'] !== null) {
                $endpointId = $spec->endpoints()
                    ->where('method', $rule['method'])
                    ->where('path', $rule['path'])
                    ->value('id');
            }
            $this->createRule->execute($project, [
                'code' => $rule['code'],
                'endpoint_id' => $endpointId === null ? null : (int) $endpointId,
                'description' => $rule['description'],
            ]);
        }
    }

    /**
     * @param  list<string>  $legacyDescriptions  Earlier canonical descriptions replaced by $description.
     */
    private function suite(Project $project, ApiSpec $spec, User $owner, string $name, string $description, array $legacyDescriptions = []): TestSuite
    {
        $suite = TestSuite::query()->firstOrCreate(
            ['project_id' => $project->id, 'name' => $name],
            ['api_spec_id' => $spec->id, 'description' => $description, 'created_by' => $owner->id],
        );
        if ($suite->description !== $description && in_array($suite->description, $legacyDescriptions, true)) {
            $suite->update(['description' => $description]);
        }

        return $suite;
    }

    /**
     * @param  list<string>  $warnings
     */
    private function generateRuleCases(TestSuite $suite, ApiSpec $spec, User $owner, int $maxCasesPerEndpoint, ?AuthFixture $auth, array &$warnings): void
    {
        if ($this->hasFinishedJob($suite, GenerationStrategy::Rule)) {
            return;
        }

        // Same params as an M11 RULE job over every endpoint; executed synchronously instead of
        // being queued so the demo has data even when no worker is running.
        $job = GenerationJob::create([
            'project_id' => $suite->project_id,
            'api_spec_id' => $spec->id,
            'test_suite_id' => $suite->id,
            'strategy' => GenerationStrategy::Rule,
            'status' => GenerationJobStatus::Queued,
            'params' => array_filter([
                'endpoint_ids' => $spec->endpoints()->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
                'techniques' => [],
                'max_cases_per_endpoint' => $maxCasesPerEndpoint,
                // Role names only (never tokens), the same params an experiment RULE job carries (ADR-0006).
                'auth' => $auth?->toPublicArray(),
            ], static fn (mixed $value): bool => $value !== null),
            'created_by' => $owner->id,
        ]);

        $job = $this->generate->execute($job->id);
        if ($job->status === GenerationJobStatus::Failed) {
            $warnings[] = 'Job sinh test RULE của demo thất bại: '.(string) $job->error;
        }
    }

    /**
     * Each recorded job goes through the web validation, dedup and persistence of a live LLM job;
     * only the ai-service response comes from the official results instead of an LLM.
     *
     * @param  list<string>  $warnings
     */
    private function importRecordedJobs(TestSuite $suite, User $owner, DemoProjectPlan $plan, array &$warnings): void
    {
        $missing = array_values(array_filter(
            $plan->recordedStrategies,
            fn (GenerationStrategy $strategy): bool => ! $this->hasFinishedJob($suite, $strategy),
        ));
        $unmarked = $this->unmarkedReplays($suite, $plan->recordedStrategies);
        if ($missing === [] && $unmarked === []) {
            return;
        }

        try {
            /** @var array<string, RecordedLlmJob> $recorded */
            $recorded = ($plan->recordedJobs)();
        } catch (Throwable $e) {
            $warnings[] = 'Bỏ qua job LLM bản ghi: '.$e->getMessage();

            return;
        }

        $this->markReplays($unmarked, $recorded);

        foreach ($missing as $strategy) {
            $job = $recorded[$strategy->value] ?? null;
            if (! $job instanceof RecordedLlmJob) {
                $warnings[] = "Bỏ qua job LLM bản ghi: không có job {$strategy->value} trong kết quả chính thức.";

                continue;
            }

            try {
                $imported = $this->importRecorded->execute($suite, $owner, $job);
            } catch (Throwable $e) {
                $warnings[] = 'Bỏ qua job LLM bản ghi: '.$e->getMessage();

                continue;
            }
            if ($imported->status === GenerationJobStatus::Failed) {
                $warnings[] = "Job {$strategy->value} bản ghi của demo thất bại: ".(string) $imported->error;
            }
        }
    }

    /**
     * Replays imported before they were marked (QA S14-06): the first finished job of each recorded
     * strategy in the suite (jobs a visitor starts later have higher ids) without the marker.
     *
     * @param  list<GenerationStrategy>  $strategies
     * @return list<GenerationJob>
     */
    private function unmarkedReplays(TestSuite $suite, array $strategies): array
    {
        $jobs = [];
        foreach ($strategies as $strategy) {
            $first = GenerationJob::query()
                ->where('test_suite_id', $suite->id)
                ->where('strategy', $strategy->value)
                ->whereIn('status', [GenerationJobStatus::Succeeded->value, GenerationJobStatus::Partial->value])
                ->orderBy('id')
                ->first();
            if ($first instanceof GenerationJob && ! $first->isReplayed()) {
                $jobs[] = $first;
            }
        }

        return $jobs;
    }

    /**
     * Marks the jobs that are the replay of a recorded job: same model and seed, and exactly the
     * recorded LLM calls (a job generated live by a visitor is left alone).
     *
     * @param  list<GenerationJob>  $jobs
     * @param  array<string, RecordedLlmJob>  $recorded
     */
    private function markReplays(array $jobs, array $recorded): void
    {
        foreach ($jobs as $job) {
            $record = $recorded[$job->strategy->value] ?? null;
            if (! $record instanceof RecordedLlmJob
                || ($job->params['model'] ?? null) !== $record->params['model']
                || ($job->params['seed'] ?? null) !== $record->params['seed']
                || $job->llmCallLogs()->count() !== count($record->response->llmCalls)) {
                continue;
            }
            $job->update(['params' => [...$job->params, GenerationJob::RECORDED_SOURCE_PARAM => $record->sourceJobId]]);
        }
    }

    private function hasFinishedJob(TestSuite $suite, GenerationStrategy $strategy): bool
    {
        return GenerationJob::query()
            ->where('test_suite_id', $suite->id)
            ->where('strategy', $strategy->value)
            ->whereIn('status', [GenerationJobStatus::Succeeded->value, GenerationJobStatus::Partial->value])
            ->exists();
    }

    /**
     * @param  bool  $resetPerCase  Reset the sandbox before every case (experiment case isolation,
     *                              ADR-0006 addendum), so the verdicts are those of the official clean run.
     * @param  list<string>  $warnings
     * @return bool Whether a run was executed by this call.
     */
    private function completedRun(
        TestSuite $suite,
        User $owner,
        string $targetBaseUrl,
        bool $resetPerCase,
        ?AuthFixture $auth,
        string $retryHint,
        array &$warnings,
    ): bool {
        $targetBaseUrl = rtrim($targetBaseUrl, '/');
        $alreadyRun = $suite->testRuns()->where('status', TestRunStatus::Completed->value)->exists();
        $hasCases = $suite->testCases()
            ->whereIn('status', [TestCaseStatus::Valid->value, TestCaseStatus::Approved->value])
            ->exists();
        if ($alreadyRun || ! $hasCases) {
            return false;
        }

        if (! $this->allowlist->isAllowed($targetBaseUrl)) {
            $warnings[] = "Bỏ qua lần chạy demo: {$targetBaseUrl} không thuộc allowlist (ALLOWED_TARGET_HOSTS).";

            return false;
        }

        // Restore the seed data so the demo run is reproducible; an unreachable sandbox means the
        // stack is not up, and a run full of ERROR verdicts would mislead the audience.
        try {
            $this->sandbox->reset($targetBaseUrl);
        } catch (Throwable $e) {
            $warnings[] = trim("Bỏ qua lần chạy demo: không reset được {$targetBaseUrl} ({$e->getMessage()}). {$retryHint}");

            return false;
        }

        /** @var TestRun $run */
        $run = $suite->testRuns()->create([
            'target_base_url' => $targetBaseUrl,
            'benchmark_variant' => null,
            'status' => TestRunStatus::Queued,
            'totals' => null,
            'triggered_by' => $owner->id,
        ]);

        $betweenCases = $resetPerCase ? fn () => $this->sandbox->reset($targetBaseUrl) : null;
        try {
            $this->executeRun->execute($run->id, auth: $auth, betweenCases: $betweenCases);
        } catch (Throwable $e) {
            // A reset failing between cases aborts the run (§4.3: RUNNING → FAILED).
            $this->executeRun->markFailed($run->id);
            $warnings[] = "Lần chạy demo của suite \"{$suite->name}\" thất bại: {$e->getMessage()}";
        }

        return true;
    }
}
