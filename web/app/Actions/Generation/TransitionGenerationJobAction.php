<?php

namespace App\Actions\Generation;

use App\Enums\ErrorCode;
use App\Enums\GenerationJobStatus;
use App\Exceptions\ApiException;
use App\Models\GenerationJob;
use Illuminate\Support\Facades\DB;

class TransitionGenerationJobAction
{
    /**
     * Apply one transition from docs/DESIGN.md §4.1 under a row lock.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function execute(GenerationJob $job, GenerationJobStatus $status, array $attributes = []): GenerationJob
    {
        return DB::transaction(function () use ($job, $status, $attributes): GenerationJob {
            $lockedJob = GenerationJob::query()
                ->lockForUpdate()
                ->whereKey($job->getKey())
                ->firstOrFail();
            $allowed = match ($lockedJob->status) {
                GenerationJobStatus::Queued => [GenerationJobStatus::Running, GenerationJobStatus::Failed],
                GenerationJobStatus::Running => [
                    GenerationJobStatus::Succeeded,
                    GenerationJobStatus::Partial,
                    GenerationJobStatus::Failed,
                ],
                default => [],
            };

            if (! in_array($status, $allowed, true)) {
                throw new ApiException(
                    ErrorCode::InvalidStateTransition,
                    "Không thể chuyển GenerationJob từ '{$lockedJob->status->value}' sang '{$status->value}'.",
                    ['generation_job_id' => $lockedJob->id]
                );
            }

            unset($attributes['status']);
            $lockedJob->fill($attributes);
            $lockedJob->status = $status;
            $lockedJob->save();

            return $lockedJob;
        });
    }
}
