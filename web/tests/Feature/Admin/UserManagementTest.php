<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_user_management(): void
    {
        $response = $this->get('/admin/users');

        $response->assertRedirect('/login');
    }

    public function test_tester_cannot_access_user_management(): void
    {
        $tester = User::factory()->tester()->create();

        $response = $this->actingAs($tester)->get('/admin/users');

        $response->assertForbidden();
    }

    public function test_admin_can_access_user_management_and_see_users(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Admin Boss', 'email' => 'admin@ait.local']);
        $tester = User::factory()->tester()->create(['name' => 'Tester One', 'email' => 'tester1@ait.local']);

        $response = $this->actingAs($admin)->get('/admin/users');

        $response->assertOk()
            ->assertSee('Quản lý người dùng')
            ->assertSee('Admin Boss')
            ->assertSee('tester1@ait.local')
            ->assertSee('Cấp tài khoản mới');
    }

    public function test_admin_can_filter_users_by_search_query(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['name' => 'Alpha Tester', 'email' => 'alpha@ait.local']);
        User::factory()->create(['name' => 'Beta Developer', 'email' => 'beta@ait.local']);

        $response = $this->actingAs($admin)->get('/admin/users?search=Alpha');

        $response->assertOk()
            ->assertSee('Alpha Tester')
            ->assertDontSee('Beta Developer');
    }

    public function test_admin_can_filter_users_by_role_and_status(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['name' => 'Active Tester', 'role' => UserRole::Tester, 'is_active' => true]);
        User::factory()->create(['name' => 'Inactive Tester', 'role' => UserRole::Tester, 'is_active' => false]);

        $response = $this->actingAs($admin)->get('/admin/users?role=TESTER&status=1');

        $response->assertOk()
            ->assertSee('Active Tester')
            ->assertDontSee('Inactive Tester');
    }

    public function test_admin_can_create_a_new_user(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/admin/users', [
            'name' => 'Nguyen Van Test',
            'email' => 'vantest@ait.local',
            'password' => 'secret-12345',
            'role' => 'TESTER',
            'is_active' => '1',
        ]);

        $response->assertRedirect('/admin/users');
        $response->assertSessionHas('success');

        $user = User::where('email', 'vantest@ait.local')->first();
        $this->assertNotNull($user);
        $this->assertSame('Nguyen Van Test', $user->name);
        $this->assertSame(UserRole::Tester, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('secret-12345', $user->password));
    }

    public function test_create_user_validates_required_fields_and_unique_email(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'existing@ait.local']);

        $response = $this->actingAs($admin)->post('/admin/users', [
            'name' => '',
            'email' => 'existing@ait.local',
            'password' => 'short',
            'role' => 'INVALID_ROLE',
        ]);

        $response->assertSessionHasErrors(['name', 'email', 'password', 'role']);
    }

    public function test_admin_can_create_user_with_inactive_status_when_checkbox_unchecked(): void
    {
        $admin = User::factory()->admin()->create();

        // When checkbox is unchecked in browser, 'is_active' is omitted from POST
        $response = $this->actingAs($admin)->post('/admin/users', [
            'name' => 'Inactive New User',
            'email' => 'inactive.new@ait.local',
            'password' => 'secret-12345',
            'role' => 'TESTER',
        ]);

        $response->assertRedirect('/admin/users');
        $response->assertSessionHas('success');

        $user = User::where('email', 'inactive.new@ait.local')->first();
        $this->assertNotNull($user);
        $this->assertFalse($user->is_active);
    }

    public function test_admin_can_create_user_with_explicit_inactive_flag(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/admin/users', [
            'name' => 'Explicit Inactive User',
            'email' => 'explicit.inactive@ait.local',
            'password' => 'secret-12345',
            'role' => 'TESTER',
            'is_active' => '0',
        ]);

        $response->assertRedirect('/admin/users');
        $response->assertSessionHas('success');

        $user = User::where('email', 'explicit.inactive@ait.local')->first();
        $this->assertNotNull($user);
        $this->assertFalse($user->is_active);
    }

    public function test_admin_can_toggle_user_active_status(): void
    {
        $admin = User::factory()->admin()->create();
        $targetUser = User::factory()->tester()->create(['is_active' => true]);

        // Toggle to inactive (Khóa)
        $response = $this->actingAs($admin)->post("/admin/users/{$targetUser->id}/toggle-status");

        $response->assertRedirect('/admin/users');
        $freshTarget = $targetUser->fresh();
        $this->assertNotNull($freshTarget);
        $this->assertFalse($freshTarget->is_active);

        // Toggle back to active (Mở khóa)
        $response = $this->actingAs($admin)->post("/admin/users/{$targetUser->id}/toggle-status");

        $response->assertRedirect('/admin/users');
        $freshTarget = $targetUser->fresh();
        $this->assertNotNull($freshTarget);
        $this->assertTrue($freshTarget->is_active);
    }

    public function test_admin_cannot_lock_their_own_account(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);

        $response = $this->actingAs($admin)->post("/admin/users/{$admin->id}/toggle-status");

        $response->assertSessionHasErrors('user');
        $freshAdmin = $admin->fresh();
        $this->assertNotNull($freshAdmin);
        $this->assertTrue($freshAdmin->is_active);
    }
}
