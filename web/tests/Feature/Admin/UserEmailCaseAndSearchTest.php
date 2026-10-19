<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * Regressions (QA S13-03): emails differing only by letter case created two accounts, login was
 * case-sensitive, and the M03 search was case-sensitive with % and _ acting as wildcards.
 */
class UserEmailCaseAndSearchTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_13_100001_add_case_insensitive_unique_email_to_users.php';

    public function test_m03_rejects_an_email_that_differs_only_by_letter_case(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = ['name' => 'QA User', 'password' => 'secret-password', 'role' => 'TESTER', 'is_active' => '1'];

        $this->actingAs($admin)
            ->post('/admin/users', [...$payload, 'email' => '  QA-M-User1@ait.local '])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => 'qa-m-user1@ait.local']);

        $this->actingAs($admin)
            ->from('/admin/users')
            ->post('/admin/users', [...$payload, 'email' => 'qa-m-user1@ait.local'])
            ->assertSessionHasErrors('email');

        $this->assertSame(1, User::query()->whereRaw('LOWER(email) = ?', ['qa-m-user1@ait.local'])->count());
    }

    public function test_api_reports_a_case_insensitive_duplicate_as_conflict(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = ['name' => 'QA User', 'password' => 'secret-password', 'role' => 'TESTER'];

        $this->actingAs($admin)->postJson('/api/v1/admin/users', [...$payload, 'email' => 'QA-M-User1@ait.local'])
            ->assertCreated()
            ->assertJsonPath('data.email', 'qa-m-user1@ait.local');

        $this->actingAs($admin)->postJson('/api/v1/admin/users', [...$payload, 'email' => 'qa-m-user1@AIT.local'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CONFLICT');

        $this->assertSame(1, User::query()->whereRaw('LOWER(email) = ?', ['qa-m-user1@ait.local'])->count());
    }

    public function test_login_ignores_the_letter_case_of_the_email(): void
    {
        $user = User::factory()->create(['email' => 'qa-admin@ait.local', 'password' => Hash::make('password123')]);

        $this->post('/login', ['email' => 'QA-Admin@ait.local', 'password' => 'password123'])
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_account_created_with_capitals_can_sign_in_in_lower_case(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->postJson('/api/v1/admin/users', [
            'name' => 'Mixed Case', 'email' => 'Mixed.Case@AIT.local', 'password' => 'secret-password', 'role' => 'TESTER',
        ])->assertCreated();
        auth()->logout();

        $this->post('/login', ['email' => 'mixed.case@ait.local', 'password' => 'secret-password'])
            ->assertRedirect('/dashboard');
        $this->assertSame('mixed.case@ait.local', auth()->user()?->email);
    }

    public function test_database_enforces_unique_lower_email(): void
    {
        User::factory()->create(['email' => 'unique@ait.local']);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('users')->insert([
            'name' => 'Raw', 'email' => 'UNIQUE@ait.local', 'password' => 'x', 'role' => 'TESTER', 'is_active' => true,
        ]);
    }

    public function test_migration_lower_cases_existing_rows_and_refuses_case_duplicates(): void
    {
        $migration = require base_path(self::MIGRATION);
        $migration->down();

        $id = DB::table('users')->insertGetId([
            'name' => 'Legacy', 'email' => 'Legacy.User@AIT.local', 'password' => 'x', 'role' => 'TESTER', 'is_active' => true,
        ]);
        $migration->up();
        $this->assertSame('legacy.user@ait.local', DB::table('users')->where('id', $id)->value('email'));

        $migration->down();
        DB::table('users')->insert([
            'name' => 'Twin', 'email' => 'LEGACY.user@ait.local', 'password' => 'x', 'role' => 'TESTER', 'is_active' => true,
        ]);
        try {
            $migration->up();
            $this->fail('The migration must refuse accounts that differ only by letter case.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('legacy.user@ait.local', $e->getMessage());
        }
    }

    public function test_search_is_case_insensitive_on_api_and_m03(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Root Admin', 'email' => 'root@ait.local']);
        User::factory()->create(['name' => 'QA Tester', 'email' => 'qa-tester@ait.local']);
        User::factory()->create(['name' => 'Beta Other', 'email' => 'beta@ait.local']);

        $this->actingAs($admin)->getJson('/api/v1/admin/users?search=qa%20tester')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'QA Tester');
        $this->actingAs($admin)->getJson('/api/v1/admin/users?search=QA-TESTER@AIT')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->actingAs($admin)->get('/admin/users?search=qa+tester')
            ->assertOk()
            ->assertSee('QA Tester')
            ->assertDontSee('Beta Other');
    }

    public function test_search_treats_percent_underscore_and_backslash_literally(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Root Admin', 'email' => 'root@ait.local']);
        User::factory()->create(['name' => '100% Tester', 'email' => 'percent@ait.local']);
        User::factory()->create(['name' => 'Under_score', 'email' => 'under@ait.local']);

        $this->actingAs($admin)->getJson('/api/v1/admin/users?search=%25')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', '100% Tester');
        $this->actingAs($admin)->getJson('/api/v1/admin/users?search=_')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Under_score');
        $this->actingAs($admin)->getJson('/api/v1/admin/users?search=%5C')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }
}
