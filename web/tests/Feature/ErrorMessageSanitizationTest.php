<?php

namespace Tests\Feature;

use App\Actions\Experiments\RunExperimentAction;
use App\Actions\Generation\ExecuteRuleGenerationAction;
use App\Contracts\BenchmarkSandbox;
use App\Enums\ApiSpecStatus;
use App\Enums\ExperimentStatus;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\HttpMethod;
use App\Enums\TestCaseStatus;
use App\Enums\TestRunStatus;
use App\Enums\TestVerdict;
use App\Jobs\ExecuteTestRunJob;
use App\Jobs\Generation\GenerateRuleTestCases;
use App\Jobs\RunExperimentJob;
use App\Models\ApiSpec;
use App\Models\Benchmark;
use App\Models\BenchmarkBug;
use App\Models\Endpoint;
use App\Models\Experiment;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\TestCase as TestCaseModel;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\Execution\TestExecutor;
use App\Services\Generation\RuleGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Regression (S10-01 audit): raw messages of unexpected exceptions (SQL text, internal hosts,
 * class names) were stored in test_results.error_message, generation_jobs.error and
 * experiments.metrics.error and shown to users even with APP_DEBUG=false (§6).
 */
class ErrorMessageSanitizationTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'SQLSTATE[08006] could not connect to server db.internal:5432';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
    }

    public function test_test_result_hides_internal_exception_message(): void
    {
        $this->mock(TestExecutor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('execute')->andThrow(new RuntimeException(self::SECRET));
        });
        $user = User::factory()->tester()->create();
        $suite = TestSuite::factory()->for(Project::factory()->for($user, 'owner'))->for($user, 'creator')->create();
        TestCaseModel::factory()->for($suite, 'suite')->create(['status' => TestCaseStatus::Valid]);
        $run = TestRun::factory()->for($suite)->for($user, 'triggerer')->create([
            'target_base_url' => 'http://localhost:8000',
            'status' => TestRunStatus::Queued,
            'started_at' => null,
            'finished_at' => null,
            'totals' => null,
        ]);

        $this->app->call([new ExecuteTestRunJob($run->id), 'handle']);

        $result = TestResult::query()->where('test_run_id', $run->id)->firstOrFail();
        $this->assertSame(TestVerdict::Error, $result->verdict);
        $this->assertStringNotContainsString('SQLSTATE', (string) $result->error_message);
        $this->assertStringNotContainsString('db.internal', (string) $result->error_message);
        $this->assertStringContainsString('lỗi hệ thống', (string) $result->error_message);
    }

    public function test_rule_generation_job_hides_internal_exception_message(): void
    {
        $this->mock(RuleGenerator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')->andThrow(new RuntimeException(self::SECRET));
        });
        $job = $this->ruleJob();

        $job = app(ExecuteRuleGenerationAction::class)->execute($job->id);

        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertStringStartsWith('POST:/items: ', (string) $job->error);
        $this->assertStringNotContainsString('SQLSTATE', (string) $job->error);
    }

    public function test_rule_generation_queue_failure_hides_internal_exception_message(): void
    {
        $job = $this->ruleJob();

        (new GenerateRuleTestCases($job->id))->failed(new RuntimeException(self::SECRET));

        $job->refresh();
        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertStringNotContainsString('SQLSTATE', (string) $job->error);
        $this->assertNotSame('', (string) $job->error);
    }

    public function test_experiment_hides_internal_exception_message(): void
    {
        $this->mock(BenchmarkSandbox::class, function (MockInterface $mock): void {
            $mock->shouldReceive('variantBaseUrl')->andReturn('http://benchmark:8002');
            $mock->shouldReceive('ensureAvailable');
            $mock->shouldReceive('reset')->andThrow(new RuntimeException(self::SECRET));
        });
        $user = User::factory()->tester()->create();
        $benchmark = Benchmark::factory()->create(['slug' => 'simple-store', 'base_url' => 'http://benchmark:8002']);
        BenchmarkBug::factory()->create(['benchmark_id' => $benchmark->id, 'code' => 'BUG-001', 'toggle_flag' => 'BUG_001']);
        $experiment = Experiment::factory()->create([
            'benchmark_id' => $benchmark->id,
            'strategy' => GenerationStrategy::Rule,
            'config' => ['model' => null, 'seed' => 42, 'temperature' => 0.2, 'repetitions' => 1],
        ]);

        app(RunExperimentAction::class)->execute($experiment->id, $user->id);

        $experiment->refresh();
        $this->assertSame(ExperimentStatus::Failed, $experiment->status);
        $error = (string) ($experiment->metrics['error'] ?? '');
        $this->assertStringNotContainsString('SQLSTATE', $error);
        $this->assertStringContainsString('INTERNAL_ERROR', $error);

        $this->actingAs($user)->getJson("/api/v1/experiments/{$experiment->id}")
            ->assertOk()
            ->assertDontSee('db.internal');
    }

    public function test_experiment_queue_failure_hides_internal_exception_message(): void
    {
        $experiment = Experiment::factory()->create();

        (new RunExperimentJob($experiment->id, 1))->failed(new RuntimeException(self::SECRET));

        $experiment->refresh();
        $this->assertSame(ExperimentStatus::Failed, $experiment->status);
        $this->assertStringNotContainsString('SQLSTATE', (string) ($experiment->metrics['error'] ?? ''));
    }

    private function ruleJob(): GenerationJob
    {
        $user = User::factory()->tester()->create();
        $project = Project::factory()->for($user, 'owner')->create();
        $spec = ApiSpec::factory()->for($project)->create(['status' => ApiSpecStatus::Parsed]);
        $suite = TestSuite::factory()->create(['project_id' => $project->id, 'api_spec_id' => $spec->id, 'created_by' => $user->id]);
        $endpoint = Endpoint::factory()->create(['api_spec_id' => $spec->id, 'method' => HttpMethod::Post, 'path' => '/items']);

        return GenerationJob::factory()->create([
            'project_id' => $project->id,
            'api_spec_id' => $spec->id,
            'test_suite_id' => $suite->id,
            'created_by' => $user->id,
            'strategy' => GenerationStrategy::Rule,
            'status' => GenerationJobStatus::Queued,
            'params' => ['endpoint_ids' => [$endpoint->id]],
        ]);
    }
}
