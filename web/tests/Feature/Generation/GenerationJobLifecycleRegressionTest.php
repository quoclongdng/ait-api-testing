<?php

namespace Tests\Feature\Generation;

use App\Actions\Generation\ProcessGeneratedTestCasesAction;
use App\Actions\Generation\SummarizeGenerationJobAction;
use App\Actions\Runs\ListRunCaseSummariesAction;
use App\Data\Ai\AiGeneratedTestCase;
use App\Enums\ErrorCode;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\HttpMethod;
use App\Exceptions\ApiException;
use App\Jobs\ExecuteTestRunJob;
use App\Jobs\GenerateTestsJob;
use App\Jobs\Generation\GenerateRuleTestCases;
use App\Jobs\RunExperimentJob;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Models\GenerationJob;
use App\Models\LlmCallLog;
use App\Models\TestCase as TestCaseModel;
use App\Models\TestRun;
use App\Models\TestSuite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Regressions (S10-01 audit) for the LLM/HYBRID generation job lifecycle and the queue settings.
 */
class GenerationJobLifecycleRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_tests_job_failed_hook_moves_a_running_job_to_failed(): void
    {
        $job = $this->job(GenerationJobStatus::Running);

        (new GenerateTestsJob($job->id))->failed(new RuntimeException('worker killed'));

        $job->refresh();
        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertSame(ErrorCode::InternalError->value, $job->error);
        $this->assertNotNull($job->finished_at);
    }

    public function test_generate_tests_job_failed_hook_leaves_terminal_jobs_untouched(): void
    {
        $job = $this->job(GenerationJobStatus::Succeeded);

        (new GenerateTestsJob($job->id))->failed(null);

        $this->assertSame(GenerationJobStatus::Succeeded, $job->refresh()->status);
    }

    public function test_queue_retry_after_exceeds_every_job_timeout(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');

        foreach ([
            new GenerateTestsJob(1),
            new GenerateRuleTestCases(1),
            new ExecuteTestRunJob(1),
            new RunExperimentJob(1, 1),
        ] as $queued) {
            $this->assertSame(1, $queued->tries, $queued::class);
            $this->assertGreaterThan($queued->timeout, $retryAfter, $queued::class);
        }
        $this->assertGreaterThan((int) config('ait.ai_service.timeout_seconds'), (new GenerateTestsJob(1))->timeout);
    }

    public function test_processing_refuses_a_job_that_is_no_longer_running(): void
    {
        $job = $this->job(GenerationJobStatus::Running);
        // Another path (queue failed() hook, cancellation) finished the job meanwhile.
        GenerationJob::query()->whereKey($job->id)->update(['status' => GenerationJobStatus::Failed->value, 'error' => 'INTERNAL_ERROR']);

        try {
            app(ProcessGeneratedTestCasesAction::class)->execute($job, [new AiGeneratedTestCase($this->dsl(), 'VALID', [], 0)]);
            $this->fail('A FAILED job must not be overwritten.');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::InvalidStateTransition, $e->errorCode);
        }

        $job->refresh();
        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertSame(0, TestCaseModel::query()->count());
    }

    public function test_processing_transitions_through_the_state_machine(): void
    {
        $job = $this->job(GenerationJobStatus::Running);

        app(ProcessGeneratedTestCasesAction::class)->execute($job, [
            new AiGeneratedTestCase($this->dsl(), 'VALID', [], 0),
            new AiGeneratedTestCase($this->dsl(), 'VALID', [], 0),
        ]);

        $job->refresh();
        $this->assertSame(GenerationJobStatus::Succeeded, $job->status);
        $this->assertSame(1, $job->stats['valid'] ?? null);
        $this->assertSame(1, $job->stats['duplicates'] ?? null);
    }

    public function test_m11_summary_does_not_load_dsl_or_llm_prompts(): void
    {
        $job = $this->job(GenerationJobStatus::Running);
        TestCaseModel::factory()->create(['test_suite_id' => $job->test_suite_id, 'generation_job_id' => $job->id]);
        LlmCallLog::factory()->create(['generation_job_id' => $job->id]);

        $queries = $this->captureQueries(fn () => app(SummarizeGenerationJobAction::class)->execute(GenerationJob::query()->findOrFail($job->id)));

        foreach ($queries as $sql) {
            if (str_contains($sql, 'from "test_cases"') || str_contains($sql, 'from "llm_call_logs"')) {
                $this->assertStringNotContainsString('*', $sql);
                $this->assertStringNotContainsString('"dsl"', $sql);
                $this->assertStringNotContainsString('"prompt"', $sql);
                $this->assertStringNotContainsString('"response"', $sql);
            }
        }
    }

    public function test_m12_case_summaries_do_not_load_dsl(): void
    {
        $job = $this->job(GenerationJobStatus::Succeeded);
        TestCaseModel::factory()->create(['test_suite_id' => $job->test_suite_id]);
        $run = TestRun::factory()->create(['test_suite_id' => $job->test_suite_id]);

        $queries = $this->captureQueries(function () use ($run): void {
            $summary = app(ListRunCaseSummariesAction::class)->execute(TestRun::query()->findOrFail($run->id));
            $this->assertCount(1, $summary['cases']);
        });

        foreach ($queries as $sql) {
            if (str_contains($sql, 'from "test_cases"')) {
                $this->assertStringNotContainsString('*', $sql);
                $this->assertStringNotContainsString('"dsl"', $sql);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function captureQueries(callable $callback): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $callback();

        return $queries;
    }

    private function job(GenerationJobStatus $status): GenerationJob
    {
        $spec = ApiSpec::factory()->create();
        $endpoint = Endpoint::factory()->create([
            'api_spec_id' => $spec->id,
            'method' => HttpMethod::Get,
            'path' => '/orders',
            'responses' => ['200' => ['description' => 'OK']],
        ]);
        $suite = TestSuite::factory()->create(['api_spec_id' => $spec->id, 'project_id' => $spec->project_id]);

        return GenerationJob::factory()->create([
            'project_id' => $spec->project_id,
            'api_spec_id' => $spec->id,
            'test_suite_id' => $suite->id,
            'strategy' => GenerationStrategy::Llm,
            'status' => $status,
            'params' => ['endpoint_ids' => [$endpoint->id]],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function dsl(): array
    {
        return [
            'dsl_version' => '1.0',
            'title' => 'List orders',
            'endpoint' => ['method' => 'GET', 'path' => '/orders'],
            'technique' => 'HAPPY_PATH',
            'source' => 'LLM',
            'steps' => [[
                'name' => 'Step',
                'request' => ['method' => 'GET', 'path' => '/orders'],
                'assertions' => [['type' => 'status', 'equals' => 200]],
            ]],
        ];
    }
}
