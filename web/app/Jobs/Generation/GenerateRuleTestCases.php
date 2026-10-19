<?php

namespace App\Jobs\Generation;

use App\Actions\Generation\ExecuteRuleGenerationAction;
use App\Actions\Generation\TransitionGenerationJobAction;
use App\Enums\GenerationJobStatus;
use App\Exceptions\ApiException;
use App\Models\GenerationJob;
use App\Services\Support\SafeErrorMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

class GenerateRuleTestCases implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $generationJobId) {}

    public function handle(ExecuteRuleGenerationAction $action): void
    {
        $action->execute($this->generationJobId);
    }

    /**
     * Laravel's CallQueuedHandler invokes failed($e) with a single argument (no container
     * injection), so dependencies are resolved here instead of being declared as parameters.
     */
    public function failed(?Throwable $exception): void
    {
        $transition = app(TransitionGenerationJobAction::class);

        $job = GenerationJob::query()->find($this->generationJobId);
        if ($job === null || ! in_array($job->status, [GenerationJobStatus::Queued, GenerationJobStatus::Running], true)) {
            return;
        }

        try {
            $transition->execute($job, GenerationJobStatus::Failed, [
                'error' => $exception === null
                    ? 'Tiến trình sinh test bị dừng bất thường (INTERNAL_ERROR).'
                    : SafeErrorMessage::from(
                        $exception,
                        'Tiến trình sinh test bị dừng bất thường (INTERNAL_ERROR).',
                        ['generation_job_id' => $job->id],
                    ),
                'finished_at' => Carbon::now(),
            ]);
        } catch (ApiException) {
            // The job may have reached a terminal state while the worker reported failure.
        }
    }
}
