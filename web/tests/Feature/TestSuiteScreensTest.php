<?php

namespace Tests\Feature;

use App\Enums\ApiSpecStatus;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\HttpMethod;
use App\Enums\TestCaseSource;
use App\Enums\TestCaseStatus;
use App\Enums\TestTechnique;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\TestCase as TestCaseModel;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\TestEngine\TestFingerprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TestSuiteScreensTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login_for_suite_screens(): void
    {
        $suite = TestSuite::factory()->create();
        $testCase = TestCaseModel::factory()->create(['test_suite_id' => $suite->id]);
        $job = GenerationJob::factory()->create(['test_suite_id' => $suite->id]);

        $this->get("/projects/{$suite->project_id}/suites")->assertRedirect('/login');
        $this->get("/suites/{$suite->id}")->assertRedirect('/login');
        $this->get("/test-cases/{$testCase->id}")->assertRedirect('/login');
        $this->get("/generation-jobs/{$job->id}")->assertRedirect('/login');
        $this->get("/suites/{$suite->id}/generation-jobs/create")->assertRedirect('/login');
    }

    public function test_tester_cannot_access_other_users_suite_or_cases(): void
    {
        $testerA = User::factory()->tester()->create();
        $testerB = User::factory()->tester()->create();

        $projectB = Project::factory()->for($testerB, 'owner')->create();
        $specB = ApiSpec::factory()->for($projectB)->create();
        $suiteB = TestSuite::factory()->create([
            'project_id' => $projectB->id,
            'api_spec_id' => $specB->id,
            'created_by' => $testerB->id,
        ]);
        $endpointB = Endpoint::factory()->create(['api_spec_id' => $specB->id]);
        $testCaseB = TestCaseModel::factory()->create([
            'test_suite_id' => $suiteB->id,
            'endpoint_id' => $endpointB->id,
        ]);

        // Tester A tries to access Tester B's resources
        $this->actingAs($testerA)->get("/projects/{$projectB->id}/suites")->assertForbidden();
        $this->actingAs($testerA)->get("/suites/{$suiteB->id}")->assertForbidden();
        $this->actingAs($testerA)->get("/test-cases/{$testCaseB->id}")->assertForbidden();
        $this->actingAs($testerA)->get("/suites/{$suiteB->id}/generation-jobs/create")->assertForbidden();
    }

    public function test_tester_can_list_and_create_test_suites(): void
    {
        $tester = User::factory()->tester()->create();
        $project = Project::factory()->for($tester, 'owner')->create();
        $spec = ApiSpec::factory()->for($project)->create(['version_label' => 'v1.0.0', 'status' => ApiSpecStatus::Parsed]);

        // View suites list
        $this->actingAs($tester)
            ->get("/projects/{$project->id}/suites")
            ->assertOk()
            ->assertSee('<span>Bộ kiểm thử</span>', false)
            ->assertSee('Chưa có bộ kiểm thử nào');

        // Create new suite
        $this->actingAs($tester)
            ->post("/projects/{$project->id}/suites", [
                'api_spec_id' => $spec->id,
                'name' => 'Regression Test Suite',
                'description' => 'Kiểm thử hồi quy tự động',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('test_suites', [
            'project_id' => $project->id,
            'api_spec_id' => $spec->id,
            'name' => 'Regression Test Suite',
            'created_by' => $tester->id,
        ]);
    }

    public function test_m09_screen_displays_suite_with_filters_and_bulk_actions(): void
    {
        $tester = User::factory()->tester()->create();
        $project = Project::factory()->for($tester, 'owner')->create();
        $spec = ApiSpec::factory()->for($project)->create(['version_label' => 'v1.0', 'status' => ApiSpecStatus::Parsed]);
        $suite = TestSuite::factory()->create([
            'project_id' => $project->id,
            'api_spec_id' => $spec->id,
            'name' => 'Core Suite',
            'created_by' => $tester->id,
        ]);
        $endpoint = Endpoint::factory()->create([
            'api_spec_id' => $spec->id,
            'method' => HttpMethod::Get,
            'path' => '/api/users',
        ]);

        $tc1 = TestCaseModel::factory()->create([
            'test_suite_id' => $suite->id,
            'endpoint_id' => $endpoint->id,
            'title' => 'Get all active users',
            'status' => TestCaseStatus::Valid,
            'source' => TestCaseSource::Rule,
            'technique' => TestTechnique::HappyPath,
        ]);

        $tc2 = TestCaseModel::factory()->create([
            'test_suite_id' => $suite->id,
            'endpoint_id' => $endpoint->id,
            'title' => 'Get user invalid id',
            'status' => TestCaseStatus::Invalid,
            'source' => TestCaseSource::Llm,
            'technique' => TestTechnique::InvalidInput,
        ]);

        // Access M09
        $response = $this->actingAs($tester)->get("/suites/{$suite->id}");
        $response->assertOk()
            ->assertSee('Core Suite')
            ->assertSee('Get all active users')
            ->assertSee('Get user invalid id')
            ->assertSee('Sinh test mới');

        // Filter by status=VALID
        $filterResponse = $this->actingAs($tester)->get("/suites/{$suite->id}?status=VALID");
        $filterResponse->assertOk()
            ->assertSee('Get all active users')
            ->assertDontSee('Get user invalid id');

        // Bulk approve tc1
        $this->actingAs($tester)
            ->post("/suites/{$suite->id}/bulk-action", [
                'action' => 'approve',
                'test_case_ids' => [$tc1->id],
            ])
            ->assertRedirect("/suites/{$suite->id}")
            ->assertSessionHas('success');

        $tc1->refresh();
        $this->assertEquals(TestCaseStatus::Approved, $tc1->status);

        // Bulk archive tc1
        $this->actingAs($tester)
            ->post("/suites/{$suite->id}/bulk-action", [
                'action' => 'archive',
                'test_case_ids' => [$tc1->id],
            ])
            ->assertRedirect("/suites/{$suite->id}")
            ->assertSessionHas('success');

        $tc1->refresh();
        $this->assertEquals(TestCaseStatus::Archived, $tc1->status);
    }

    public function test_m10_screen_dsl_editor_and_fsm_state_transitions(): void
    {
        $tester = User::factory()->tester()->create();
        $project = Project::factory()->for($tester, 'owner')->create();
        $spec = ApiSpec::factory()->for($project)->create(['status' => ApiSpecStatus::Parsed]);
        $suite = TestSuite::factory()->create([
            'project_id' => $project->id,
            'api_spec_id' => $spec->id,
            'created_by' => $tester->id,
        ]);
        $endpoint = Endpoint::factory()->create([
            'api_spec_id' => $spec->id,
            'method' => HttpMethod::Post,
            'path' => '/api/orders',
            'responses' => ['201' => ['description' => 'Created']],
        ]);

        // Create APPROVED test case
        $tc = TestCaseModel::factory()->create([
            'test_suite_id' => $suite->id,
            'endpoint_id' => $endpoint->id,
            'title' => 'Create new order',
            'status' => TestCaseStatus::Approved,
            'source' => TestCaseSource::Rule,
        ]);

        // View M10
        $this->actingAs($tester)
            ->get("/test-cases/{$tc->id}")
            ->assertOk()
            ->assertSee('Create new order')
            ->assertSee('Trình soạn thảo')
            ->assertSee('trực quan DSL')
            ->assertSee('Tab 2: Lỗi kiểm tra hợp lệ')
            ->assertSee('Tab 3: Log gọi LLM');

        // Edit DSL: FSM rule (§4.2) requires APPROVED -> VALID and source = MANUAL
        $updatedDsl = [
            'dsl_version' => '1.0',
            'title' => 'Create new order updated',
            'endpoint' => ['method' => 'POST', 'path' => '/api/orders'],
            'technique' => 'HAPPY_PATH',
            'source' => 'MANUAL',
            'steps' => [
                [
                    'name' => 'Send order',
                    'request' => ['method' => 'POST', 'path' => '/api/orders'],
                    'assertions' => [['type' => 'status', 'equals' => 201]],
                ],
            ],
        ];

        $this->actingAs($tester)
            ->patch("/test-cases/{$tc->id}", [
                'title' => 'Create new order updated',
                'dsl' => $updatedDsl,
            ])
            ->assertRedirect("/test-cases/{$tc->id}")
            ->assertSessionHas('success');

        $tc->refresh();
        $this->assertEquals(TestCaseStatus::Valid, $tc->status);
        $this->assertEquals(TestCaseSource::Manual, $tc->source);
        $this->assertEquals('Create new order updated', $tc->title);

        // Approve test case
        $this->actingAs($tester)
            ->post("/test-cases/{$tc->id}/approve")
            ->assertRedirect("/test-cases/{$tc->id}")
            ->assertSessionHas('success');

        $tc->refresh();
        $this->assertEquals(TestCaseStatus::Approved, $tc->status);

        // Archive test case
        $this->actingAs($tester)
            ->post("/test-cases/{$tc->id}/archive")
            ->assertRedirect("/test-cases/{$tc->id}")
            ->assertSessionHas('success');

        $tc->refresh();
        $this->assertEquals(TestCaseStatus::Archived, $tc->status);
    }

    public function test_m10_broken_dsl_is_marked_invalid_and_preserves_validation_errors(): void
    {
        $tester = User::factory()->tester()->create();
        $project = Project::factory()->for($tester, 'owner')->create();
        $spec = ApiSpec::factory()->for($project)->create(['version_label' => 'v1.0', 'status' => ApiSpecStatus::Parsed]);
        $suite = TestSuite::factory()->create([
            'project_id' => $project->id,
            'api_spec_id' => $spec->id,
            'created_by' => $tester->id,
        ]);
        $endpoint = Endpoint::factory()->create([
            'api_spec_id' => $spec->id,
            'method' => HttpMethod::Post,
            'path' => '/api/orders',
        ]);

        $tc = TestCaseModel::factory()->create([
            'test_suite_id' => $suite->id,
            'endpoint_id' => $endpoint->id,
            'status' => TestCaseStatus::Invalid,
        ]);

        // Submit broken DSL: version 9.9, method BOGUS, missing source, technique, etc.
        $brokenDsl = [
            'dsl_version' => '9.9',
            'title' => 'Broken DSL test',
            'endpoint' => ['method' => 'BOGUS', 'path' => '/non-existent'],
        ];

        $this->actingAs($tester)
            ->patch("/test-cases/{$tc->id}", [
                'title' => 'Broken DSL test',
                'dsl' => $brokenDsl,
            ])
            ->assertRedirect("/test-cases/{$tc->id}")
            ->assertSessionHas('warning');

        $tc->refresh();
        // Status MUST be Invalid, never Valid
        $this->assertEquals(TestCaseStatus::Invalid, $tc->status);
        $this->assertNotNull($tc->validation_errors);
        $this->assertNotEmpty($tc->validation_errors);
    }

    public function test_m11_generation_flow_creates_test_cases_and_tracks_progress(): void
    {
        $tester = User::factory()->tester()->create();
        $project = Project::factory()->for($tester, 'owner')->create();
        $spec = ApiSpec::factory()->for($project)->create(['version_label' => 'v1.0', 'status' => ApiSpecStatus::Parsed]);
        $suite = TestSuite::factory()->create([
            'project_id' => $project->id,
            'api_spec_id' => $spec->id,
            'created_by' => $tester->id,
        ]);
        [$endpoint1, $endpoint2] = $this->createItemEndpoints($spec);

        // 1. View M11 config form
        $this->actingAs($tester)
            ->get("/suites/{$suite->id}/generation-jobs/create")
            ->assertOk()
            ->assertSee('Cấu hình sinh test')
            ->assertSee('RULE-BASED')
            ->assertSee('/api/items');

        // 2. Submit RULE generation
        $response = $this->actingAs($tester)
            ->post("/suites/{$suite->id}/generation-jobs", [
                'strategy' => 'RULE',
                'endpoint_ids' => [$endpoint1->id, $endpoint2->id],
                'techniques' => ['HAPPY_PATH', 'BOUNDARY'],
            ]);

        $job = GenerationJob::query()->where('test_suite_id', $suite->id)->latest()->firstOrFail();
        $response->assertRedirect("/generation-jobs/{$job->id}");

        $this->assertEquals(GenerationJobStatus::Succeeded, $job->status);
        $this->assertEquals(GenerationStrategy::Rule, $job->strategy);
        $this->assertNotNull($job->started_at);
        $this->assertNotNull($job->finished_at);
        $this->assertNotNull($job->stats);

        // Cases come from the real RuleGenerator (S3-01): every generated case is VALID (S3-01 ✅)
        $cases = TestCaseModel::query()->where('generation_job_id', $job->id)->get();
        $this->assertGreaterThan(2, $cases->count());
        $this->assertEquals($cases->count(), $job->stats['generated']);
        $this->assertEquals($cases->count(), $job->stats['valid']);
        $this->assertEquals(0, $job->stats['invalid']);
        foreach ($cases as $case) {
            $this->assertEquals(TestCaseStatus::Valid, $case->status);
            $this->assertEquals(TestCaseSource::Rule, $case->source);
            $this->assertContains($case->technique, [TestTechnique::HappyPath, TestTechnique::Boundary]);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $case->fingerprint);
        }
        $this->assertTrue($cases->contains(fn (TestCaseModel $c): bool => $c->technique === TestTechnique::Boundary));

        // Verify HAPPY_PATH for endpoint2 (POST) dynamically uses 201, not hardcoded 200
        $happyPathPost = TestCaseModel::query()
            ->where('generation_job_id', $job->id)
            ->where('endpoint_id', $endpoint2->id)
            ->where('technique', TestTechnique::HappyPath)
            ->firstOrFail();

        $this->assertEquals(201, $happyPathPost->dsl['steps'][0]['assertions'][0]['equals']);

        // Verify NO mock LLM logs recorded
        $this->assertEquals(0, $job->llmCallLogs()->count());

        // 3. View M11 progress/stats page
        $this->actingAs($tester)
            ->get("/generation-jobs/{$job->id}")
            ->assertOk()
            ->assertSee('Tiến độ sinh test #'.$job->id)
            ->assertSee('SUCCEEDED')
            ->assertSee('Xem bộ kiểm thử');

        // 4. Test cancel queued job succeeds, but running job fails
        $queuedJob = GenerationJob::factory()->create([
            'project_id' => $project->id,
            'api_spec_id' => $spec->id,
            'test_suite_id' => $suite->id,
            'status' => GenerationJobStatus::Queued,
            'created_by' => $tester->id,
        ]);

        $this->actingAs($tester)
            ->post("/generation-jobs/{$queuedJob->id}/cancel")
            ->assertRedirect("/generation-jobs/{$queuedJob->id}")
            ->assertSessionHas('success');

        $queuedJob->refresh();
        $this->assertEquals(GenerationJobStatus::Failed, $queuedJob->status);

        // Running job cannot be cancelled (§4.1)
        $runningJob = GenerationJob::factory()->create([
            'project_id' => $project->id,
            'api_spec_id' => $spec->id,
            'test_suite_id' => $suite->id,
            'status' => GenerationJobStatus::Running,
            'created_by' => $tester->id,
        ]);

        $this->actingAs($tester)
            ->post("/generation-jobs/{$runningJob->id}/cancel")
            ->assertRedirect("/generation-jobs/{$runningJob->id}")
            ->assertSessionHas('error');

        $runningJob->refresh();
        $this->assertEquals(GenerationJobStatus::Running, $runningJob->status);
    }

    public function test_m11_rejects_unknown_strategies(): void
    {
        $tester = User::factory()->tester()->create();
        $project = Project::factory()->for($tester, 'owner')->create();
        $spec = ApiSpec::factory()->for($project)->create(['status' => ApiSpecStatus::Parsed]);
        $suite = TestSuite::factory()->create([
            'project_id' => $project->id,
            'api_spec_id' => $spec->id,
            'created_by' => $tester->id,
        ]);

        $this->actingAs($tester)
            ->post("/suites/{$suite->id}/generation-jobs", [
                'strategy' => 'GENETIC',
            ])
            ->assertSessionHasErrors(['strategy']);

        $this->assertSame(0, GenerationJob::query()->count());
    }

    public function test_fingerprint_preserves_request_body_title_and_description(): void
    {
        $dslA = [
            'dsl_version' => '1.0',
            'title' => 'Ignored root title A',
            'description' => 'Ignored root description A',
            'endpoint' => ['method' => 'POST', 'path' => '/api/products'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'Create product A',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/api/products',
                        'body' => [
                            'name' => 'Laptop',
                            'title' => 'Product Title A',
                            'description' => 'High-end laptop description',
                        ],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 201]],
                ],
            ],
        ];

        $dslB = [
            'dsl_version' => '1.0',
            'title' => 'Ignored root title B',
            'description' => 'Ignored root description B',
            'endpoint' => ['method' => 'POST', 'path' => '/api/products'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'Create product B',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/api/products',
                        'body' => [
                            'name' => 'Laptop',
                            'title' => 'Product Title B',
                            'description' => 'Budget laptop description',
                        ],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 201]],
                ],
            ],
        ];

        $fingerprints = app(TestFingerprintService::class);
        $fpA = $fingerprints->calculate($dslA);
        $fpB = $fingerprints->calculate($dslB);

        // Body differing only in title and description MUST NOT be stripped into identical fingerprints
        $this->assertNotEquals($fpA, $fpB);
    }

    public function test_update_dsl_rejects_payload_exceeding_5mb(): void
    {
        $tester = User::factory()->tester()->create();
        $suite = TestSuite::factory()->create();
        $suite->project->update(['owner_id' => $tester->id]);
        $testCase = TestCaseModel::factory()->create(['test_suite_id' => $suite->id]);

        $hugeDsl = str_repeat('x', 5400000);

        $response = $this->actingAs($tester)
            ->patch("/test-cases/{$testCase->id}", [
                'title' => 'Huge payload test',
                'dsl' => $hugeDsl,
            ]);

        $response->assertSessionHasErrors(['dsl']);
    }

    /**
     * @return array{0: Endpoint, 1: Endpoint}
     */
    private function createItemEndpoints(ApiSpec $spec): array
    {
        $endpoint1 = Endpoint::factory()->create([
            'api_spec_id' => $spec->id,
            'method' => HttpMethod::Get,
            'path' => '/api/items',
            'parameters' => [
                ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
            ],
            'responses' => ['200' => ['description' => 'OK'], '422' => ['description' => 'Unprocessable']],
        ]);
        $endpoint2 = Endpoint::factory()->create([
            'api_spec_id' => $spec->id,
            'method' => HttpMethod::Post,
            'path' => '/api/items',
            'request_body' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['name', 'quantity'],
                            'properties' => [
                                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 50],
                                'quantity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
                            ],
                        ],
                    ],
                ],
            ],
            'responses' => ['201' => ['description' => 'Created'], '422' => ['description' => 'Unprocessable']],
        ]);

        return [$endpoint1, $endpoint2];
    }

    /**
     * @return array{0: User, 1: ApiSpec, 2: TestSuite}
     */
    private function createOwnedSuite(): array
    {
        $tester = User::factory()->tester()->create();
        $project = Project::factory()->for($tester, 'owner')->create();
        $spec = ApiSpec::factory()->for($project)->create(['status' => ApiSpecStatus::Parsed]);
        $suite = TestSuite::factory()->create([
            'project_id' => $project->id,
            'api_spec_id' => $spec->id,
            'created_by' => $tester->id,
        ]);

        return [$tester, $spec, $suite];
    }

    public function test_m11_regenerating_same_rules_marks_cases_duplicate(): void
    {
        [$tester, $spec, $suite] = $this->createOwnedSuite();
        $this->createItemEndpoints($spec);

        $payload = ['strategy' => 'RULE', 'techniques' => ['HAPPY_PATH']];
        $this->actingAs($tester)->post("/suites/{$suite->id}/generation-jobs", $payload)->assertSessionHas('success');
        $this->actingAs($tester)->post("/suites/{$suite->id}/generation-jobs", $payload);

        $second = GenerationJob::query()->where('test_suite_id', $suite->id)->orderByDesc('id')->firstOrFail();

        // Every case is DUPLICATE (§10.5) and nothing failed: SUCCEEDED with no new case (§4.1, S14-06)
        $this->assertEquals(GenerationJobStatus::Succeeded, $second->status);
        $this->assertNull($second->error);
        $this->assertNotNull($second->stats);
        $this->assertGreaterThan(0, $second->stats['duplicates']);
        $this->assertEquals(0, $second->stats['valid']);
        $this->assertEquals(
            0,
            TestCaseModel::query()->where('generation_job_id', $second->id)->where('status', '!=', TestCaseStatus::Duplicate)->count()
        );
    }

    public function test_m11_job_without_endpoints_fails(): void
    {
        [$tester, , $suite] = $this->createOwnedSuite();

        $this->actingAs($tester)
            ->post("/suites/{$suite->id}/generation-jobs", ['strategy' => 'RULE'])
            ->assertSessionHas('error');

        $job = GenerationJob::query()->where('test_suite_id', $suite->id)->firstOrFail();
        $this->assertEquals(GenerationJobStatus::Failed, $job->status);
        $this->assertNotNull($job->error);
        $this->assertEquals(0, TestCaseModel::query()->where('generation_job_id', $job->id)->count());
    }

    public function test_m11_rejects_unsupported_techniques_and_foreign_endpoints(): void
    {
        [$tester, , $suite] = $this->createOwnedSuite();
        $foreignEndpoint = Endpoint::factory()->create();

        $this->actingAs($tester)
            ->post("/suites/{$suite->id}/generation-jobs", [
                'strategy' => 'RULE',
                'techniques' => ['SEQUENCE'],
                'endpoint_ids' => [$foreignEndpoint->id],
            ])
            ->assertSessionHasErrors(['techniques.0', 'endpoint_ids.0']);

        $this->assertEquals(0, GenerationJob::query()->count());
    }

    public function test_tester_cannot_generate_in_other_users_suite(): void
    {
        [, , $suite] = $this->createOwnedSuite();
        $other = User::factory()->tester()->create();

        $this->actingAs($other)
            ->post("/suites/{$suite->id}/generation-jobs", ['strategy' => 'RULE'])
            ->assertForbidden();

        $this->actingAs($other)
            ->get("/suites/{$suite->id}/generation-jobs/create")
            ->assertForbidden();
    }

    public function test_archived_test_case_cannot_be_edited_or_approved(): void
    {
        [$tester, $spec, $suite] = $this->createOwnedSuite();
        $endpoint = Endpoint::factory()->create(['api_spec_id' => $spec->id]);
        $tc = TestCaseModel::factory()->create([
            'test_suite_id' => $suite->id,
            'endpoint_id' => $endpoint->id,
            'status' => TestCaseStatus::Archived,
        ]);
        $originalDsl = $tc->dsl;

        $this->actingAs($tester)
            ->patch("/test-cases/{$tc->id}", ['title' => 'Tiêu đề mới', 'dsl' => ['dsl_version' => '1.0']])
            ->assertRedirect("/test-cases/{$tc->id}")
            ->assertSessionHas('error');

        $this->actingAs($tester)
            ->post("/test-cases/{$tc->id}/approve")
            ->assertSessionHas('error');

        $tc->refresh();
        $this->assertEquals(TestCaseStatus::Archived, $tc->status);
        $this->assertEquals($originalDsl, $tc->dsl);
    }

    public function test_bulk_action_only_transitions_eligible_cases_of_the_suite(): void
    {
        [$tester, $spec, $suite] = $this->createOwnedSuite();
        $endpoint = Endpoint::factory()->create(['api_spec_id' => $spec->id]);
        $valid = TestCaseModel::factory()->create(['test_suite_id' => $suite->id, 'endpoint_id' => $endpoint->id, 'status' => TestCaseStatus::Valid]);
        $invalid = TestCaseModel::factory()->create(['test_suite_id' => $suite->id, 'endpoint_id' => $endpoint->id, 'status' => TestCaseStatus::Invalid]);
        $otherSuiteCase = TestCaseModel::factory()->create(['status' => TestCaseStatus::Valid]);

        $this->actingAs($tester)
            ->post("/suites/{$suite->id}/bulk-action", [
                'action' => 'approve',
                'test_case_ids' => [$valid->id, $invalid->id, $otherSuiteCase->id],
            ])
            ->assertRedirect("/suites/{$suite->id}")
            ->assertSessionHas('success', 'Đã duyệt thành công 1 test case.');

        $this->assertEquals(TestCaseStatus::Approved, $valid->refresh()->status);
        $this->assertEquals(TestCaseStatus::Invalid, $invalid->refresh()->status);
        $this->assertEquals(TestCaseStatus::Valid, $otherSuiteCase->refresh()->status);
    }

    public function test_suite_with_runs_cannot_be_deleted(): void
    {
        [$tester, , $suite] = $this->createOwnedSuite();
        TestRun::factory()->create(['test_suite_id' => $suite->id]);

        $this->actingAs($tester)
            ->delete("/suites/{$suite->id}")
            ->assertRedirect("/suites/{$suite->id}")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('test_suites', ['id' => $suite->id]);
    }

    public function test_generation_job_creation_is_rate_limited(): void
    {
        [$tester, , $suite] = $this->createOwnedSuite();
        $limit = AppServiceProvider::GENERATION_JOBS_PER_MINUTE;

        for ($i = 0; $i < $limit; $i++) {
            $this->actingAs($tester)
                ->post("/suites/{$suite->id}/generation-jobs", ['strategy' => 'RULE'])
                ->assertRedirect();
        }
        $this->assertEquals($limit, GenerationJob::query()->where('test_suite_id', $suite->id)->count());

        // Request N+1 is throttled: error shown, no job created
        $this->actingAs($tester)
            ->from("/suites/{$suite->id}/generation-jobs/create")
            ->post("/suites/{$suite->id}/generation-jobs", ['strategy' => 'RULE'])
            ->assertRedirect("/suites/{$suite->id}/generation-jobs/create")
            ->assertSessionHas('error', 'Bạn đã tạo quá nhiều tiến trình sinh test. Vui lòng thử lại sau ít phút.');
        $this->assertEquals($limit, GenerationJob::query()->where('test_suite_id', $suite->id)->count());

        // JSON callers receive 429
        $this->actingAs($tester)
            ->postJson("/suites/{$suite->id}/generation-jobs", ['strategy' => 'RULE'])
            ->assertStatus(429);

        // The limit is per user: another owner is not affected
        [$otherTester, , $otherSuite] = $this->createOwnedSuite();
        $this->actingAs($otherTester)
            ->post("/suites/{$otherSuite->id}/generation-jobs", ['strategy' => 'RULE'])
            ->assertSessionMissing('error', 'Bạn đã tạo quá nhiều tiến trình sinh test. Vui lòng thử lại sau ít phút.');
        $this->assertEquals(1, GenerationJob::query()->where('test_suite_id', $otherSuite->id)->count());
    }

    public function test_editing_valid_case_into_invalid_dsl_is_rejected_and_keeps_case(): void
    {
        [$tester, $spec, $suite] = $this->createOwnedSuite();
        $endpoint = $this->createOrdersEndpoint($spec);
        $originalDsl = $this->ordersDsl('Original');
        $tc = TestCaseModel::factory()->create([
            'test_suite_id' => $suite->id,
            'endpoint_id' => $endpoint->id,
            'title' => 'Original',
            'dsl' => $originalDsl,
            'status' => TestCaseStatus::Valid,
            'source' => TestCaseSource::Rule,
        ]);

        $this->actingAs($tester)
            ->patch("/test-cases/{$tc->id}", [
                'title' => 'Broken',
                'dsl' => ['dsl_version' => '9.9', 'title' => 'Broken', 'endpoint' => ['method' => 'BOGUS', 'path' => '/nope']],
            ])
            ->assertRedirect("/test-cases/{$tc->id}")
            ->assertSessionHas('error');

        $tc->refresh();
        $this->assertEquals(TestCaseStatus::Valid, $tc->status);
        $this->assertEquals(TestCaseSource::Rule, $tc->source);
        $this->assertEquals('Original', $tc->title);
        $this->assertEquals($originalDsl, $tc->dsl);
    }

    public function test_editing_approved_case_into_duplicate_is_rejected_and_keeps_case(): void
    {
        [$tester, $spec, $suite] = $this->createOwnedSuite();
        $endpoint = $this->createOrdersEndpoint($spec);
        $fingerprints = app(TestFingerprintService::class);

        $existingDsl = $this->ordersDsl('Existing', 201);
        TestCaseModel::factory()->create([
            'test_suite_id' => $suite->id,
            'endpoint_id' => $endpoint->id,
            'dsl' => $existingDsl,
            'fingerprint' => $fingerprints->calculate($existingDsl),
            'status' => TestCaseStatus::Valid,
        ]);

        $approvedDsl = $this->ordersDsl('Approved', 422);
        $tc = TestCaseModel::factory()->create([
            'test_suite_id' => $suite->id,
            'endpoint_id' => $endpoint->id,
            'title' => 'Approved',
            'dsl' => $approvedDsl,
            'fingerprint' => $fingerprints->calculate($approvedDsl),
            'status' => TestCaseStatus::Approved,
            'source' => TestCaseSource::Rule,
        ]);

        // Same content as the existing case, different title -> would be DUPLICATE
        $this->actingAs($tester)
            ->patch("/test-cases/{$tc->id}", [
                'title' => 'Renamed copy',
                'dsl' => $this->ordersDsl('Renamed copy', 201),
            ])
            ->assertRedirect("/test-cases/{$tc->id}")
            ->assertSessionHas('error');

        $tc->refresh();
        $this->assertEquals(TestCaseStatus::Approved, $tc->status);
        $this->assertEquals('Approved', $tc->title);
        $this->assertEquals($approvedDsl, $tc->dsl);
        $this->assertEquals(0, TestCaseModel::query()->where('status', TestCaseStatus::Duplicate)->count());
    }

    public function test_editing_invalid_case_into_duplicate_is_rejected(): void
    {
        [$tester, $spec, $suite] = $this->createOwnedSuite();
        $endpoint = $this->createOrdersEndpoint($spec);
        $existingDsl = $this->ordersDsl('Existing', 201);
        TestCaseModel::factory()->create([
            'test_suite_id' => $suite->id,
            'endpoint_id' => $endpoint->id,
            'dsl' => $existingDsl,
            'fingerprint' => app(TestFingerprintService::class)->calculate($existingDsl),
            'status' => TestCaseStatus::Valid,
        ]);
        $tc = TestCaseModel::factory()->create([
            'test_suite_id' => $suite->id,
            'endpoint_id' => $endpoint->id,
            'status' => TestCaseStatus::Invalid,
        ]);
        $originalDsl = $tc->dsl;

        $this->actingAs($tester)
            ->patch("/test-cases/{$tc->id}", ['title' => 'Copy', 'dsl' => $this->ordersDsl('Copy', 201)])
            ->assertSessionHas('error');

        $tc->refresh();
        $this->assertEquals(TestCaseStatus::Invalid, $tc->status);
        $this->assertEquals($originalDsl, $tc->dsl);
    }

    public function test_suite_cannot_be_created_from_unparsed_spec(): void
    {
        $tester = User::factory()->tester()->create();
        $project = Project::factory()->for($tester, 'owner')->create();
        $failed = ApiSpec::factory()->for($project)->create(['status' => ApiSpecStatus::ParseFailed]);
        $uploaded = ApiSpec::factory()->for($project)->create(['status' => ApiSpecStatus::Uploaded]);

        foreach ([$failed, $uploaded] as $spec) {
            $this->actingAs($tester)
                ->post("/projects/{$project->id}/suites", ['api_spec_id' => $spec->id, 'name' => 'Bad suite'])
                ->assertSessionHasErrors(['api_spec_id']);
        }

        $this->assertEquals(0, TestSuite::query()->where('project_id', $project->id)->count());
    }

    public function test_generation_is_rejected_for_suite_bound_to_unparsed_spec(): void
    {
        [$tester, $spec, $suite] = $this->createOwnedSuite();
        $this->createItemEndpoints($spec);
        $spec->update(['status' => ApiSpecStatus::ParseFailed]);

        $this->actingAs($tester)
            ->post("/suites/{$suite->id}/generation-jobs", ['strategy' => 'RULE'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertEquals(0, GenerationJob::query()->where('test_suite_id', $suite->id)->count());
    }

    private function createOrdersEndpoint(ApiSpec $spec): Endpoint
    {
        return Endpoint::factory()->create([
            'api_spec_id' => $spec->id,
            'method' => HttpMethod::Post,
            'path' => '/api/orders',
            'responses' => ['201' => ['description' => 'Created'], '422' => ['description' => 'Unprocessable']],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function ordersDsl(string $title, int $status = 201): array
    {
        return [
            'dsl_version' => '1.0',
            'title' => $title,
            'endpoint' => ['method' => 'POST', 'path' => '/api/orders'],
            'technique' => 'HAPPY_PATH',
            'source' => 'MANUAL',
            'steps' => [
                [
                    'name' => 'Send order',
                    'request' => ['method' => 'POST', 'path' => '/api/orders'],
                    'assertions' => [['type' => 'status', 'equals' => $status]],
                ],
            ],
        ];
    }
}
