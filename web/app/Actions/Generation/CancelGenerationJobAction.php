<?php

namespace App\Actions\Generation;

use App\Enums\ErrorCode;
use App\Enums\GenerationJobStatus;
use App\Exceptions\ApiException;
use App\Models\GenerationJob;
use Illuminate\Support\Carbon;

class CancelGenerationJobAction
{
    public function __construct(
        private readonly TransitionGenerationJobAction $transition,
    ) {}

    /**
     * §4.1: only a QUEUED job can be cancelled (QUEUED -> FAILED).
     *
     * @throws ApiException
     */
    public function execute(GenerationJob $job): GenerationJob
    {
        if ($job->status !== GenerationJobStatus::Queued) {
            throw new ApiException(
                ErrorCode::InvalidStateTransition,
                $job->status === GenerationJobStatus::Running
                    ? 'Tiến trình đang chạy (RUNNING): chỉ có thể hủy khi còn ở hàng đợi (QUEUED).'
                    : "Tiến trình đã kết thúc ({$job->status->value}), không thể hủy."
            );
        }

        // Re-checked under a row lock so a worker picking the job up concurrently cannot be overwritten.
        return $this->transition->execute($job, GenerationJobStatus::Failed, [
            'error' => 'Tiến trình đã bị người dùng hủy bỏ.',
            'finished_at' => Carbon::now(),
        ]);
    }
}
