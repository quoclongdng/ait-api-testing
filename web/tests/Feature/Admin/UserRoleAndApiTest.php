<?php

namespace Tests\Feature\Admin;

use App\Actions\User\UpdateUserAction;
use App\Enums\ErrorCode;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression (S10-01 audit): ADMIN could not change a user's role and GET/POST/PATCH
 * /api/v1/admin/users from the frozen contract returned 404; the M03 filters were unvalidated.
 */
class UserRoleAndApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_lists_users_with_pagination_meta_and_without_secrets(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->tester()->count(2)->create();

        $response = $this->actingAs($admin)->getJson('/api/v1/admin/users?per_page=2');

        $response->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'email', 'role', 'is_active', 'created_at', 'updated_at']]]);
        $this->assertArrayNotHasKey('password', $response->json('data.0'));
    }

    public function test_api_creates_an_active_user(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson('/api/v1/admin/users', [
            'name' => 'Tester Mới',
            'email' => 'new@ait.local',
            'password' => 'secret-password',
            'role' => 'TESTER',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.email', 'new@ait.local')
            ->assertJsonPath('data.role', 'TESTER')
            ->assertJsonPath('data.is_active', true);
    }

    public function test_api_admin_changes_role_name_and_status(): void
    {
        $admin = User::factory()->admin()->create();
        $tester = User::factory()->tester()->create();

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/users/{$tester->id}", ['role' => 'ADMIN', 'name' => 'Đổi tên'])
            ->assertOk()
            ->assertJsonPath('data.role', 'ADMIN')
            ->assertJsonPath('data.name', 'Đổi tên');
        $this->assertSame(UserRole::Admin, $tester->refresh()->role);

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/users/{$tester->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_tester_cannot_use_admin_user_api(): void
    {
        $tester = User::factory()->tester()->create();
        $other = User::factory()->tester()->create();

        $this->actingAs($tester)->getJson('/api/v1/admin/users')
            ->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        $this->actingAs($tester)->postJson('/api/v1/admin/users', [])
            ->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        $this->actingAs($tester)->patchJson("/api/v1/admin/users/{$tester->id}", ['role' => 'ADMIN'])
            ->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        $this->actingAs($tester)->patchJson("/api/v1/admin/users/{$other->id}", ['role' => 'ADMIN'])
            ->assertForbidden();

        $this->assertSame(UserRole::Tester, $tester->refresh()->role);
    }

    public function test_admin_cannot_change_own_role_or_lock_themselves(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->admin()->create();

        $this->actingAs($admin)->patchJson("/api/v1/admin/users/{$admin->id}", ['role' => 'TESTER'])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->actingAs($admin)->patchJson("/api/v1/admin/users/{$admin->id}", ['is_active' => false])
            ->assertStatus(422);
        $this->actingAs($admin)->patchJson("/api/v1/admin/users/{$admin->id}", ['name' => 'Tên mới'])
            ->assertOk();

        $admin->refresh();
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertTrue($admin->is_active);
    }

    public function test_last_active_admin_cannot_be_demoted_or_locked(): void
    {
        $actor = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();

        // Two active admins: demoting one is allowed.
        $this->actingAs($actor)->patchJson("/api/v1/admin/users/{$other->id}", ['role' => 'TESTER'])->assertOk();

        // The actor is now the last active admin; an inactive admin does not count. Over HTTP only
        // an active admin can act, so the guard is exercised on the action (concurrent-change case).
        $inactiveAdmin = User::factory()->admin()->inactive()->create();
        foreach ([['role' => 'TESTER'], ['is_active' => false]] as $patch) {
            try {
                app(UpdateUserAction::class)->execute($inactiveAdmin, $actor, $patch);
                $this->fail('The last active ADMIN must not be demoted or locked.');
            } catch (ApiException $e) {
                $this->assertSame(ErrorCode::ValidationFailed, $e->errorCode);
            }
        }
        $actor->refresh();
        $this->assertSame(UserRole::Admin, $actor->role);
        $this->assertTrue($actor->is_active);
    }

    public function test_api_patch_validates_payload(): void
    {
        $admin = User::factory()->admin()->create();
        $tester = User::factory()->tester()->create();

        $this->actingAs($admin)->patchJson("/api/v1/admin/users/{$tester->id}", [])->assertStatus(422);
        $this->actingAs($admin)->patchJson("/api/v1/admin/users/{$tester->id}", ['role' => 'ROOT'])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->actingAs($admin)->patchJson('/api/v1/admin/users/999999', ['role' => 'ADMIN'])->assertNotFound();
    }

    public function test_web_admin_changes_role_from_m03(): void
    {
        $admin = User::factory()->admin()->create();
        $tester = User::factory()->tester()->create();

        $this->actingAs($admin)->get('/admin/users')->assertOk()->assertSee('update-role-form-'.$tester->id, false);

        $this->actingAs($admin)
            ->patch("/admin/users/{$tester->id}", ['role' => 'ADMIN'])
            ->assertRedirect('/admin/users')
            ->assertSessionHas('success');
        $this->assertSame(UserRole::Admin, $tester->refresh()->role);

        $this->actingAs($admin)
            ->patch("/admin/users/{$admin->id}", ['role' => 'TESTER'])
            ->assertRedirect('/admin/users')
            ->assertSessionHas('error');
        $this->assertSame(UserRole::Admin, $admin->refresh()->role);
    }

    public function test_tester_cannot_change_roles_through_the_web(): void
    {
        $tester = User::factory()->tester()->create();

        $this->actingAs($tester)->patch("/admin/users/{$tester->id}", ['role' => 'ADMIN'])->assertForbidden();
        $this->assertSame(UserRole::Tester, $tester->refresh()->role);
    }

    /**
     * Regression: ?search[]=x reached "%{$search}%" and raised "Array to string conversion" (500).
     */
    public function test_m03_rejects_array_and_unknown_filters_without_server_error(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/users?search[]=x')
            ->assertRedirect()
            ->assertSessionHasErrors('search');
        $this->actingAs($admin)->get('/admin/users?role=ROOT')->assertSessionHasErrors('role');
        $this->actingAs($admin)->get('/admin/users?status=2')->assertSessionHasErrors('status');
        $this->actingAs($admin)->getJson('/api/v1/admin/users?search[]=x')
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }
}
