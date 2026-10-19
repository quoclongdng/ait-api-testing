<?php

namespace App\Actions\Experiments;

use App\Enums\ErrorCode;
use App\Enums\ExperimentStatus;
use App\Exceptions\ApiException;
use App\Models\Experiment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Experiment state machine (docs/DESIGN.md §4.4): PLANNED → RUNNING → COMPLETED | FAILED.
 */
class TransitionExperimentAction
{
    /**
     * @param  array<string, mixed>  $attributes  columns written together with the status
     *
     * @throws ApiException
     */
    public function execute(Experiment $experiment, ExperimentStatus $status, array $attributes = []): Experiment
    {
        return DB::transaction(function () use ($experiment, $status, $attributes): Experiment {
            $locked = Experiment::query()->lockForUpdate()->whereKey($experiment->getKey())->firstOrFail();

            $allowed = match ($locked->status) {
                ExperimentStatus::Planned => [ExperimentStatus::Running],
                ExperimentStatus::Running => [ExperimentStatus::Completed, ExperimentStatus::Failed],
                default => [],
            };

            if (! in_array($status, $allowed, true)) {
                throw new ApiException(
                    ErrorCode::InvalidStateTransition,
                    "Không thể chuyển thực nghiệm từ {$locked->status->value} sang {$status->value}.",
                    ['experiment_id' => $locked->id, 'current_status' => $locked->status->value, 'target_status' => $status->value]
                );
            }

            $changes = $attributes + ['status' => $status];
            if ($status === ExperimentStatus::Running) {
                $changes['started_at'] ??= Carbon::now();
            } else {
                $changes['finished_at'] ??= Carbon::now();
            }

            $locked->fill($changes)->save();

            return $experiment->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
