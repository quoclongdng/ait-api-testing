<?php

namespace Tests\Feature\Api;

use App\Enums\ApiSpecStatus;
use App\Enums\HttpMethod;
use App\Enums\TestCaseSource;
use App\Enums\TestCaseStatus;
use App\Enums\TestTechnique;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Models\Project;
use App\Models\TestCase as TestCaseModel;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\TestEngine\TestFingerprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * S8-02: test case lifecycle API (docs/DESIGN.md §4.2).
 */
class TestCaseApiTest extends TestCase
{
    use RefreshDatabase;

    private User $tester;

    private TestSuite $suite;

    private Endpoint $endpoint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tester = User::factory()->tester()->create();
        $project = Project::factory()->for($this->tester, 'owner')->create();
        $spec = ApiSpec::factory()->for($project)->create(['status' => ApiSpecStatus::Parsed]);
        $this->suite = TestSuite::factory()->create([
            'project_id' => $project->id,
            'api_spec_id' => $spec->id,
            'created_by' => $this->tester->id,
        ]);
        $this->endpoint = Endpoint::factory()->create([
            'api_spec_id' => $spec->id,
            'method' => HttpMethod::Post,
            'path' => '/api/orders',
            'responses' => ['201' => ['description' => 'Created'], '422' => ['description' => 'Unprocessable']],
        ]);
    }

    public function test_guest_gets_unauthenticated_envelope_on_every_endpoint(): void
    {
        $case = $this->makeCase(TestCaseStatus::Valid);

        $requests = [
            ['GET', "/api/v1/suites/{$this->suite->id}/test-cases"],
            ['GET', "/api/v1/test-cases/{$case->id}"],
            ['PATCH', "/api/v1/test-cases/{$case->id}"],
            ['POST', "/api/v1/test-cases/{$case->id}/approve"],
            ['POST', "/api/v1/test-cases/{$case->id}/archive"],
        ];

        foreach ($requests as [$method, $uri]) {
            $this->json($method, $uri, ['dsl' => $this->ordersDsl('x')])
                ->assertStatus(401)
                ->assertJsonPath('error.code', 'UNAUTHENTICATED');
        }

        $this->assertSame(TestCaseStatus::Valid, $case->refresh()->status);
    }

    public function test_tester_cannot_touch_another_users_test_cases(): void
    {
        $case = $this->makeCase(TestCaseStatus::Valid);
        $other = User::factory()->tester()->create();

        $this->actingAs($other)->getJson("/api/v1/suites/{$this->suite->id}/test-cases")
            ->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        $this->actingAs($other)->getJson("/api/v1/test-cases/{$case->id}")
            ->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        $this->actingAs($other)->patchJson("/api/v1/test-cases/{$case->id}", ['dsl' => $this->ordersDsl('Hijack')])
            ->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        $this->actingAs($other)->postJson("/api/v1/test-cases/{$case->id}/approve")
            ->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        $this->actingAs($other)->postJson("/api/v1/test-cases/{$case->id}/archive")
            ->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');

        $case->refresh();
        $this->assertSame(TestCaseStatus::Valid, $case->status);
        $this->assertSame('Original', $case->title);
    }

    public function test_admin_can_review_any_test_case(): void
    {
        $admin = User::factory()->admin()->create();
        $case = $this->makeCase(TestCaseStatus::Valid);

        $this->actingAs($admin)->getJson("/api/v1/test-cases/{$case->id}")->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/test-cases/{$case->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'APPROVED');
    }

    public function test_unknown_test_case_returns_not_found_envelope(): void
    {
        $this->actingAs($this->tester)->getJson('/api/v1/test-cases/999999')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_list_is_paginated_and_filterable(): void
    {
        $valid = $this->makeCase(TestCaseStatus::Valid);
        $this->makeCase(TestCaseStatus::Invalid, ['source' => TestCaseSource::Llm]);
        $this->makeCase(TestCaseStatus::Approved);

        $this->actingAs($this->tester)->getJson("/api/v1/suites/{$this->suite->id}/test-cases?per_page=2")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.current_page', 1);

        $this->actingAs($this->tester)->getJson("/api/v1/suites/{$this->suite->id}/test-cases?status=VALID")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $valid->id);

        $this->actingAs($this->tester)->getJson("/api/v1/suites/{$this->suite->id}/test-cases?source=LLM")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'INVALID');

        $this->actingAs($this->tester)->getJson("/api/v1/suites/{$this->suite->id}/test-cases?status=BOGUS")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_show_returns_contract_shape(): void
    {
        $case = $this->makeCase(TestCaseStatus::Invalid, ['validation_errors' => ['Thiếu trường steps']]);

        $this->actingAs($this->tester)->getJson("/api/v1/test-cases/{$case->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'id', 'test_suite_id', 'endpoint_id', 'generation_job_id', 'source', 'technique', 'title', 'dsl',
                'dsl_version', 'fingerprint', 'status', 'validation_errors', 'repair_attempts', 'created_at', 'updated_at',
            ]])
            ->assertJsonPath('data.status', 'INVALID')
            ->assertJsonPath('data.validation_errors.0', 'Thiếu trường steps');
    }

    public function test_approve_moves_valid_to_approved(): void
    {
        $case = $this->makeCase(TestCaseStatus::Valid);

        $this->actingAs($this->tester)->postJson("/api/v1/test-cases/{$case->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'APPROVED');

        $this->assertSame(TestCaseStatus::Approved, $case->refresh()->status);
    }

    /**
     * @return array<string, array{TestCaseStatus}>
     */
    public static function nonApprovableStatuses(): array
    {
        return [
            'INVALID' => [TestCaseStatus::Invalid],
            'DUPLICATE' => [TestCaseStatus::Duplicate],
            'APPROVED' => [TestCaseStatus::Approved],
            'ARCHIVED' => [TestCaseStatus::Archived],
        ];
    }

    #[DataProvider('nonApprovableStatuses')]
    public function test_approve_from_illegal_status_is_rejected(TestCaseStatus $status): void
    {
        $case = $this->makeCase($status);

        $this->actingAs($this->tester)->postJson("/api/v1/test-cases/{$case->id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');

        $this->assertSame($status, $case->refresh()->status);
    }

    /**
     * @return array<string, array{TestCaseStatus}>
     */
    public static function archivableStatuses(): array
    {
        return [
            'VALID' => [TestCaseStatus::Valid],
            'APPROVED' => [TestCaseStatus::Approved],
        ];
    }

    #[DataProvider('archivableStatuses')]
    public function test_archive_moves_valid_or_approved_to_archived(TestCaseStatus $status): void
    {
        $case = $this->makeCase($status);

        $this->actingAs($this->tester)->postJson("/api/v1/test-cases/{$case->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.status', 'ARCHIVED');

        $this->assertSame(TestCaseStatus::Archived, $case->refresh()->status);
    }

    /**
     * @return array<string, array{TestCaseStatus}>
     */
    public static function nonArchivableStatuses(): array
    {
        return [
            'INVALID' => [TestCaseStatus::Invalid],
            'DUPLICATE' => [TestCaseStatus::Duplicate],
            'ARCHIVED' => [TestCaseStatus::Archived],
        ];
    }

    #[DataProvider('nonArchivableStatuses')]
    public function test_archive_from_illegal_status_is_rejected(TestCaseStatus $status): void
    {
        $case = $this->makeCase($status);

        $this->actingAs($this->tester)->postJson("/api/v1/test-cases/{$case->id}/archive")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');

        $this->assertSame($status, $case->refresh()->status);
    }

    public function test_manual_edit_turns_invalid_into_valid_and_recomputes_fingerprint(): void
    {
        $case = $this->makeCase(TestCaseStatus::Invalid, [
            'fingerprint' => null,
            'validation_errors' => ['old error'],
            'dsl' => ['dsl_version' => '1.0', 'title' => 'Broken'],
        ]);
        $dsl = $this->ordersDsl('Fixed by hand', 422);

        $this->actingAs($this->tester)->patchJson("/api/v1/test-cases/{$case->id}", ['dsl' => $dsl])
            ->assertOk()
            ->assertJsonPath('data.status', 'VALID')
            ->assertJsonPath('data.source', 'MANUAL')
            ->assertJsonPath('data.title', 'Fixed by hand')
            ->assertJsonPath('data.validation_errors', null)
            ->assertJsonPath('data.fingerprint', app(TestFingerprintService::class)->calculate($dsl));

        $case->refresh();
        $this->assertSame(TestCaseStatus::Valid, $case->status);
        $this->assertSame(TestCaseSource::Manual, $case->source);
        $this->assertEquals($dsl, $case->dsl);
    }

    /**
     * Regression (S10-01): Laravel decodes JSON bodies associatively, so `"query": {}` used to reach
     * the validator as `[]` and fail the DSL schema (type: object), and the fingerprint changed.
     */
    public function test_manual_edit_keeps_empty_objects_in_json_body_and_in_string_dsl(): void
    {
        $dslJson = '{"dsl_version":"1.0","title":"Empty objects","endpoint":{"method":"POST","path":"/api/orders"},'
            .'"technique":"HAPPY_PATH","source":"MANUAL","variables":{},"steps":[{"name":"Send","request":{"method":"POST",'
            .'"path":"/api/orders","query":{},"headers":{}},"assertions":[{"type":"status","equals":201}]}]}';
        $fingerprint = app(TestFingerprintService::class)->calculate($dslJson);

        foreach (['body' => '{"dsl":'.$dslJson.'}', 'string' => (string) json_encode(['dsl' => $dslJson])] as $mode => $payload) {
            $case = $this->makeCase(TestCaseStatus::Valid, ['fingerprint' => null]);

            $this->actingAs($this->tester)
                ->call('PATCH', "/api/v1/test-cases/{$case->id}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $payload)
                ->assertOk()
                ->assertJsonPath('data.status', 'VALID')
                ->assertJsonPath('data.fingerprint', $fingerprint);

            $raw = (string) $case->refresh()->getRawOriginal('dsl');
            $this->assertStringContainsString('"query": {}', $raw, $mode);
            $this->assertStringContainsString('"headers": {}', $raw, $mode);
            $case->forceFill(['status' => TestCaseStatus::Archived])->save();
        }
    }

    public function test_manual_edit_that_still_fails_keeps_invalid_with_errors(): void
    {
        $case = $this->makeCase(TestCaseStatus::Invalid, ['fingerprint' => null]);

        $response = $this->actingAs($this->tester)->patchJson("/api/v1/test-cases/{$case->id}", [
            'title' => 'Still broken',
            'dsl' => ['dsl_version' => '9.9', 'title' => 'Still broken', 'endpoint' => ['method' => 'BOGUS', 'path' => '/nope']],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'INVALID')
            ->assertJsonPath('data.title', 'Still broken')
            ->assertJsonPath('data.dsl_version', '1.0');
        $this->assertNotEmpty($response->json('data.validation_errors'));
        $this->assertSame(TestCaseStatus::Invalid, $case->refresh()->status);
    }

    public function test_editing_approved_case_returns_it_to_valid_for_re_approval(): void
    {
        $case = $this->makeCase(TestCaseStatus::Approved);

        $this->actingAs($this->tester)->patchJson("/api/v1/test-cases/{$case->id}", [
            'title' => 'Edited after approval',
            'dsl' => $this->ordersDsl('Edited after approval', 422),
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'VALID')
            ->assertJsonPath('data.title', 'Edited after approval');

        $this->assertSame(TestCaseStatus::Valid, $case->refresh()->status);
    }

    #[DataProvider('archivableStatuses')]
    public function test_invalid_dsl_on_valid_or_approved_case_is_rejected_without_saving(TestCaseStatus $status): void
    {
        $case = $this->makeCase($status);
        $originalDsl = $case->dsl;

        $response = $this->actingAs($this->tester)->patchJson("/api/v1/test-cases/{$case->id}", [
            'dsl' => ['dsl_version' => '1.0', 'title' => 'Broken', 'endpoint' => ['method' => 'POST', 'path' => '/missing']],
        ]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'DSL_INVALID');
        $this->assertNotEmpty($response->json('error.details.dsl'));

        $case->refresh();
        $this->assertSame($status, $case->status);
        $this->assertEquals($originalDsl, $case->dsl);
        $this->assertSame('Original', $case->title);
    }

    public function test_edit_that_duplicates_another_case_is_rejected_with_conflict(): void
    {
        $existingDsl = $this->ordersDsl('Existing', 201);
        $existing = $this->makeCase(TestCaseStatus::Valid, [
            'dsl' => $existingDsl,
            'fingerprint' => app(TestFingerprintService::class)->calculate($existingDsl),
        ]);
        $case = $this->makeCase(TestCaseStatus::Valid, ['dsl' => $this->ordersDsl('Original', 422)]);

        $this->actingAs($this->tester)->patchJson("/api/v1/test-cases/{$case->id}", ['dsl' => $this->ordersDsl('Copy', 201)])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CONFLICT')
            ->assertJsonPath('error.details.duplicate_of', $existing->id);

        $this->assertSame(TestCaseStatus::Valid, $case->refresh()->status);
        $this->assertSame(0, TestCaseModel::query()->where('status', TestCaseStatus::Duplicate)->count());
    }

    /**
     * @return array<string, array{TestCaseStatus}>
     */
    public static function activeStatuses(): array
    {
        return [
            'VALID' => [TestCaseStatus::Valid],
            'APPROVED' => [TestCaseStatus::Approved],
        ];
    }

    #[DataProvider('activeStatuses')]
    public function test_title_only_edit_is_not_blocked_by_the_cases_own_duplicate_rows(TestCaseStatus $status): void
    {
        $dsl = $this->ordersDsl('Original', 201);
        $fingerprint = app(TestFingerprintService::class)->calculate($dsl);
        $case = $this->makeCase($status, ['dsl' => $dsl, 'fingerprint' => $fingerprint]);
        // A second generation run stored a DUPLICATE row carrying the same fingerprint.
        $this->makeCase(TestCaseStatus::Duplicate, ['dsl' => $dsl, 'fingerprint' => $fingerprint]);

        $edited = $this->ordersDsl('Renamed', 201);
        $edited['description'] = 'Only the title and description change.';

        $this->actingAs($this->tester)->patchJson("/api/v1/test-cases/{$case->id}", ['dsl' => $edited])
            ->assertOk()
            ->assertJsonPath('data.status', 'VALID')
            ->assertJsonPath('data.title', 'Renamed')
            ->assertJsonPath('data.fingerprint', $fingerprint);

        $case->refresh();
        $this->assertSame(TestCaseStatus::Valid, $case->status);
        $this->assertSame('Renamed', $case->title);
    }

    public function test_conflict_points_at_the_active_original_not_at_a_duplicate_row(): void
    {
        $dsl = $this->ordersDsl('Existing', 201);
        $fingerprint = app(TestFingerprintService::class)->calculate($dsl);
        // The DUPLICATE row is older than the original on purpose: the lookup must skip it by status.
        $this->makeCase(TestCaseStatus::Duplicate, ['dsl' => $dsl, 'fingerprint' => $fingerprint]);
        $original = $this->makeCase(TestCaseStatus::Approved, ['dsl' => $dsl, 'fingerprint' => $fingerprint]);
        $case = $this->makeCase(TestCaseStatus::Invalid, [
            'fingerprint' => null,
            'dsl' => ['dsl_version' => '1.0', 'title' => 'Broken'],
        ]);

        $this->actingAs($this->tester)->patchJson("/api/v1/test-cases/{$case->id}", ['dsl' => $this->ordersDsl('Fix', 201)])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CONFLICT')
            ->assertJsonPath('error.details.duplicate_of', $original->id);

        $this->assertSame(TestCaseStatus::Invalid, $case->refresh()->status);
    }

    public function test_archived_case_with_same_content_does_not_block_an_edit(): void
    {
        $dsl = $this->ordersDsl('Retired', 201);
        $this->makeCase(TestCaseStatus::Archived, [
            'dsl' => $dsl,
            'fingerprint' => app(TestFingerprintService::class)->calculate($dsl),
        ]);
        $case = $this->makeCase(TestCaseStatus::Valid, ['dsl' => $this->ordersDsl('Original', 422)]);

        $this->actingAs($this->tester)->patchJson("/api/v1/test-cases/{$case->id}", ['dsl' => $this->ordersDsl('Revived', 201)])
            ->assertOk()
            ->assertJsonPath('data.status', 'VALID');
    }

    /**
     * @return array<string, array{TestCaseStatus}>
     */
    public static function readOnlyStatuses(): array
    {
        return [
            'DUPLICATE' => [TestCaseStatus::Duplicate],
            'ARCHIVED' => [TestCaseStatus::Archived],
        ];
    }

    #[DataProvider('readOnlyStatuses')]
    public function test_duplicate_or_archived_case_cannot_be_edited(TestCaseStatus $status): void
    {
        $case = $this->makeCase($status);

        $this->actingAs($this->tester)->patchJson("/api/v1/test-cases/{$case->id}", ['dsl' => $this->ordersDsl('Edit', 422)])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');

        $case->refresh();
        $this->assertSame($status, $case->status);
        $this->assertSame('Original', $case->title);
    }

    public function test_patch_payload_is_validated(): void
    {
        $case = $this->makeCase(TestCaseStatus::Valid);

        $this->actingAs($this->tester)->patchJson("/api/v1/test-cases/{$case->id}", [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['dsl']]]);

        $this->actingAs($this->tester)->patchJson("/api/v1/test-cases/{$case->id}", ['title' => '', 'dsl' => $this->ordersDsl('x')])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['title']]]);
    }

    public function test_full_lifecycle_follows_state_machine(): void
    {
        $case = $this->makeCase(TestCaseStatus::Invalid, ['fingerprint' => null]);
        $id = $case->id;

        $this->actingAs($this->tester);

        // INVALID cannot be approved or archived
        $this->postJson("/api/v1/test-cases/{$id}/approve")->assertStatus(409);
        $this->postJson("/api/v1/test-cases/{$id}/archive")->assertStatus(409);

        // INVALID -> VALID (manual fix)
        $this->patchJson("/api/v1/test-cases/{$id}", ['dsl' => $this->ordersDsl('Step 1', 201)])
            ->assertOk()->assertJsonPath('data.status', 'VALID');

        // VALID -> APPROVED, then APPROVED cannot be approved again
        $this->postJson("/api/v1/test-cases/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $this->postJson("/api/v1/test-cases/{$id}/approve")->assertStatus(409);

        // APPROVED -> VALID by editing DSL, then re-approve
        $this->patchJson("/api/v1/test-cases/{$id}", ['dsl' => $this->ordersDsl('Step 2', 422)])
            ->assertOk()->assertJsonPath('data.status', 'VALID');
        $this->postJson("/api/v1/test-cases/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'APPROVED');

        // APPROVED -> ARCHIVED; ARCHIVED is terminal
        $this->postJson("/api/v1/test-cases/{$id}/archive")->assertOk()->assertJsonPath('data.status', 'ARCHIVED');
        $this->postJson("/api/v1/test-cases/{$id}/approve")->assertStatus(409);
        $this->postJson("/api/v1/test-cases/{$id}/archive")->assertStatus(409);
        $this->patchJson("/api/v1/test-cases/{$id}", ['dsl' => $this->ordersDsl('Step 3', 201)])->assertStatus(409);

        $this->assertSame(TestCaseStatus::Archived, TestCaseModel::query()->findOrFail($id)->status);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeCase(TestCaseStatus $status, array $attributes = []): TestCaseModel
    {
        return TestCaseModel::factory()->create(array_merge([
            'test_suite_id' => $this->suite->id,
            'endpoint_id' => $this->endpoint->id,
            'title' => 'Original',
            'status' => $status,
            'source' => TestCaseSource::Rule,
            'technique' => TestTechnique::HappyPath,
        ], $attributes));
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
