<?php

namespace Tests\Feature\Database;

use App\Contracts\AiGeneratorClient;
use App\Contracts\BenchmarkSandbox;
use App\Contracts\TargetHostResolver;
use App\Contracts\TargetHttpClient;
use App\Enums\ApiSpecStatus;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\TestCaseSource;
use App\Enums\TestCaseStatus;
use App\Enums\TestRunStatus;
use App\Enums\UserRole;
use App\Models\ApiSpec;
use App\Models\Benchmark;
use App\Models\BenchmarkBug;
use App\Models\BusinessRule;
use App\Models\GenerationJob;
use App\Models\LlmCallLog;
use App\Models\Project;
use App\Models\TestCase;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\Ai\FakeAiGeneratorClient;
use App\Services\Benchmark\FakeBenchmarkSandbox;
use App\Services\Benchmark\GroundTruthLoader;
use App\Services\Benchmark\RecordedLlmJobLoader;
use App\Services\Support\PgDumpReader;
use App\Services\Target\FakeTargetHostResolver;
use App\Services\Target\FakeTargetHttpClient;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use RuntimeException;
use Tests\TestCase as FeatureTestCase;

class DemoSeederTest extends FeatureTestCase
{
    use RefreshDatabase;

    private FakeTargetHttpClient $target;

    protected function setUp(): void
    {
        parent::setUp();

        // simple-store is the Docker alias of the clean benchmark (ground-truth base_url).
        $this->app->instance(TargetHostResolver::class, new FakeTargetHostResolver([
            'simple-store' => ['127.0.0.1'],
        ]));
        $this->target = new FakeTargetHttpClient;
        $this->app->instance(TargetHttpClient::class, $this->target);
        config(['ait.demo_password' => 'demo-test-only-pw']);
    }

    public function test_it_seeds_a_ready_to_demo_project_with_rule_cases_and_a_completed_run(): void
    {
        $this->seed(DemoSeeder::class);

        $tester = User::query()->where('email', DemoSeeder::EMAIL)->firstOrFail();
        $this->assertSame(UserRole::Tester, $tester->role);
        $this->assertTrue($tester->is_active);
        $this->assertTrue(Hash::check('demo-test-only-pw', $tester->password));

        $project = Project::query()->where('name', DemoSeeder::PROJECT_NAME)->firstOrFail();
        $this->assertSame($tester->id, $project->owner_id);
        $this->assertSame('http://simple-store:8000', $project->default_target_url);

        $spec = ApiSpec::query()->where('project_id', $project->id)->firstOrFail();
        $this->assertSame(ApiSpecStatus::Parsed, $spec->status);
        $this->assertGreaterThanOrEqual(9, $spec->endpoints()->count());

        $rules = BusinessRule::query()->where('project_id', $project->id)->get();
        $this->assertCount(8, $rules);
        $this->assertTrue($rules->every(fn (BusinessRule $rule): bool => $rule->endpoint_id !== null));

        $suite = TestSuite::query()->where('project_id', $project->id)->where('name', DemoSeeder::SUITE_NAME)->firstOrFail();
        $job = GenerationJob::query()->where('test_suite_id', $suite->id)->firstOrFail();
        $this->assertSame(GenerationStrategy::Rule, $job->strategy);
        $this->assertContains($job->status, [GenerationJobStatus::Succeeded, GenerationJobStatus::Partial]);

        $runnable = TestCase::query()
            ->where('test_suite_id', $suite->id)
            ->where('status', TestCaseStatus::Valid->value)
            ->count();
        $this->assertGreaterThan(0, $runnable);
        $this->assertSame(0, TestCase::query()->where('test_suite_id', $suite->id)->where('source', '!=', TestCaseSource::Rule->value)->count());

        $run = TestRun::query()->where('test_suite_id', $suite->id)->sole();
        $this->assertSame(TestRunStatus::Completed, $run->status);
        $this->assertSame('http://simple-store:8000', $run->target_base_url);
        $this->assertSame($tester->id, $run->triggered_by);
        $this->assertNull($run->benchmark_variant);
        $this->assertSame($runnable, $run->totals['total'] ?? null);
        $this->assertSame($runnable, TestResult::query()->where('test_run_id', $run->id)->count());
        $this->assertNotEmpty($this->target->sent());
    }

    public function test_it_is_idempotent(): void
    {
        $this->seed(DemoSeeder::class);
        $counts = $this->counts();
        $passwordHash = User::query()->where('email', DemoSeeder::EMAIL)->value('password');

        config(['ait.demo_password' => 'another-value']);
        $this->seed(DemoSeeder::class);

        $this->assertSame($counts, $this->counts());
        $this->assertSame($passwordHash, User::query()->where('email', DemoSeeder::EMAIL)->value('password'));
    }

