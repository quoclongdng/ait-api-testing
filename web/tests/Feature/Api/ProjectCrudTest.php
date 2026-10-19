<?php

namespace Tests\Feature\Api;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_receives_unauthenticated_envelope(): void
    {
        $this->getJson('/api/v1/projects', ['X-Request-Id' => 'project-guest'])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonPath('trace_id', 'project-guest');
    }

    public function test_tester_lists_only_owned_projects_with_pagination_envelope(): void
    {
        $tester = User::factory()->tester()->create();
        $otherTester = User::factory()->tester()->create();
        $ownedProject = Project::factory()->for($tester, 'owner')->create(['name' => 'Owned project']);
        Project::factory()->for($otherTester, 'owner')->create(['name' => 'Hidden project']);

        $this->actingAs($tester)
            ->getJson('/api/v1/projects?per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownedProject->id)
            ->assertJsonPath('data.0.name', 'Owned project')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonMissing(['name' => 'Hidden project']);
    }

    public function test_admin_lists_all_projects(): void
    {
        $admin = User::factory()->admin()->create();
        Project::factory()->count(2)->create();

        $this->actingAs($admin)
            ->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_tester_can_create_project_and_owner_is_derived_from_session(): void
    {
        $tester = User::factory()->tester()->create();
        $otherTester = User::factory()->tester()->create();

        $this->actingAs($tester)
            ->postJson('/api/v1/projects', [
                'name' => '  Store API  ',
                'description' => 'Kiểm thử API cửa hàng.',
                'default_target_url' => 'http://benchmark:8000/',
                'owner_id' => $otherTester->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Store API')
            ->assertJsonPath('data.owner_id', $tester->id)
            ->assertJsonPath('data.default_target_url', 'http://benchmark:8000');

        $this->assertDatabaseHas('projects', [
            'name' => 'Store API',
            'owner_id' => $tester->id,
            'default_target_url' => 'http://benchmark:8000',
        ]);
    }

    public function test_create_project_validation_uses_standard_error_envelope(): void
    {
        $tester = User::factory()->tester()->create();

        $this->actingAs($tester)
            ->postJson('/api/v1/projects', [
                'name' => '',
                'default_target_url' => 'ftp://unsafe.example',
            ], ['X-Request-Id' => 'project-validation'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('trace_id', 'project-validation')
            ->assertJsonStructure(['error' => ['details' => ['name', 'default_target_url']]]);
    }

    public function test_owner_can_view_update_and_soft_delete_project(): void
    {
        $tester = User::factory()->tester()->create();
        $project = Project::factory()->for($tester, 'owner')->create(['name' => 'Before']);

        $this->actingAs($tester)
            ->getJson("/api/v1/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $project->id);

        $this->actingAs($tester)
            ->patchJson("/api/v1/projects/{$project->id}", [
                'name' => 'After',
                'description' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'After')
            ->assertJsonPath('data.description', null);

        $this->actingAs($tester)
            ->deleteJson("/api/v1/projects/{$project->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('projects', ['id' => $project->id]);
    }

    public function test_tester_cannot_access_another_users_project(): void
    {
        $tester = User::factory()->tester()->create();
        $otherTester = User::factory()->tester()->create();
        $project = Project::factory()->for($otherTester, 'owner')->create();

        $this->actingAs($tester)
            ->getJson("/api/v1/projects/{$project->id}")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->actingAs($tester)
            ->patchJson("/api/v1/projects/{$project->id}", ['name' => 'Stolen'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->actingAs($tester)
            ->deleteJson("/api/v1/projects/{$project->id}")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'owner_id' => $otherTester->id,
        ]);
    }

    public function test_admin_can_manage_another_users_project(): void
    {
        $admin = User::factory()->admin()->create();
        $tester = User::factory()->tester()->create();
        $project = Project::factory()->for($tester, 'owner')->create();

        $this->actingAs($admin)
            ->patchJson("/api/v1/projects/{$project->id}", ['name' => 'Admin updated'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Admin updated');

        $this->actingAs($admin)
            ->deleteJson("/api/v1/projects/{$project->id}")
            ->assertNoContent();
    }
}
