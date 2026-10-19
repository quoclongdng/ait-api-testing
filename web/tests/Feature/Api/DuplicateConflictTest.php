<?php

namespace Tests\Feature\Api;

use App\Models\BusinessRule;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression (S10-01 r2): duplicates returned 422 VALIDATION_FAILED while web-api.openapi.yaml and
 * docs/DESIGN.md §6 document 409 CONFLICT for POST /admin/users and the business-rule endpoints.
 */
class DuplicateConflictTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_user_with_an_existing_email_returns_conflict(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['email' => 'dup@example.test']);

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/users', [
                'name' => 'Người dùng trùng',
                'email' => 'dup@example.test',
                'password' => 'password-123',
                'role' => 'TESTER',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CONFLICT')
            ->assertJsonStructure(['error' => ['details' => ['email']], 'trace_id']);

        $this->assertSame(1, User::query()->where('email', 'dup@example.test')->count());
    }

    public function test_creating_a_business_rule_with_an_existing_code_returns_conflict(): void
    {
        $tester = User::factory()->tester()->create();
        $project = Project::factory()->for($tester, 'owner')->create();
        BusinessRule::factory()->create(['project_id' => $project->id, 'code' => 'BR-DUP']);

        $this->actingAs($tester)
            ->postJson("/api/v1/projects/{$project->id}/business-rules", ['code' => 'br-dup', 'description' => 'Trùng mã'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CONFLICT')
            ->assertJsonStructure(['error' => ['details' => ['code']]]);
    }

    public function test_renaming_a_business_rule_to_an_existing_code_returns_conflict(): void
    {
        $tester = User::factory()->tester()->create();
        $project = Project::factory()->for($tester, 'owner')->create();
        BusinessRule::factory()->create(['project_id' => $project->id, 'code' => 'BR-ONE']);
        $rule = BusinessRule::factory()->create(['project_id' => $project->id, 'code' => 'BR-TWO']);

        $this->actingAs($tester)
            ->patchJson("/api/v1/business-rules/{$rule->id}", ['code' => 'BR-ONE'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CONFLICT');

        // Keeping its own code is not a conflict.
        $this->actingAs($tester)
            ->patchJson("/api/v1/business-rules/{$rule->id}", ['code' => 'BR-TWO', 'description' => 'Cập nhật'])
            ->assertOk();
        $this->assertSame('BR-TWO', $rule->refresh()->code);
    }
}
