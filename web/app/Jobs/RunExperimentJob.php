<?php

namespace App\Jobs;

use App\Actions\Experiments\RunExperimentAction;
use App\Data\Experiments\ExperimentStep;
use App\Enums\QueueName;
use App\Services\Support\SafeErrorMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * Queue worker entry point for an experiment (docs/DESIGN.md D10, §4.4). It only moves the experiment
 * to RUNNING and plans it; the work itself is chained as one RunExperimentStepJob per
 * generation / variant run / finalisation (S11-02), so every job stays far below the worker
 * --timeout and DB_QUEUE_RETRY_AFTER. An experiment is executed at most once because test
 * results and bug detections are insert-only.
 */
class RunExperimentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public int $experimentId,
        public int $userId,
    ) {}

    public function handle(RunExperimentAction $action): void
    {
        $steps = $action->start($this->experimentId, $this->userId);
        if ($steps === null || $steps === []) {
            return;
        }

        Bus::chain(array_map(
            fn (ExperimentStep $step): RunExperimentStepJob => new RunExperimentStepJob($this->experimentId, $this->userId, $step),
            $steps,
        ))->onQueue(QueueName::Llm)->dispatch();
    }

    public function failed(?Throwable $exception): void
    {
        $generic = 'Tiến trình thực nghiệm bị dừng bất thường.';
        app(RunExperimentAction::class)->markFailed(
            $this->experimentId,
            $exception === null ? $generic : SafeErrorMessage::from($exception, $generic, ['experiment_id' => $this->experimentId]),
        );
    }
}
