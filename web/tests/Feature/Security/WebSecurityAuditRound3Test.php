<?php

namespace Tests\Feature\Security;

use App\Models\Benchmark;
use App\Models\BenchmarkBug;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Regression tests for the S10-02 web-security audit (round 3): security-review §5 proposals 2-4.
 */
class WebSecurityAuditRound3Test extends TestCase
{
    use RefreshDatabase;

    // --- §9: session cookie HttpOnly + SameSite=Lax ----------------------------------

    public function test_session_cookie_config_is_http_only_and_same_site_lax(): void
    {
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
    }

    public function test_login_page_sets_session_cookie_with_http_only_and_same_site_lax(): void
    {
        $response = $this->get('/login')->assertOk();

        $cookieName = config('session.cookie');
        $this->assertIsString($cookieName);
        $cookie = $response->getCookie($cookieName, false);

        $this->assertNotNull($cookie, 'The session cookie must be set on /login.');
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());

        $header = collect($response->headers->all('set-cookie'))
            ->first(fn (?string $line): bool => is_string($line) && str_starts_with($line, $cookieName.'='));
        $this->assertIsString($header);
        $this->assertStringContainsStringIgnoringCase('httponly', $header);
        $this->assertStringContainsStringIgnoringCase('samesite=lax', $header);
    }

    // --- §9: rate limit on POST /api/v1/experiments -------------------------------------

    public function test_eleventh_experiment_within_a_minute_is_rate_limited_with_envelope(): void
    {
        Queue::fake();
        $tester = User::factory()->tester()->create();
        $benchmark = Benchmark::factory()->create(['slug' => 'simple-store']);
        BenchmarkBug::factory()->count(2)->create(['benchmark_id' => $benchmark->id]);
        $payload = [
            'name' => 'Giới hạn tần suất',
            'benchmark_id' => $benchmark->id,
            'strategy' => 'RULE',
            'config' => ['model' => 'qwen2.5-coder:7b', 'seed' => 42, 'temperature' => 0.2, 'repetitions' => 3],
        ];

        for ($i = 1; $i <= 10; $i++) {
            $this->actingAs($tester)->postJson('/api/v1/experiments', $payload)->assertStatus(202);
        }

        $this->actingAs($tester)
            ->withHeader('X-Request-Id', 'trace-experiments-429')
            ->postJson('/api/v1/experiments', $payload)
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertJsonPath('trace_id', 'trace-experiments-429')
            ->assertJsonStructure(['error' => ['code', 'message'], 'trace_id']);
    }

    // --- §9: passwords hashed with bcrypt -----------------------------------------------

    public function test_admin_screen_created_user_password_is_bcrypt(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/users', [
            'name' => 'Bcrypt Web',
            'email' => 'bcrypt.web@ait.local',
            'password' => 'secret-12345',
            'role' => 'TESTER',
            'is_active' => '1',
        ])->assertRedirect('/admin/users');

        $this->assertBcrypt(User::where('email', 'bcrypt.web@ait.local')->firstOrFail()->password);
    }

    public function test_api_created_user_password_is_bcrypt(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/v1/admin/users', [
            'name' => 'Bcrypt Api',
            'email' => 'bcrypt.api@ait.local',
            'password' => 'secret-12345',
            'role' => 'TESTER',
        ])->assertCreated();

        $this->assertBcrypt(User::where('email', 'bcrypt.api@ait.local')->firstOrFail()->password);
    }

    public function test_admin_seeder_password_is_bcrypt(): void
    {
        config(['ait.admin_default_password' => 'configured-local-admin-pass']);
        $this->seed(AdminUserSeeder::class);

        $this->assertBcrypt(User::where('email', 'admin@ait.local')->firstOrFail()->password);
    }

    public function test_password_cast_hashes_plain_text_with_bcrypt(): void
    {
        $user = User::factory()->create(['password' => 'plain-text-password']);

        $this->assertBcrypt($user->password);
    }

    private function assertBcrypt(string $hash): void
    {
        $this->assertSame('bcrypt', Hash::info($hash)['algoName']);
        $this->assertStringStartsWith('$2y$', $hash);
    }
}
