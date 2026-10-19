<?php

namespace App\Actions\Projects;

use App\Actions\Generation\CancelGenerationJobAction;
use App\Actions\Runs\CancelTestRunAction;
use App\Enums\GenerationJobStatus;
use App\Enums\TestRunStatus;
use App\Exceptions\ApiException;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\TestRun;

class DeleteProjectAction
{
    public function __construct(
        private readonly CancelGenerationJobAction $cancelGenerationJob,
        private readonly CancelTestRunAction $cancelTestRun,
    ) {}

    /**
     * Soft-deletes the project. Its children become unreachable (404, see BelongsToLiveProject), so
     * work that has not finished yet is stopped through the §4 transitions instead of running on:
     * QUEUED generation jobs → FAILED, QUEUED/RUNNING test runs → CANCELLED.
     */
    public function execute(Project $project): void
    {
        $project->delete();

        $jobs = GenerationJob::query()
            ->where('project_id', $project->id)
            ->where('status', GenerationJobStatus::Queued->value)
            ->get();
        foreach ($jobs as $job) {
            try {
                $this->cancelGenerationJob->execute($job);
            } catch (ApiException) {
                // Picked up by a worker concurrently.
            }
        }

        $runs = TestRun::query()
            ->whereHas('testSuite', fn ($query) => $query->where('project_id', $project->id))
            ->whereIn('status', [TestRunStatus::Queued->value, TestRunStatus::Running->value])
            ->get();
        foreach ($runs as $run) {
            try {
                $this->cancelTestRun->execute($run);
            } catch (ApiException) {
                // Finished concurrently.
            }
        }
    }
}
