<?php

namespace Database\Seeders;

use App\Actions\ApiSpecs\CreateApiSpecAction;
use App\Actions\BusinessRules\CreateBusinessRuleAction;
use App\Actions\Generation\ExecuteRuleGenerationAction;
use App\Actions\Generation\ImportRecordedLlmJobAction;
use App\Actions\Runs\ExecuteTestRunAction;
use App\Contracts\BenchmarkSandbox;
use App\Enums\ApiSpecFormat;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\TestCaseStatus;
use App\Enums\TestRunStatus;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\ApiSpec;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\Benchmark\GroundTruthLoader;
use App\Services\Benchmark\RecordedLlmJobLoader;
use App\Services\Target\TargetAllowlistService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/**
 * Demo data for the ≤ 15-minute demo script (S14-01, docs/demo/kich-ban-demo.md).
 *
 * `php artisan db:seed --class=DemoSeeder` (or `make demo` / `make demo-seed`) creates:
 * - a TESTER account `demo@ait.local` (password from DEMO_PASSWORD, otherwise generated and printed once);
 * - the project "Demo – Simple Store" with benchmark simple-store's openapi.yaml parsed (M06/M07);
 * - the benchmark's business rules (M08);
 * - a suite with RULE test cases generated synchronously through the normal pipeline (M09–M11);
 * - one COMPLETED run against the clean simple-store sandbox (M12/M13);
 * - a second suite holding the LLM job (seed 42) of the official experiment, replayed from
 *   benchmark/results/benchmark-v1.0/simple-store/db.sql.gz without calling an LLM: real LLM cases
 *   and their verbatim llm_call_logs for M10/M11 on a machine without Ollama (S13-03), and one
 *   COMPLETED run of it on the clean sandbox with a reset before every case, as in the experiment
 *   (ADR-0006), whose FAIL verdicts are false positives of the LLM's oracle (M12).
 *
 * Idempotent: every step reuses what an earlier run created. When the sandbox cannot be reset
 * (stack not up), the runs are skipped with a warning and are created by the next invocation.
 * Ends with a short summary of what the demo has (or why a part was skipped).
 */
class DemoSeeder extends Seeder
{
    public const EMAIL = 'demo@ait.local';

    public const PROJECT_NAME = 'Demo – Simple Store';

    public const SUITE_NAME = 'Demo – RULE baseline';

    public const LLM_SUITE_NAME = 'Demo – LLM (bản ghi thực nghiệm)';

    public const BENCHMARK_SLUG = 'simple-store';

    public const SPEC_VERSION = 'v1.0 (benchmark)';

    /** Seed of repetition 1 of the official experiment (experiment-design.md §4.2). */
    public const RECORDED_SEED = 42;

    /** Keeps the seeded run short enough for the demo (≈ 6 cases × 10 endpoints). */
    public const MAX_CASES_PER_ENDPOINT = 6;

    public function run(
        GroundTruthLoader $loader,
        CreateApiSpecAction $createSpec,
        CreateBusinessRuleAction $createRule,
        ExecuteRuleGenerationAction $generate,
        ExecuteTestRunAction $executeRun,
        BenchmarkSandbox $sandbox,
        TargetAllowlistService $allowlist,
        RecordedLlmJobLoader $recordedJobs,
        ImportRecordedLlmJobAction $importRecorded,
    ): void {
        // ADR-0007: production has no demo/default account. Seeding one there needs an explicit --force.
        if (app()->environment('production') && ! $this->forced()) {
            $this->warn('Từ chối tạo dữ liệu demo (tài khoản '.self::EMAIL.') khi APP_ENV=production. '
                .'Chạy lại với db:seed --class=DemoSeeder --force nếu thật sự cần.');

            return;
        }

        try {
            $benchmark = $loader->load(self::BENCHMARK_SLUG);
            $specContent = $loader->openApiContent(self::BENCHMARK_SLUG);
        } catch (ApiException $e) {
            $this->warn('Bỏ qua dữ liệu demo: '.$e->getMessage());

            return;
        }
        if ($specContent === null) {
            $this->warn('Bỏ qua dữ liệu demo: không tìm thấy benchmark/'.self::BENCHMARK_SLUG.'/openapi.yaml.');

            return;
        }

        $tester = $this->tester();

        $project = Project::query()->firstOrCreate(
            ['name' => self::PROJECT_NAME, 'owner_id' => $tester->id],
            [
                'description' => 'Dự án demo: benchmark Simple Store (danh mục, sản phẩm) – dữ liệu tạo bởi DemoSeeder.',
                'default_target_url' => $benchmark->baseUrl,
            ],
        );

        $spec = $this->spec($project, $specContent, $createSpec);

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
            $createRule->execute($project, [
                'code' => $rule['code'],
                'endpoint_id' => $endpointId === null ? null : (int) $endpointId,
                'description' => $rule['description'],
            ]);
        }