    public function test_an_empty_demo_password_is_generated_and_printed_once(): void
    {
        config(['ait.demo_password' => '']);

        $this->seedCommand()
            ->expectsOutputToContain('generated password for '.DemoSeeder::EMAIL)
            ->assertSuccessful();

        $this->seedCommand()
            ->doesntExpectOutputToContain('generated password')
            ->assertSuccessful();

        $tester = User::query()->where('email', DemoSeeder::EMAIL)->firstOrFail();
        $this->assertFalse(Hash::check('', $tester->password));
    }

    public function test_the_run_is_skipped_while_the_sandbox_is_down_and_created_on_the_next_seed(): void
    {
        if (! is_file(app(RecordedLlmJobLoader::class)->dumpPath('simple-store'))) {
            $this->markTestSkipped('The database dumps of benchmark/results/benchmark-v1.0 are not part of this release.');
        }
        $this->app->instance(BenchmarkSandbox::class, new class implements BenchmarkSandbox
        {
            public function ensureAvailable(Benchmark $benchmark): void {}

            public function variantBaseUrl(Benchmark $benchmark, ?BenchmarkBug $bug): string
            {
                return 'http://simple-store:8000';
            }

            public function reset(string $baseUrl): void
            {
                throw new RuntimeException('Connection refused');
            }
        });

        $this->seedCommand()
            ->expectsOutputToContain('Bỏ qua lần chạy demo')
            ->assertSuccessful();

        $this->assertSame(0, TestRun::query()->count());
        $this->assertGreaterThan(0, TestCase::query()->where('status', TestCaseStatus::Valid->value)->count());

        $this->app->instance(BenchmarkSandbox::class, new FakeBenchmarkSandbox);
        $this->seed(DemoSeeder::class);

        // One COMPLETED run per demo suite (RULE baseline + recorded LLM job).
        $this->assertSame([TestRunStatus::Completed, TestRunStatus::Completed], TestRun::query()->orderBy('id')->get()->pluck('status')->all());
    }

    public function test_the_run_is_skipped_when_the_target_is_not_allowlisted(): void
    {
        config(['ait.allowed_target_hosts' => ['localhost']]);

        $this->seed(DemoSeeder::class);

        $this->assertSame(0, TestRun::query()->count());
        $this->assertSame([DemoSeeder::SUITE_NAME, DemoSeeder::LLM_SUITE_NAME], TestSuite::query()->orderBy('id')->pluck('name')->all());
    }

