<?php

namespace App\Actions\Experiments;

use App\Enums\GenerationJobStatus;
use App\Models\Experiment;
use App\Models\GenerationJob;
use App\Services\Support\GenerationJobError;

class ShowExperimentAction
{
    public function execute(Experiment $experiment): Experiment
    {
        return $experiment->load([
            'benchmark.bugs' => fn ($query) => $query->orderBy('code'),
            'bugDetections' => fn ($query) => $query->orderBy('repetition')->orderBy('benchmark_bug_id'),
        ]);
    }

    /**
     * Error code of every repetition whose scored generation job FAILED, keyed by repetition, so
     * M15 shows why a repetition has no cases instead of plain 0% metrics.
     *
     * @return array<int, string>
     */
    public function generationFailures(Experiment $experiment): array
    {
        $jobIds = [];
        foreach ($experiment->repetitions_data ?? [] as $entry) {
            if (is_numeric($entry['generation_job_id'] ?? null) && is_numeric($entry['repetition'] ?? null)) {
                $jobIds[(int) $entry['repetition']] = (int) $entry['generation_job_id'];
            }
        }
        if ($jobIds === []) {
            return [];
        }

        $failed = GenerationJob::query()
            ->whereIn('id', array_values($jobIds))
            ->where('status', GenerationJobStatus::Failed->value)
            ->pluck('error', 'id');

        $failures = [];
        foreach ($jobIds as $repetition => $jobId) {
            if ($failed->has($jobId)) {
                $failures[$repetition] = mb_substr(GenerationJobError::oneLine($failed->get($jobId) ?: 'INTERNAL_ERROR'), 0, 300);
            }
        }

        return $failures;
    }
}
