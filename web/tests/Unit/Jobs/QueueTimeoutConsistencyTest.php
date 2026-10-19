<?php

namespace Tests\Unit\Jobs;

use App\Data\Experiments\ExperimentStep;
use App\Jobs\ExecuteTestRunJob;
use App\Jobs\GenerateTestsJob;
use App\Jobs\Generation\GenerateRuleTestCases;
use App\Jobs\RunExperimentJob;
use App\Jobs\RunExperimentStepJob;
use Tests\TestCase;

/**
 * Regression (S11-02): the experiment job had $timeout = 3600 while the worker ran with
 * --timeout=600 and the database queue re-released jobs after 90 s (DB_QUEUE_RETRY_AFTER), so a
 * long experiment could be killed or executed twice. Every job timeout must stay below the
 * worker timeout, which must stay below retry_after.
 */
class QueueTimeoutConsistencyTest extends TestCase
{
    private function workerTimeout(): int
    {
        $path = base_path('../docker/docker-compose.yml');
        if (! is_file($path)) {
            $this->markTestSkipped('docker/docker-compose.yml is not mounted (running inside the web container).');
        }
        $compose = (string) file_get_contents($path);
        if (preg_match('/"queue:work"[^\]]*"--timeout=([0-9]+)"/', $compose, $matches) !== 1) {
            $this->fail('worker command must set --timeout');
        }

        return (int) $matches[1];
    }

    public function test_job_timeouts_fit_the_worker_timeout_and_retry_after(): void
    {
        $worker = $this->workerTimeout();
        $retryAfter = (int) config('queue.connections.database.retry_after');

        $timeouts = [
            RunExperimentJob::class => (new RunExperimentJob(1, 1))->timeout,
            RunExperimentStepJob::class => (new RunExperimentStepJob(1, 1, ExperimentStep::finalize()))->timeout,
            ExecuteTestRunJob::class => (new ExecuteTestRunJob(1))->timeout,
            GenerateTestsJob::class => (new GenerateTestsJob(1))->timeout,
            GenerateRuleTestCases::class => (new \ReflectionClass(GenerateRuleTestCases::class))->getProperty('timeout')->getDefaultValue(),
        ];

        foreach ($timeouts as $job => $timeout) {
            $this->assertIsInt($timeout);
            $this->assertLessThanOrEqual($worker, $timeout, "{$job} timeout exceeds the worker --timeout");
        }
        $this->assertGreaterThan($worker, $retryAfter, 'DB_QUEUE_RETRY_AFTER must exceed the worker --timeout');
        $this->assertSame(1900, $retryAfter);
    }

    /**
     * S11-02: a slow local LLM needs one long POST /v1/generate. ai-service stops at
     * AI_GENERATE_BUDGET_SECONDS and returns partial results, so the web must wait longer than
     * that (AI_SERVICE_TIMEOUT), and the jobs that make the call must outlive the HTTP timeout.
     */
    public function test_ai_service_timeout_fits_the_generation_budget_and_job_timeouts(): void
    {
        $aiTimeout = (int) config('ait.ai_service.timeout_seconds');
        $this->assertSame(1380, $aiTimeout);
        $this->assertStringContainsString('AI_SERVICE_TIMEOUT=1380', (string) file_get_contents(base_path('.env.example')));

        // Generation plus validation/persistence (and the RULE seed run of a HYBRID step).
        $this->assertGreaterThanOrEqual($aiTimeout + 60, (new GenerateTestsJob(1))->timeout);
        $this->assertGreaterThanOrEqual($aiTimeout + 60, RunExperimentStepJob::TIMEOUT);

        $aiExample = base_path('../ai-service/.env.example');
        if (! is_file($aiExample)) {
            $this->markTestSkipped('ai-service/.env.example is not mounted (running inside the web container).');
        }
        $env = (string) file_get_contents($aiExample);
        if (preg_match('/^AI_GENERATE_BUDGET_SECONDS=([0-9]+)$/m', $env, $budget) !== 1
            || preg_match('/^AI_LLM_TIMEOUT_SECONDS=([0-9]+)$/m', $env, $callTimeout) !== 1) {
            $this->fail('ai-service/.env.example must set AI_GENERATE_BUDGET_SECONDS and AI_LLM_TIMEOUT_SECONDS');
        }
        $this->assertLessThanOrEqual($aiTimeout - 60, (int) $budget[1], 'AI_GENERATE_BUDGET_SECONDS must end 60 s before AI_SERVICE_TIMEOUT');
        $this->assertLessThan((int) $budget[1], (int) $callTimeout[1]);
    }

    public function test_env_example_documents_retry_after(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('DB_QUEUE_RETRY_AFTER=1900', $example);
    }
}