    /**
     * S13-03: the demo has real LLM cases and llm_call_logs without Ollama: the LLM job seed 42 of the
     * official experiment, replayed from benchmark/results/benchmark-v1.0/simple-store/db.sql.gz.
     */
    public function test_it_replays_the_official_llm_job_into_its_own_suite_without_calling_an_llm(): void
    {
        if (! is_file(app(RecordedLlmJobLoader::class)->dumpPath('simple-store'))) {
            $this->markTestSkipped('The database dumps of benchmark/results/benchmark-v1.0 are not part of this release.');
        }
        $sandbox = new FakeBenchmarkSandbox;
        $this->app->instance(BenchmarkSandbox::class, $sandbox);
        $ai = new FakeAiGeneratorClient;
        $this->app->instance(AiGeneratorClient::class, $ai);

        $this->seed(DemoSeeder::class);

        $suite = TestSuite::query()->where('name', DemoSeeder::LLM_SUITE_NAME)->firstOrFail();
        $ruleSuite = TestSuite::query()->where('name', DemoSeeder::SUITE_NAME)->firstOrFail();
        $this->assertSame($ruleSuite->project_id, $suite->project_id);
        $this->assertSame($ruleSuite->api_spec_id, $suite->api_spec_id);
        $this->assertStringContainsString('benchmark-v1.0/simple-store/db.sql.gz', (string) $suite->description);

        $job = GenerationJob::query()->where('test_suite_id', $suite->id)->sole();
        $this->assertSame(GenerationStrategy::Llm, $job->strategy);
        $this->assertSame(GenerationJobStatus::Succeeded, $job->status);
        $this->assertNotNull($job->started_at);
        $this->assertNotNull($job->finished_at);
        $this->assertEquals(['generated' => 24, 'valid' => 23, 'invalid' => 1, 'duplicates' => 0, 'repaired' => 4], $job->stats);
        $this->assertSame(42, $job->params['seed'] ?? null);
        $this->assertSame('qwen2.5-coder:7b', $job->params['model'] ?? null);
        $this->assertSame(
            $suite->apiSpec->endpoints()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            $job->params['endpoint_ids'] ?? null,
        );
        $this->assertSame([], $ai->recordedRequests(), 'the recorded job must not call ai-service');

        $cases = TestCase::query()->where('test_suite_id', $suite->id)->get();
        $this->assertCount(24, $cases);
        $this->assertTrue($cases->every(fn (TestCase $case): bool => $case->source === TestCaseSource::Llm));
        $this->assertSame(23, $cases->where('status', TestCaseStatus::Valid)->count());
        $invalid = $cases->firstWhere('status', TestCaseStatus::Invalid);
        $this->assertInstanceOf(TestCase::class, $invalid);
        $this->assertNotEmpty($invalid->validation_errors);
        $this->assertSame(2, $invalid->repair_attempts);

        // D09: every GENERATE/REPAIR call of the recorded job, verbatim, on the job (M10/M11).
        $logs = LlmCallLog::query()->where('generation_job_id', $job->id)->orderBy('id')->get();
        $this->assertCount(15, $logs);
        $this->assertSame(['GENERATE' => 9, 'REPAIR' => 6], $logs->countBy(fn (LlmCallLog $log): string => $log->purpose->value)->sortKeys()->all());
        $this->assertTrue($logs->every(fn (LlmCallLog $log): bool => $log->model === 'qwen2.5-coder:7b' && $log->seed === 42 && $log->response_raw !== ''));
        $first = $logs->first();
        $this->assertInstanceOf(LlmCallLog::class, $first);
        $this->assertSame(1323, $first->prompt_tokens);
        $this->assertSame(23301, $first->latency_ms);

        // One COMPLETED run on the clean sandbox, reset before every case like the experiment.
        $run = TestRun::query()->where('test_suite_id', $suite->id)->sole();
        $this->assertSame(TestRunStatus::Completed, $run->status);
        $this->assertSame('http://simple-store:8000', $run->target_base_url);
        $this->assertSame(23, $run->totals['total'] ?? null);
        // 1 reset before the RULE run, 1 + 22 for the LLM run, 1 to leave the sandbox as seeded.
        $this->assertCount(1 + 1 + 22 + 1, $sandbox->resets);
    }

    public function test_the_llm_suite_is_skipped_with_a_warning_when_the_official_dump_is_missing(): void
    {
        $this->app->instance(RecordedLlmJobLoader::class, new class(app(GroundTruthLoader::class), new PgDumpReader) extends RecordedLlmJobLoader
        {
            public function dumpPath(string $slug): string
            {
                return '/nonexistent/db.sql.gz';
            }
        });

        $this->seedCommand()
            ->expectsOutputToContain('Bỏ qua job LLM bản ghi')
            ->assertSuccessful();

        $llmSuite = TestSuite::query()->where('name', DemoSeeder::LLM_SUITE_NAME)->firstOrFail();
        $this->assertSame(0, $llmSuite->testCases()->count());
        $this->assertSame(0, $llmSuite->testRuns()->count());
        $this->assertSame(1, TestRun::query()->count(), 'the RULE part of the demo is unaffected');
    }

    public function test_it_prints_a_summary_of_the_demo_data(): void
    {
        if (! is_file(app(RecordedLlmJobLoader::class)->dumpPath('simple-store'))) {
            $this->markTestSkipped('The database dumps of benchmark/results/benchmark-v1.0 are not part of this release.');
        }
        $this->seedCommand()
            ->expectsOutputToContain('Dữ liệu demo: project "'.DemoSeeder::PROJECT_NAME.'"')
            ->expectsOutputToContain('Suite "'.DemoSeeder::SUITE_NAME.'"')
            // One expectation per line; ids come from sequences RefreshDatabase does not reset.
            ->expectsOutputToContain('23 case chạy được; lần chạy #')
            ->assertSuccessful();
    }

    public function test_the_summary_says_when_a_suite_has_no_run(): void
    {
        config(['ait.allowed_target_hosts' => ['localhost']]);

        $this->seedCommand()
            ->expectsOutputToContain('không thuộc allowlist')
            ->expectsOutputToContain('chưa có lần chạy')
            ->assertSuccessful();
    }

    private function seedCommand(): PendingCommand
    {
        $command = $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return [
            'users' => User::query()->count(),
            'projects' => Project::query()->count(),
            'specs' => ApiSpec::query()->count(),
            'rules' => BusinessRule::query()->count(),
            'suites' => TestSuite::query()->count(),
            'jobs' => GenerationJob::query()->count(),
            'cases' => TestCase::query()->count(),
            'runs' => TestRun::query()->count(),
            'results' => TestResult::query()->count(),
            'llm_calls' => LlmCallLog::query()->count(),
        ];
    }
}