        $suite = TestSuite::query()->firstOrCreate(
            ['project_id' => $project->id, 'name' => self::SUITE_NAME],
            [
                'api_spec_id' => $spec->id,
                'description' => 'Test case RULE sinh sẵn cho demo; dùng để so sánh với job LLM/HYBRID sinh trực tiếp.',
                'created_by' => $tester->id,
            ],
        );

        $this->generateRuleCases($suite, $spec, $tester, $generate);
        $ran = $this->completedRun($suite, $tester, $benchmark->baseUrl, $executeRun, $sandbox, $allowlist, resetPerCase: false);

        $llmSuite = TestSuite::query()->firstOrCreate(
            ['project_id' => $project->id, 'name' => self::LLM_SUITE_NAME],
            [
                'api_spec_id' => $spec->id,
                'description' => 'Job LLM seed '.self::RECORDED_SEED.' (lần lặp 1) của thực nghiệm chính thức benchmark-v1.0, nạp lại từ '
                    .'benchmark/results/benchmark-v1.0/'.self::BENCHMARK_SLUG.'/db.sql.gz: case và log LLM là bản ghi thật, '
                    .'không gọi LLM khi tạo. Dùng khi máy demo không có Ollama.',
                'created_by' => $tester->id,
            ],
        );
        $this->importRecordedLlmJob($llmSuite, $tester, $recordedJobs, $importRecorded);
        $ran = $this->completedRun($llmSuite, $tester, $benchmark->baseUrl, $executeRun, $sandbox, $allowlist, resetPerCase: true) || $ran;

        if ($ran) {
            // The seeded runs changed the sandbox data: leave it as seeded for the live M12 run.
            try {
                $sandbox->reset(rtrim($benchmark->baseUrl, '/'));
            } catch (Throwable) {
                // A failing run already reported it; the demo script resets the sandbox again at T−5.
            }
        }

