<?php

namespace App\Jobs;

use App\Actions\Experiments\RunExperimentAction;
use App\Data\Experiments\ExperimentStep;
use App\Services\Support\SafeErrorMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One step of an experiment (generation of a repetition, one variant run, or finalisation),
 * chained by RunExperimentJob (S11-02).
 *
 * Timeouts must stay consistent (guarded by QueueTimeoutConsistencyTest):
 * TIMEOUT (1500 s) ≤ worker `queue:work --timeout` (1800 s, docker-compose) < DB_QUEUE_RETRY_AFTER (1900 s),
 * so a running step is never handed to another worker while it is still executing.
 * A step that fails marks the experiment FAILED; the remaining chained steps then do nothing.
 */
class RunExperimentStepJob implements ShouldQueue
{
    use Queueable;

    public const TIMEOUT = 1500;

    public int $tries = 1;

    public int $timeout = self::TIMEOUT;

    public function __construct(
        public int $experimentId,
        public int $userId,
        public ExperimentStep $step,
    ) {}

    public function handle(RunExperimentAction $action): void
    {
        $action->runStep($this->experimentId, $this->userId, $this->step);
    }

    public function failed(?Throwable $exception): void
    {
        $generic = "Bước thực nghiệm {$this->step->label()} bị dừng bất thường.";
        app(RunExperimentAction::class)->markFailed(
            $this->experimentId,
            $exception === null ? $generic : SafeErrorMessage::from($exception, $generic, ['experiment_id' => $this->experimentId]),
        );
    }
}
