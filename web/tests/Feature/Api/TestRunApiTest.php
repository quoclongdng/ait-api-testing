<?php

namespace Tests\Feature\Api;

use App\Enums\TestCaseStatus;
use App\Enums\TestRunStatus;
use App\Enums\TestVerdict;
use App\Enums\UserRole;
use App\Jobs\ExecuteTestRunJob;
use App\Models\Project;
use App\Models\TestCase;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase as FeatureTestCase;

class TestRunApiTest extends FeatureTestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/runs/1');
        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_user_can_create_test_run_and_dispatches_job(): void
    {
        Queue::fake();

        $user = User::factory()->create(['role' => UserRole::Tester]);
        $project = Project::factory()->for($user, 'owner')->create();
        $suite = TestSuite::factory()->for($project)->for($user, 'creator')->create();

        $response = $this->actingAs($user)->postJson("/api/v1/suites/{$suite->id}/runs", [
            'target_base_url' => 'http://localhost:8000',
            'benchmark_variant' => 'clean',
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.status', 'QUEUED');
        $response->assertJsonPath('data.totals', null);
        $response->assertJsonPath('data.target_base_url', 'http://localhost:8000');
        $response->assertJsonPath('data.benchmark_variant', 'clean');

        $runId = $response->json('data.id');
        $this->assertNotNull($runId);
        $this->assertDatabaseHas('test_runs', [
            'id' => $runId,
            'test_suite_id' => $suite->id,
            'status' => 'QUEUED',
        ]);

        Queue::assertPushed(ExecuteTestRunJob::class, fn (ExecuteTestRunJob $job) => $job->testRunId === $runId);
        // S14-06: runs stay on the default queue, never behind LLM/HYBRID generation.
        Queue::assertPushedOn('default', ExecuteTestRunJob::class);
    }

    public function test_create_test_run_rejects_target_outside_allowlist(): void
    {
        $user = User::factory()->create(['role' => UserRole::Tester]);
        $project = Project::factory()->for($user, 'owner')->create();
        $suite = TestSuite::factory()->for($project)->for($user, 'creator')->create();

        $response = $this->actingAs($user)->postJson("/api/v1/suites/{$suite->id}/runs", [
            'target_base_url' => 'http://evil-attacker.com',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'TARGET_NOT_ALLOWED');
    }

    public function test_create_test_run_validates_required_fields(): void
    {
        $user = User::factory()->create(['role' => UserRole::Tester]);
        $project = Project::factory()->for($user, 'owner')->create();
        $suite = TestSuite::factory()->for($project)->for($user, 'creator')->create();

        $response = $this->actingAs($user)->postJson("/api/v1/suites/{$suite->id}/runs", [
            'target_base_url' => 'not-a-valid-url',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_user_can_view_test_run(): void
    {
        $user = User::factory()->create(['role' => UserRole::Tester]);
        $project = Project::factory()->for($user, 'owner')->create();
        $suite = TestSuite::factory()->for($project)->for($user, 'creator')->create();
        $run = TestRun::factory()->for($suite)->for($user, 'triggerer')->create([
            'status' => TestRunStatus::Completed,
            'totals' => ['total' => 5, 'pass' => 5, 'fail' => 0, 'error' => 0, 'skipped' => 0],
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/runs/{$run->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $run->id);
        $response->assertJsonPath('data.status', 'COMPLETED');
        $response->assertJsonPath('data.totals.total', 5);
    }

    public function test_user_can_view_test_results(): void
    {
        $user = User::factory()->create(['role' => UserRole::Tester]);
        $project = Project::factory()->for($user, 'owner')->create();
        $suite = TestSuite::factory()->for($project)->for($user, 'creator')->create();
        $case = TestCase::factory()->for($suite, 'suite')->create(['title' => 'Sample Case']);
        $run = TestRun::factory()->for($suite)->for($user, 'triggerer')->create([
            'status' => TestRunStatus::Completed,
        ]);

        TestResult::create([
            'test_run_id' => $run->id,
            'test_case_id' => $case->id,
            'verdict' => TestVerdict::Pass,
            'duration_ms' => 120,
            'request_log' => ['method' => 'GET', 'url' => 'http://localhost:8000/api'],
            'response_log' => ['status' => 200],
            'assertion_results' => [],
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/runs/{$run->id}/results");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.verdict', 'PASS');
        $response->assertJsonPath('data.0.duration_ms', 120);
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('meta.current_page', 1);
        $this->assertSame(
            ['id', 'test_run_id', 'test_case_id', 'verdict', 'duration_ms', 'request_log', 'response_log', 'assertion_results', 'error_message', 'created_at'],
            array_keys((array) $response->json('data.0'))
        );
    }

    /**
     * Regression (QA S13-03): numeric-keyed objects inside the raw logs (a target body such as
     * {"1": ..., "2": ...}) were re-indexed into JSON lists by JsonResource::filter().
     */
    public function test_results_keep_numeric_keys_of_raw_logs(): void
    {
        $user = User::factory()->create(['role' => UserRole::Tester]);
        $project = Project::factory()->for($user, 'owner')->create();
        $suite = TestSuite::factory()->for($project)->for($user, 'creator')->create();
        $case = TestCase::factory()->for($suite, 'suite')->create();
        $run = TestRun::factory()->for($suite)->for($user, 'triggerer')->create(['status' => TestRunStatus::Completed]);

        TestResult::create([
            'test_run_id' => $run->id,
            'test_case_id' => $case->id,
            'verdict' => TestVerdict::Pass,
            'duration_ms' => 5,
            'request_log' => ['method' => 'GET', 'url' => 'http://localhost:8000/api', 'body' => ['10' => 'a', '20' => 'b']],
            'response_log' => ['status' => 200, 'body' => ['200' => ['ok' => true], '404' => ['ok' => false]]],
            'assertion_results' => [['type' => 'status', 'passed' => true]],
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/runs/{$run->id}/results")->assertOk();

        $body = json_decode((string) $response->getContent(), false, 512, JSON_THROW_ON_ERROR);
        $result = $body->data[0];
        $this->assertInstanceOf(\stdClass::class, $result->response_log->body);
        $this->assertTrue($result->response_log->body->{'200'}->ok);
        $this->assertInstanceOf(\stdClass::class, $result->request_log->body);
        $this->assertSame('b', $result->request_log->body->{'20'});
        $this->assertIsArray($result->assertion_results);
        $this->assertIsArray($body->data);
    }

    public function test_user_can_cancel_test_run(): void
    {
        $user = User::factory()->create(['role' => UserRole::Tester]);
        $project = Project::factory()->for($user, 'owner')->create();
        $suite = TestSuite::factory()->for($project)->for($user, 'creator')->create();
        $run = TestRun::factory()->for($suite)->for($user, 'triggerer')->create([
            'status' => TestRunStatus::Queued,
        ]);

        $response = $this->actingAs($user)->postJson("/api/v1/runs/{$run->id}/cancel");

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'CANCELLED');
        $freshRun = $run->fresh();
        $this->assertNotNull($freshRun);
        $this->assertSame(TestRunStatus::Cancelled, $freshRun->status);
    }

    public function test_cancel_rejects_completed_test_run_with_409_invalid_state_transition(): void
    {
        $user = User::factory()->create(['role' => UserRole::Tester]);
        $project = Project::factory()->for($user, 'owner')->create();
        $suite = TestSuite::factory()->for($project)->for($user, 'creator')->create();
        $run = TestRun::factory()->for($suite)->for($user, 'triggerer')->create([
            'status' => TestRunStatus::Completed,
        ]);

        $response = $this->actingAs($user)->postJson("/api/v1/runs/{$run->id}/cancel");

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');
    }

    public function test_user_cannot_view_or_cancel_run_of_another_users_project(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create(['role' => UserRole::Tester]);
        $project = Project::factory()->for($owner, 'owner')->create();
        $suite = TestSuite::factory()->for($project)->for($owner, 'creator')->create();
        $run = TestRun::factory()->for($suite)->for($owner, 'triggerer')->create();

        $viewResponse = $this->actingAs($otherUser)->getJson("/api/v1/runs/{$run->id}");
        $viewResponse->assertStatus(403);

        $cancelResponse = $this->actingAs($otherUser)->postJson("/api/v1/runs/{$run->id}/cancel");
        $cancelResponse->assertStatus(403);
    }

    public function test_results_can_be_filtered_by_verdict_and_paginated(): void
    {
        $user = User::factory()->create(['role' => UserRole::Tester]);
        $project = Project::factory()->for($user, 'owner')->create();
        $suite = TestSuite::factory()->for($project)->for($user, 'creator')->create();
        $run = TestRun::factory()->for($suite)->for($user, 'triggerer')->create(['status' => TestRunStatus::Completed]);

        foreach ([TestVerdict::Pass, TestVerdict::Fail, TestVerdict::Fail] as $verdict) {
            TestResult::create([
                'test_run_id' => $run->id,
                'test_case_id' => TestCase::factory()->for($suite, 'suite')->create()->id,
                'verdict' => $verdict,
                'duration_ms' => 1,
                'request_log' => ['steps' => []],
                'response_log' => ['steps' => []],
                'assertion_results' => [],
            ]);
        }

        $this->actingAs($user)->getJson("/api/v1/runs/{$run->id}/results?verdict=FAIL&per_page=1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.verdict', 'FAIL')
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2);

        $this->actingAs($user)->getJson("/api/v1/runs/{$run->id}/results?verdict=MAYBE")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_cancelling_twice_returns_409(): void
    {
        $user = User::factory()->create(['role' => UserRole::Tester]);
        $project = Project::factory()->for($user, 'owner')->create();
        $suite = TestSuite::factory()->for($project)->for($user, 'creator')->create();
        $run = TestRun::factory()->for($suite)->for($user, 'triggerer')->create(['status' => TestRunStatus::Running]);

        $this->actingAs($user)->postJson("/api/v1/runs/{$run->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');

        $this->actingAs($user)->postJson("/api/v1/runs/{$run->id}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');
    }

    public function test_user_cannot_create_run_on_another_users_suite(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['role' => UserRole::Tester]);
        $other = User::factory()->create(['role' => UserRole::Tester]);
        $project = Project::factory()->for($owner, 'owner')->create();
        $suite = TestSuite::factory()->for($project)->for($owner, 'creator')->create();

        $this->actingAs($other)->postJson("/api/v1/suites/{$suite->id}/runs", ['target_base_url' => 'http://localhost:8000'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->actingAs($other)->getJson('/api/v1/runs/999999')->assertStatus(404);
        Queue::assertNothingPushed();
    }

    public function test_admin_can_view_any_run(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Tester]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $project = Project::factory()->for($owner, 'owner')->create();
        $suite = TestSuite::factory()->for($project)->for($owner, 'creator')->create();
        $run = TestRun::factory()->for($suite)->for($owner, 'triggerer')->create();

        $this->actingAs($admin)->getJson("/api/v1/runs/{$run->id}")->assertOk();
        $this->actingAs($admin)->getJson("/api/v1/runs/{$run->id}/results")->assertOk();
    }

    public function test_create_run_accepts_only_runnable_cases_of_the_suite(): void
    {
        Queue::fake();
        $user = User::factory()->create(['role' => UserRole::Tester]);
        $project = Project::factory()->for($user, 'owner')->create();
        $suite = TestSuite::factory()->for($project)->for($user, 'creator')->create();
        $otherSuite = TestSuite::factory()->for($project)->for($user, 'creator')->create();
        $valid = TestCase::factory()->for($suite, 'suite')->create(['status' => TestCaseStatus::Valid]);
        $invalid = TestCase::factory()->for($suite, 'suite')->create(['status' => TestCaseStatus::Invalid]);
        $foreign = TestCase::factory()->for($otherSuite, 'suite')->create(['status' => TestCaseStatus::Valid]);

        foreach ([$invalid->id, $foreign->id] as $badId) {
            $this->actingAs($user)->postJson("/api/v1/suites/{$suite->id}/runs", [
                'target_base_url' => 'http://localhost:8000',
                'test_case_ids' => [$valid->id, $badId],
            ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
        $this->assertDatabaseCount('test_runs', 0);

        $this->actingAs($user)->postJson("/api/v1/suites/{$suite->id}/runs", [
            'target_base_url' => 'http://localhost:8000',
            'test_case_ids' => [$valid->id],
        ])->assertStatus(202);

        Queue::assertPushed(ExecuteTestRunJob::class, fn (ExecuteTestRunJob $job): bool => $job->testCaseIds === [$valid->id]);
    }

    public function test_creating_runs_is_rate_limited(): void
    {
        Queue::fake();
        $user = User::factory()->create(['role' => UserRole::Tester]);
        $project = Project::factory()->for($user, 'owner')->create();
        $suite = TestSuite::factory()->for($project)->for($user, 'creator')->create();

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->postJson("/api/v1/suites/{$suite->id}/runs", ['target_base_url' => 'http://localhost:8000'])
                ->assertStatus(202);
        }

        $this->actingAs($user)->postJson("/api/v1/suites/{$suite->id}/runs", ['target_base_url' => 'http://localhost:8000'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED');
    }
}
