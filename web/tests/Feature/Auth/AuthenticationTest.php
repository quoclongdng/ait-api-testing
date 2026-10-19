<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertSee('Đăng nhập');
        $response->assertSee('AI Testing Platform');
    }

    public function test_authenticated_user_visiting_login_redirects_to_home(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/login');

        $response->assertRedirect('/dashboard');
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create([
            'email' => 'tester@ait.local',
            'password' => Hash::make('password123'),
            'role' => UserRole::Tester,
            'is_active' => true,
        ]);

        $response = $this->post('/login', [
            'email' => 'tester@ait.local',
            'password' => 'password123',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect('/dashboard');
    }

    public function test_users_can_authenticate_via_json_request(): void
    {
        $user = User::factory()->create([
            'email' => 'api-tester@ait.local',
            'password' => Hash::make('secret123'),
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        $response = $this->postJson('/login', [
            'email' => 'api-tester@ait.local',
            'password' => 'secret123',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', 'api-tester@ait.local')
            ->assertJsonPath('data.role', 'ADMIN');
    }

    public function test_users_cannot_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create([
            'email' => 'tester@ait.local',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->post('/login', [
            'email' => 'tester@ait.local',
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
    }

    public function test_inactive_users_cannot_authenticate(): void
    {
        $user = User::factory()->inactive()->create([
            'email' => 'inactive@ait.local',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->post('/login', [
            'email' => 'inactive@ait.local',
            'password' => 'password123',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
    }

    public function test_login_attempts_are_throttled_after_five_failed_attempts(): void
    {
        $user = User::factory()->create([
            'email' => 'target@ait.local',
            'password' => Hash::make('correct-password'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', [
                'email' => 'target@ait.local',
                'password' => 'wrong-password',
            ]);
        }

        // 6th attempt should be locked out
        $response = $this->post('/login', [
            'email' => 'target@ait.local',
            'password' => 'correct-password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/login');
    }

    public function test_users_can_authenticate_with_remember_checkbox_value_on(): void
    {
        $user = User::factory()->create([
            'email' => 'remember-on@ait.local',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->post('/login', [
            'email' => 'remember-on@ait.local',
            'password' => 'secret123',
            'remember' => 'on',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect('/dashboard');
    }

    public function test_users_can_authenticate_with_remember_checkbox_value_one(): void
    {
        $user = User::factory()->create([
            'email' => 'remember-one@ait.local',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->post('/login', [
            'email' => 'remember-one@ait.local',
            'password' => 'secret123',
            'remember' => '1',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect('/dashboard');
    }

    public function test_deactivated_user_with_active_session_is_logged_out_on_next_request(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        // User is logged in and active
        $response = $this->actingAs($user)->get('/dashboard');
        $response->assertOk();

        // Admin deactivates the user
        $user->is_active = false;
        $user->save();

        // On the next request, user is immediately logged out and redirected
        $nextResponse = $this->actingAs($user)->get('/dashboard');
        $nextResponse->assertRedirect('/login');
        $nextResponse->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
