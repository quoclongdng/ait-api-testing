<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/projects')->assertRedirect('/login');
    }

    public function test_tester_sees_only_owned_projects_on_m05(): void
    {
        $tester = User::factory()->tester()->create();
        $otherTester = User::factory()->tester()->create();
        Project::factory()->for($tester, 'owner')->create(['name' => 'Dự án của tôi']);
        Project::factory()->for($otherTester, 'owner')->create(['name' => 'Dự án người khác']);

        $this->actingAs($tester)
            ->get('/projects')
            ->assertOk()
            ->assertSee('Dự án của tôi')
            ->assertSee('Dự án của tôi')
            ->assertDontSee('Dự án người khác')
            ->assertSee('Tạo dự án mới');
    }

    public function test_project_web_forms_create_update_and_delete_owned_project(): void
    {
        $tester = User::factory()->tester()->create();

        $this->actingAs($tester)
            ->post('/projects', [
                '_form' => 'create-project',
                'name' => 'Dự án mới',
                'description' => 'Mô tả',
                'default_target_url' => 'http://benchmark:8000',
            ])
            ->assertRedirect('/projects')
            ->assertSessionHas('success');

        $project = Project::query()->where('owner_id', $tester->id)->firstOrFail();

        $this->actingAs($tester)
            ->patch("/projects/{$project->id}", [
                '_form' => "edit-project-{$project->id}",
                'name' => 'Dự án đã sửa',
                'description' => null,
                'default_target_url' => null,
            ])
            ->assertRedirect('/projects')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => 'Dự án đã sửa']);

        $this->actingAs($tester)
            ->delete("/projects/{$project->id}")
            ->assertRedirect('/projects')
            ->assertSessionHas('success');

        $this->assertSoftDeleted('projects', ['id' => $project->id]);
    }

    public function test_tester_cannot_update_another_users_project_through_web(): void
    {
        $tester = User::factory()->tester()->create();
        $otherTester = User::factory()->tester()->create();
        $project = Project::factory()->for($otherTester, 'owner')->create();

        $this->actingAs($tester)
            ->patch("/projects/{$project->id}", ['name' => 'Không được phép'])
            ->assertForbidden();
    }
}
