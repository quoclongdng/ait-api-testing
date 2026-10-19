<?php

namespace Tests\Feature\Api;

use App\Enums\ApiSpecStatus;
use App\Models\ApiSpec;
use App\Models\BusinessRule;
use App\Models\Endpoint;
use App\Models\Project;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression (S10-01 audit): the business-rules and suites paths of web-api.openapi.yaml were not
 * registered under /api/v1 and returned 404 NOT_FOUND.
 */
class BusinessRuleAndSuiteApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $otherTester;

    private Project $project;

    private ApiSpec $spec;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->tester()->create();
        $this->otherTester = User::factory()->tester()->create();
        $this->project = Project::factory()->create(['owner_id' => $this->owner->id]);
        $this->spec = ApiSpec::factory()->create(['project_id' => $this->project->id, 'status' => ApiSpecStatus::Parsed]);
    }

    public function test_owner_manages_business_rules_through_the_api(): void
    {
        $endpoint = Endpoint::factory()->create(['api_spec_id' => $this->spec->id]);

        $created = $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/business-rules", [
            'code' => 'br-001',
            'endpoint_id' => $endpoint->id,
            'description' => 'Số lượng phải lớn hơn 0.',
        ]);
        $created->assertCreated()
            ->assertJsonPath('data.code', 'BR-001')
            ->assertJsonPath('data.endpoint_id', $endpoint->id)
            ->assertJsonPath('data.project_id', $this->project->id);
        $ruleId = (int) $created->json('data.id');

        $this->actingAs($this->owner)->getJson("/api/v1/projects/{$this->project->id}/business-rules")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $ruleId);

        $this->actingAs($this->owner)->getJson("/api/v1/business-rules/{$ruleId}")
            ->assertOk()->assertJsonPath('data.description', 'Số lượng phải lớn hơn 0.');

        $this->actingAs($this->owner)->patchJson("/api/v1/business-rules/{$ruleId}", ['endpoint_id' => null])
            ->assertOk()
            ->assertJsonPath('data.endpoint_id', null)
            ->assertJsonPath('data.code', 'BR-001');

        $this->actingAs($this->owner)->patchJson("/api/v1/business-rules/{$ruleId}", [])->assertStatus(422);

        $this->actingAs($this->owner)->deleteJson("/api/v1/business-rules/{$ruleId}")->assertNoContent();
        $this->assertDatabaseMissing('business_rules', ['id' => $ruleId]);
    }

    public function test_business_rule_api_validates_and_enforces_ownership(): void
    {
        $rule = BusinessRule::factory()->create(['project_id' => $this->project->id, 'code' => 'BR-OWN']);

        $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/business-rules", ['code' => 'BR-X'])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->actingAs($this->otherTester)->getJson("/api/v1/projects/{$this->project->id}/business-rules")
            ->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        $this->actingAs($this->otherTester)->postJson("/api/v1/projects/{$this->project->id}/business-rules", [
            'code' => 'BR-Y', 'description' => 'x',
        ])->assertForbidden();
        $this->actingAs($this->otherTester)->getJson("/api/v1/business-rules/{$rule->id}")->assertForbidden();
        $this->actingAs($this->otherTester)->patchJson("/api/v1/business-rules/{$rule->id}", ['code' => 'HACK'])->assertForbidden();
        $this->actingAs($this->otherTester)->deleteJson("/api/v1/business-rules/{$rule->id}")->assertForbidden();
        $this->assertDatabaseHas('business_rules', ['id' => $rule->id, 'code' => 'BR-OWN']);

        $this->actingAs($this->owner)->getJson('/api/v1/business-rules/999999')->assertNotFound();
    }

    public function test_guest_gets_unauthenticated_envelope(): void
    {
        $rule = BusinessRule::factory()->create(['project_id' => $this->project->id]);

        $this->getJson("/api/v1/business-rules/{$rule->id}")
            ->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->getJson("/api/v1/projects/{$this->project->id}/suites")->assertUnauthorized();
        $this->getJson('/api/v1/admin/users')->assertUnauthorized();
    }

    public function test_owner_creates_lists_and_reads_suites_through_the_api(): void
    {
        $created = $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/suites", [
            'api_spec_id' => $this->spec->id,
            'name' => 'Bộ kiểm thử API',
        ]);
        $created->assertCreated()
            ->assertJsonPath('data.name', 'Bộ kiểm thử API')
            ->assertJsonPath('data.api_spec_id', $this->spec->id)
            ->assertJsonPath('data.created_by', $this->owner->id);
        $suiteId = (int) $created->json('data.id');

        $this->actingAs($this->owner)->getJson("/api/v1/projects/{$this->project->id}/suites?per_page=5")
            ->assertOk()
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $suiteId);

        $this->actingAs($this->owner)->getJson("/api/v1/suites/{$suiteId}")
            ->assertOk()->assertJsonPath('data.project_id', $this->project->id);
    }

    public function test_suite_api_validates_and_enforces_ownership(): void
    {
        $failedSpec = ApiSpec::factory()->create(['project_id' => $this->project->id, 'status' => ApiSpecStatus::ParseFailed]);
        $suite = TestSuite::factory()->create(['project_id' => $this->project->id, 'api_spec_id' => $this->spec->id]);

        $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/suites", [
            'api_spec_id' => $failedSpec->id,
            'name' => 'Không hợp lệ',
        ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->actingAs($this->otherTester)->getJson("/api/v1/projects/{$this->project->id}/suites")->assertForbidden();
        $this->actingAs($this->otherTester)->getJson("/api/v1/suites/{$suite->id}")->assertForbidden();
        $this->actingAs($this->otherTester)->postJson("/api/v1/projects/{$this->project->id}/suites", [
            'api_spec_id' => $this->spec->id,
            'name' => 'Chen ngang',
        ])->assertForbidden();
    }
}
