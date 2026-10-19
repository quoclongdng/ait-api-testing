<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_visiting_admin_route_is_redirected_to_login(): void
    {
        $response = $this->get('/admin');

        $response->assertRedirect('/login');
    }

    public function test_tester_visiting_admin_route_receives_forbidden(): void
    {
        $tester = User::factory()->tester()->create();

        $response = $this->actingAs($tester)->get('/admin');

        $response->assertForbidden();
    }

    public function test_admin_visiting_admin_route_receives_success(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/admin');

        // S14-04: /admin opens the ADMIN dashboard "Tổng quan quản trị".
        $response->assertRedirect(route('dashboard'));
    }

    public function test_admin_and_tester_gates(): void
    {
        $admin = User::factory()->admin()->make();
        $tester = User::factory()->tester()->make();

        $this->assertTrue(Gate::forUser($admin)->allows('admin'));
        $this->assertTrue(Gate::forUser($admin)->allows('tester'));

        $this->assertFalse(Gate::forUser($tester)->allows('admin'));
        $this->assertTrue(Gate::forUser($tester)->allows('tester'));
    }

    public function test_admin_user_seeder_creates_active_admin_account(): void
    {
        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', 'admin@ait.local')->first();

        $this->assertNotNull($admin);
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertTrue($admin->is_active);
        $this->assertTrue($admin->isAdmin());
    }

    public function test_admin_user_seeder_does_not_overwrite_existing_admin_password_or_status(): void
    {
        // Pre-existing admin with custom password and locked status
        User::factory()->admin()->create([
            'email' => 'admin@ait.local',
            'password' => Hash::make('existing-custom-password'),
            'is_active' => false,
        ]);

        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', 'admin@ait.local')->first();
        $this->assertNotNull($admin);
        $this->assertFalse($admin->is_active);
        $this->assertTrue(Hash::check('existing-custom-password', $admin->password));
    }

    public function test_admin_user_seeder_never_uses_well_known_password_when_unset(): void
    {
        // Regression S10-01: an empty ADMIN_DEFAULT_PASSWORD used to fall back to 'password'.
        config(['ait.admin_default_password' => '']);

        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', 'admin@ait.local')->firstOrFail();
        $this->assertFalse(Hash::check('password', $admin->password));
        $this->assertFalse(Hash::check('', $admin->password));
    }

    public function test_admin_user_seeder_generates_different_passwords_when_unset(): void
    {
        config(['ait.admin_default_password' => '']);

        $this->seed(AdminUserSeeder::class);
        $first = User::where('email', 'admin@ait.local')->firstOrFail()->password;
        User::where('email', 'admin@ait.local')->delete();

        $this->seed(AdminUserSeeder::class);
        $second = User::where('email', 'admin@ait.local')->firstOrFail()->password;

        $this->assertNotSame($first, $second);
    }

    public function test_admin_user_seeder_uses_configured_password(): void
    {
        config(['ait.admin_default_password' => 'configured-local-admin-pass']);

        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', 'admin@ait.local')->firstOrFail();
        $this->assertTrue(Hash::check('configured-local-admin-pass', $admin->password));
    }

    public function test_admin_default_password_has_no_well_known_fallback_and_is_documented(): void
    {
        $config = (string) file_get_contents(config_path('ait.php'));
        $this->assertStringNotContainsString("env('ADMIN_DEFAULT_PASSWORD', 'password')", $config);

        $envExample = (string) file_get_contents(base_path('.env.example'));
        $this->assertMatchesRegularExpression('/^ADMIN_DEFAULT_PASSWORD=$/m', $envExample);
    }

    public function test_user_policy_authorization(): void
    {
        $admin = User::factory()->admin()->create();
        $tester = User::factory()->tester()->create();

        // viewAny: ADMIN only
        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', User::class));
        $this->assertFalse(Gate::forUser($tester)->allows('viewAny', User::class));

        // view: ADMIN can view any, tester can view self
        $this->assertTrue(Gate::forUser($admin)->allows('view', $tester));
        $this->assertTrue(Gate::forUser($tester)->allows('view', $tester));
        $this->assertFalse(Gate::forUser($tester)->allows('view', $admin));

        // create: ADMIN only
        $this->assertTrue(Gate::forUser($admin)->allows('create', User::class));
        $this->assertFalse(Gate::forUser($tester)->allows('create', User::class));

        // update: ADMIN only
        $this->assertTrue(Gate::forUser($admin)->allows('update', $tester));
        $this->assertFalse(Gate::forUser($tester)->allows('update', $tester));

        // delete: ADMIN can delete other users, but cannot delete themselves
        $this->assertTrue(Gate::forUser($admin)->allows('delete', $tester));
        $this->assertFalse(Gate::forUser($admin)->allows('delete', $admin));
        $this->assertFalse(Gate::forUser($tester)->allows('delete', $tester));
    }
}