        $this->summary($project, [$suite, $llmSuite]);
    }

    private function tester(): User
    {
        $existing = User::query()->where('email', self::EMAIL)->first();
        if ($existing instanceof User) {
            return $existing;
        }

        $configured = config('ait.demo_password');
        $password = is_string($configured) ? $configured : '';
        $generated = $password === '';
        if ($generated) {
            $password = Str::password(20, symbols: false);
        }

        $tester = new User;
        $tester->forceFill([
            'email' => self::EMAIL,
            'name' => 'Tester Demo',
            'password' => Hash::make($password),
            'role' => UserRole::Tester,
            'is_active' => true,
        ])->save();

        if ($generated) {
            $this->warn('DEMO_PASSWORD is empty; generated password for '.self::EMAIL.': '.$password);
        }

        return $tester;
    }

    private function spec(Project $project, string $content, CreateApiSpecAction $createSpec): ApiSpec
    {
        $existing = ApiSpec::query()
            ->where('project_id', $project->id)
            ->where('checksum', hash('sha256', $content))
            ->first();

        return $existing instanceof ApiSpec
            ? $existing
            : $createSpec->execute($project, self::SPEC_VERSION, $content, 'openapi.yaml', ApiSpecFormat::Yaml);
    }

    private function generateRuleCases(TestSuite $suite, ApiSpec $spec, User $tester, ExecuteRuleGenerationAction $generate): void
    {
        if ($this->hasFinishedJob($suite)) {
            return;
        }

        // Same params as an M11 RULE job over every endpoint; executed synchronously instead of
        // being queued so the demo starts with data even when no worker is running.
        $job = GenerationJob::create([
            'project_id' => $suite->project_id,
            'api_spec_id' => $spec->id,
            'test_suite_id' => $suite->id,
            'strategy' => GenerationStrategy::Rule,
            'status' => GenerationJobStatus::Queued,
            'params' => [
                'endpoint_ids' => $spec->endpoints()->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
                'techniques' => [],
                'max_cases_per_endpoint' => self::MAX_CASES_PER_ENDPOINT,
            ],
            'created_by' => $tester->id,
        ]);

        $job = $generate->execute($job->id);
        if ($job->status === GenerationJobStatus::Failed) {
            $this->warn('Job sinh test RULE của demo thất bại: '.(string) $job->error);
        }
    }

    /**
     * The recorded job goes through the web validation, dedup and persistence of a live LLM job;
     * only the ai-service response comes from the official dump instead of an LLM.
     */
    private function importRecordedLlmJob(
        TestSuite $suite,
        User $tester,
        RecordedLlmJobLoader $recordedJobs,
        ImportRecordedLlmJobAction $importRecorded,
    ): void {
        if ($this->hasFinishedJob($suite)) {
            return;
        }

        try {
            $recorded = $recordedJobs->load(self::BENCHMARK_SLUG, GenerationStrategy::Llm, self::RECORDED_SEED);
            $job = $importRecorded->execute($suite, $tester, $recorded);
        } catch (Throwable $e) {
            $this->warn('Bỏ qua job LLM bản ghi: '.$e->getMessage());

            return;
        }
        if ($job->status === GenerationJobStatus::Failed) {
            $this->warn('Job LLM bản ghi của demo thất bại: '.(string) $job->error);
        }
    }

    private function hasFinishedJob(TestSuite $suite): bool
    {
        return GenerationJob::query()
            ->where('test_suite_id', $suite->id)
            ->whereIn('status', [GenerationJobStatus::Succeeded->value, GenerationJobStatus::Partial->value])
            ->exists();
    }

    /**
     * @param  bool  $resetPerCase  Reset the sandbox before every case (experiment case isolation,
     *                              ADR-0006 addendum), so the verdicts are those of the official clean run.
     * @return bool Whether a run was executed by this call.
     */
    private function completedRun(
        TestSuite $suite,
        User $tester,
        string $targetBaseUrl,
        ExecuteTestRunAction $executeRun,
        BenchmarkSandbox $sandbox,
        TargetAllowlistService $allowlist,
        bool $resetPerCase,
    ): bool {
        $targetBaseUrl = rtrim($targetBaseUrl, '/');
        $alreadyRun = $suite->testRuns()->where('status', TestRunStatus::Completed->value)->exists();
        $hasCases = $suite->testCases()
            ->whereIn('status', [TestCaseStatus::Valid->value, TestCaseStatus::Approved->value])
            ->exists();
        if ($alreadyRun || ! $hasCases) {
            return false;
        }

        if (! $allowlist->isAllowed($targetBaseUrl)) {
            $this->warn("Bỏ qua lần chạy demo: {$targetBaseUrl} không thuộc allowlist (ALLOWED_TARGET_HOSTS).");

            return false;
        }

        // Restore the seed data so the demo run is reproducible; an unreachable sandbox means the
        // stack is not up, and a run full of ERROR verdicts would mislead the audience.
        try {
            $sandbox->reset($targetBaseUrl);
        } catch (Throwable $e) {
            $this->warn("Bỏ qua lần chạy demo: không reset được {$targetBaseUrl} ({$e->getMessage()}). Chạy lại `make demo-seed` sau khi `make up`.");

            return false;
        }

        /** @var TestRun $run */
        $run = $suite->testRuns()->create([
            'target_base_url' => $targetBaseUrl,
            'benchmark_variant' => null,
            'status' => TestRunStatus::Queued,
            'totals' => null,
            'triggered_by' => $tester->id,
        ]);

        $betweenCases = $resetPerCase ? static fn () => $sandbox->reset($targetBaseUrl) : null;
        try {
            $executeRun->execute($run->id, betweenCases: $betweenCases);
        } catch (Throwable $e) {
            // A reset failing between cases aborts the run (§4.3: RUNNING → FAILED).
            $executeRun->markFailed($run->id);
            $this->warn("Lần chạy demo của suite \"{$suite->name}\" thất bại: {$e->getMessage()}");
        }

        return true;
    }

    /**
     * @param  list<TestSuite>  $suites
     */
    private function summary(Project $project, array $suites): void
    {
        $this->info("Dữ liệu demo: project \"{$project->name}\" (#{$project->id}), tài khoản ".self::EMAIL.'.');
        foreach ($suites as $suite) {
            $cases = $suite->testCases()
                ->whereIn('status', [TestCaseStatus::Valid->value, TestCaseStatus::Approved->value])
                ->count();
            $run = $suite->testRuns()->where('status', TestRunStatus::Completed->value)->orderByDesc('id')->first();
            $runText = $run instanceof TestRun
                ? sprintf(
                    'lần chạy #%d COMPLETED (%d PASS, %d FAIL, %d ERROR)',
                    $run->id,
                    (int) ($run->totals['pass'] ?? 0),
                    (int) ($run->totals['fail'] ?? 0),
                    (int) ($run->totals['error'] ?? 0),
                )
                : 'chưa có lần chạy (xem cảnh báo phía trên)';
            $this->info("- Suite \"{$suite->name}\" (#{$suite->id}): {$cases} case chạy được; {$runText}.");
        }
    }

    private function forced(): bool
    {
        return $this->command !== null
            && $this->command->getDefinition()->hasOption('force')
            && (bool) $this->command->option('force');
    }

    private function warn(string $message): void
    {
        // Console only: the generated password must never reach the log files (docs/DESIGN.md §9).
        $this->command->warn($message);
    }

    private function info(string $message): void
    {
        $this->command->info($message);
    }
}
