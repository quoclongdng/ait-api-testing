<?php

namespace App\Jobs;

use App\Actions\Generation\ExecuteAiGenerationAction;
use App\Actions\Generation\ProcessGeneratedTestCasesAction;
use App\Actions\Generation\TransitionGenerationJobAction;
use App\Enums\ErrorCode;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Exceptions\ApiException;
use App\Models\GenerationJob;
use App\Services\Support\GenerationJobError;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateTestsJob implements ShouldQueue
{
    use Queueable;

    /** A generation job is processed at most once; test_cases and llm_call_logs are not replayed. */
    public int $tries = 1;

    /**
     * Longer than AI_SERVICE_TIMEOUT (1380 s by default) plus web validation and persistence, and
     * not above the worker --timeout (1800 s) < DB_QUEUE_RETRY_AFTER (1900 s) (S11-02).
     */
    public const TIMEOUT = 1500;

    public int $timeout = self::TIMEOUT;

    public function __construct(
        public int $generationJobId,
    ) {}

    public function handle(
        ExecuteAiGenerationAction $aiAction,
        ProcessGeneratedTestCasesAction $processAction,
    ): void {
        $job = GenerationJob::find($this->generationJobId);
        if (! $job instanceof GenerationJob) {
            return;
        }

        // Only QUEUED jobs may be processed (§4.1)
        if ($job->status !== GenerationJobStatus::Queued) {
            return;
        }

        // RULE jobs are handled by GenerateRuleTestCases (S3-03); never leave one stuck in QUEUED here.
        if ($job->strategy !== GenerationStrategy::Llm && $job->strategy !== GenerationStrategy::Hybrid) {
            $this->markFailed($job->id, ErrorCode::ValidationFailed->value);

            return;
        }

        try {
            // 1. Call ai-service, transition QUEUED -> RUNNING, record llm_call_logs
            $response = $aiAction->execute($job);

            // 2. Validate at Web (D04), dedup by fingerprint, save test_cases, update stats & job terminal status
            $processAction->execute($job, $response->testCases, $response->llmCalls);
        } catch (ApiException $e) {
            // Usually already marked FAILED by ExecuteAiGenerationAction; guard against later stages.
            Log::warning('GenerateTestsJob failed', [
                'generation_job_id' => $job->id,
                'code' => $e->errorCode->value,
                'message' => $e->getMessage(),
            ]);
            $this->markFailed($job->id, GenerationJobError::compose($e->errorCode->value, $e->getMessage()));
        } catch (Throwable $e) {
            Log::error('GenerateTestsJob failed', [
                'generation_job_id' => $job->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $this->markFailed($job->id, ErrorCode::InternalError->value);
        }
    }

    /**
     * Called by the worker when the process died mid-run (timeout, OOM, restart) and the job is
     * reserved again past $tries: without it the row would stay RUNNING forever (§4.1).
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('GenerateTestsJob aborted by the queue worker', [
            'generation_job_id' => $this->generationJobId,
            'exception' => $exception !== null ? $exception::class : null,
            'message' => $exception?->getMessage(),
        ]);

        $this->markFailed($this->generationJobId, ErrorCode::InternalError->value);
    }

    /**
     * QUEUED/RUNNING -> FAILED through the §4.1 Action (row lock + allowed transitions).
     */
    private function markFailed(int $generationJobId, string $error): void
    {
        $job = GenerationJob::find($generationJobId);
        if (! $job instanceof GenerationJob
            || ! in_array($job->status, [GenerationJobStatus::Queued, GenerationJobStatus::Running], true)) {
            return;
        }

        try {
            app(TransitionGenerationJobAction::class)->execute($job, GenerationJobStatus::Failed, [
                'error' => $error,
                'finished_at' => Carbon::now(),
            ]);
        } catch (ApiException) {
            // Reached a terminal state concurrently.
        }
    }
}
