<?php

namespace App\Actions\Experiments;

use App\Enums\ExperimentStatus;
use App\Models\Experiment;

/**
 * Current status of experiments, for `php artisan ait:experiments:wait` (S11-02).
 */
class GetExperimentStatusesAction
{
    /**
     * @param  list<int>|null  $ids  null = every experiment still PLANNED or RUNNING
     * @return array<int, array{name: string, status: ExperimentStatus, progress: int, error: string|null}>
     */
    public function execute(?array $ids): array
    {
        $query = Experiment::query()->orderBy('id');
        if ($ids === null) {
            $query->whereIn('status', [ExperimentStatus::Planned->value, ExperimentStatus::Running->value]);
        } else {
            $query->whereIn('id', $ids);
        }

        $statuses = [];
        foreach ($query->get() as $experiment) {
            $error = $experiment->metrics['error'] ?? null;
            $statuses[$experiment->id] = [
                'name' => $experiment->name,
                'status' => $experiment->status,
                'progress' => count($experiment->repetitions_data ?? []),
                'error' => is_string($error) ? $error : null,
            ];
        }

        return $statuses;
    }
}
