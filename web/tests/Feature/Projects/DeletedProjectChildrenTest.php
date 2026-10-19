<?php

namespace Tests\Feature\Projects;

use App\Enums\GenerationJobStatus;
use App\Enums\TestRunStatus;
use App\Models\ApiSpec;
use App\Models\BusinessRule;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\TestCase as TestCaseModel;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression (S10-01 r2): children of a soft-deleted project stayed reachable by id and the
 * policies dereferenced the null project relation, so the owner got 500 INTERNAL_ERROR.
 */
class DeletedProjectChildrenTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{User, Project, ApiSpec, TestSuite, GenerationJob, TestRun, TestCaseModel, BusinessRule}
     */
    private function projectTree(): array
    {
        $tester = User::factory()->tester()->create();
        $project = Project::factory()->for($tester, 'owner')->create();
        $spec = ApiSpec::factory()->create(['project_id' => $project->id]);
        $suite = TestSuite::factory()->create(['project_id' => $project->id, 'api_spec_id' => $spec->id, 'created_by' => $tester->id]);
        $job = GenerationJob::factory()->create([
            'project_id' => $project->id,
            'api_spec_id' => $spec->id,
            'test_suite_id' => $suite->id,
            'created_by' => $tester->id,
        ]);
        $run = TestRun::factory()->create(['test_suite_id' => $suite->id, 'triggered_by' => $tester->id, 'benchmark_variant' => null]);
        $case = TestCaseModel::factory()->create(['test_suite_id' => $suite->id, 'generation_job_id' => $job->id]);
        $rule = BusinessRule::factory()->create(['project_id' => $project->id]);

        return [$tester, $project, $spec, $suite, $job, $run, $case, $rule];
    }

    public function test_children_of_a_deleted_project_return_not_found_for_owner_and_admin(): void
    {
        [$tester, $project, $spec, $suite, $job, $run, $case, $rule] = $this->projectTree();

        $this->actingAs($tester)->deleteJson("/api/v1/projects/{$project->id}")->assertNoContent();

        $admin = User::factory()->admin()->create();
        foreach ([$tester, $admin] as $user) {
            $this->actingAs($user);
            foreach ([
                "/api/v1/specs/{$spec->id}",
                "/api/v1/specs/{$spec->id}/endpoints",
                "/api/v1/suites/{$suite->id}",
                "/api/v1/suites/{$suite->id}/test-cases",
                "/api/v1/generation-jobs/{$job->id}",
                "/api/v1/runs/{$run->id}",
                "/api/v1/runs/{$run->id}/results",
                "/api/v1/test-cases/{$case->id}",
                "/api/v1/business-rules/{$rule->id}",
            ] as $uri) {
                $this->getJson($uri)->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
            }

            $this->postJson("/api/v1/suites/{$suite->id}/generation-jobs", ['strategy' => 'RULE', 'params' => []])
                ->assertNotFound();
            $this->postJson("/api/v1/suites/{$suite->id}/runs", ['target_base_url' => 'http://localhost:8000'])
                ->assertNotFound();
        }
    }

    public function test_deleting_a_project_stops_its_queued_jobs_and_runs(): void
    {
        [$tester, $project, , $suite, $job, $run] = $this->projectTree();
        $running = TestRun::factory()->create([
            'test_suite_id' => $suite->id,
            'status' => TestRunStatus::Running,
            'started_at' => now(),
        ]);

        $this->actingAs($tester)->deleteJson("/api/v1/projects/{$project->id}")->assertNoContent();

        $this->assertSame(GenerationJobStatus::Failed, $job->refresh()->status);
        $this->assertNotNull($job->finished_at);
        $this->assertSame(TestRunStatus::Cancelled, $run->refresh()->status);
        $this->assertSame(TestRunStatus::Cancelled, $running->refresh()->status);
    }
}
