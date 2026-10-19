<?php

namespace App\Http\Resources;

use App\Models\Experiment;
use App\Models\ExperimentBugDetection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use stdClass;

/** @mixin Experiment */
class ExperimentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'benchmark_id' => $this->benchmark_id,
            'strategy' => $this->strategy->value,
            'config' => $this->config,
            'status' => $this->status->value,
            'metrics' => $this->metrics,
            'started_at' => $this->started_at?->toISOString(),
            'finished_at' => $this->finished_at?->toISOString(),
            'repetitions_data' => $this->normalisedRepetitions(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];

        if ($this->resource->relationLoaded('bugDetections')) {
            $data['bug_detections'] = $this->bugDetections
                ->map(fn (ExperimentBugDetection $d): array => [
                    'id' => $d->id,
                    'experiment_id' => $d->experiment_id,
                    'benchmark_bug_id' => $d->benchmark_bug_id,
                    'repetition' => $d->repetition,
                    'detected' => $d->detected,
                    'clean_test_run_id' => $d->clean_test_run_id,
                    'bug_test_run_id' => $d->bug_test_run_id,
                    'detecting_test_case_ids' => $d->detecting_test_case_ids,
                    'created_at' => $d->created_at->toISOString(),
                ])
                ->values()
                ->all();
        }

        return $data;
    }

    /**
     * Keys of ExperimentRepetition in web-api.openapi.yaml (`additionalProperties: false`).
     * `generation_job_ids` lists every job that filled the suite (HYBRID: RULE seed job, then the
     * HYBRID job), ADR-0006.
     */
    private const REPETITION_KEYS = ['repetition', 'seed', 'generation_job_id', 'generation_job_ids', 'test_suite_id', 'clean_test_run_id', 'bug_runs'];

    /**
     * Returns only the contract keys of each entry. The contract types ExperimentRepetition.bug_runs
     * as an object keyed by bug code, but the array cast decodes an empty jsonb object back into
     * `[]`, which would serialise as a JSON array (partial entries while RUNNING, or after a failure
     * before any bug variant ran).
     *
     * @return list<array<string, mixed>>|null
     */
    private function normalisedRepetitions(): ?array
    {
        $repetitions = $this->repetitions_data;
        if ($repetitions === null) {
            return null;
        }

        return array_values(array_map(static function (array $entry): array {
            $entry = array_intersect_key($entry, array_flip(self::REPETITION_KEYS));
            if (! is_array($entry['generation_job_ids'] ?? null) && is_numeric($entry['generation_job_id'] ?? null)) {
                // Repetitions stored before the key existed had a single job.
                $entry['generation_job_ids'] = [(int) $entry['generation_job_id']];
            }
            if (($entry['bug_runs'] ?? null) === []) {
                $entry['bug_runs'] = new stdClass;
            }

            return $entry;
        }, $repetitions));
    }
}
