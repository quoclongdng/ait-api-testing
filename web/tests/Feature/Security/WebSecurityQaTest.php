<?php

namespace Tests\Feature\Security;

use App\Actions\User\ProvisionAdminAction;
use App\Models\AllowedTarget;
use App\Models\User;
use App\Support\TargetNetworkPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Regression tests for the S13-03 web-security QA findings before the public deploy: one password
 * policy for every account, legacy IPv4 notations and internal host names in the allowlist, 403
 * before route-model binding on admin routes, Retry-After on API 429 and no CORS on the internal API.
 */
class WebSecurityQaTest extends TestCase
{
    use RefreshDatabase;

    // --- Password policy: M03/API accounts follow ait:admin:create (12 characters) ------------------

    public function test_api_refuses_an_admin_or_tester_password_shorter_than_twelve_characters(): void
    {
        $admin = User::factory()->admin()->create();
        $this->assertSame(12, ProvisionAdminAction::MIN_PASSWORD_LENGTH);

        foreach (['ADMIN', 'TESTER'] as $role) {
            $this->actingAs($admin)->postJson('/api/v1/admin/users', [
                'name' => 'Ngắn',
                'email' => strtolower($role).'.short@ait.local',
                'password' => '12345678901',
                'role' => $role,
            ])
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'VALIDATION_FAILED')
                ->assertJsonPath('error.details.password.0', 'Mật khẩu phải dài ít nhất 12 ký tự.');
        }
        $this->assertSame(1, User::query()->count());

        $this->actingAs($admin)->postJson('/api/v1/admin/users', [
            'name' => 'Đủ dài',
            'email' => 'admin2@ait.local',
            'password' => '123456789012',
            'role' => 'ADMIN',
        ])->assertCreated();
    }

    public function test_m03_form_refuses_a_short_password_and_states_the_minimum(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->from('/admin/users')->post('/admin/users', [
            'name' => 'Ngắn',
            'email' => 'short@ait.local',
            'password' => '12345678',
            'role' => 'ADMIN',
            'is_active' => '1',
        ])->assertRedirect('/admin/users')->assertSessionHasErrors('password');
        $this->assertDatabaseMissing('users', ['email' => 'short@ait.local']);

        $this->actingAs($admin)->get('/admin/users')
            ->assertOk()
            ->assertSee('minlength="12"', false)
            ->assertSee('Tối thiểu 12 ký tự')
            ->assertDontSee('Tối thiểu 8 ký tự');
    }

    // --- Allowlist: legacy IPv4 notations and internal host names -----------------------------------

    public function test_legacy_ipv4_notations_and_internal_names_are_refused_when_private_networks_are_blocked(): void
    {
        config(['ait.target_client.block_private_networks' => true]);
        $admin = User::factory()->admin()->create();

        $hosts = [
            '2130706433', '0x7f000001', '017700000001', '127.1', '0177.0.0.1', '0x7f.1', '10.1', '0',
            'host.docker.internal', 'gateway.docker.internal', 'metadata.google.internal',
            'localhost.localdomain', 'HOST.DOCKER.INTERNAL.',
        ];
        foreach ($hosts as $host) {
            $this->actingAs($admin)->postJson('/api/v1/admin/allowed-targets', ['host' => $host])
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'TARGET_NOT_ALLOWED');
        }
        $this->assertSame(0, AllowedTarget::query()->count());
    }

    public function test_metadata_address_in_legacy_notation_is_always_refused(): void
    {
        config(['ait.target_client.block_private_networks' => false]);
        $admin = User::factory()->admin()->create();

        // 169.254.169.254
        foreach (['2852039166', '0xa9fea9fe', '169.254.43518'] as $host) {
            $this->actingAs($admin)->postJson('/api/v1/admin/allowed-targets', ['host' => $host])
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'TARGET_NOT_ALLOWED');
        }
    }

    public function test_non_canonical_numeric_hosts_must_be_written_as_dotted_quads(): void
    {
        config(['ait.target_client.block_private_networks' => false]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/v1/admin/allowed-targets', ['host' => '134744072'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.details.host.0', fn (string $m): bool => str_contains($m, 'địa chỉ IPv4 8.8.8.8'));
        foreach (['010.0.0.1', 'api.example.123', '1.2.3.4.5', '4294967296'] as $host) {
            $this->actingAs($admin)->postJson('/api/v1/admin/allowed-targets', ['host' => $host])
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
        $this->assertSame(0, AllowedTarget::query()->count());

        // Canonical addresses and ordinary hostnames (digits allowed outside the last label) still work.
        foreach (['8.8.8.8', '10.0.0.5', 'api1.example.test', '123.example.test', '2001:db8::1'] as $host) {
            $this->actingAs($admin)->postJson('/api/v1/admin/allowed-targets', ['host' => $host])->assertCreated();
        }
    }

    public function test_internal_names_are_allowed_in_local_development(): void
    {
        config(['ait.target_client.block_private_networks' => false]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/v1/admin/allowed-targets', ['host' => 'host.docker.internal'])->assertCreated();
    }

    public function test_legacy_ipv4_parser_follows_inet_aton(): void
    {
        $cases = [
            ['2130706433', '127.0.0.1'],
            ['0x7f000001', '127.0.0.1'],
            ['017700000001', '127.0.0.1'],
            ['127.1', '127.0.0.1'],
            ['127.0.1', '127.0.0.1'],
            ['0x7f.0.0.1', '127.0.0.1'],
            ['010.0.0.1', '8.0.0.1'],
            ['0x', '0.0.0.0'],
            ['8.8.8.8', '8.8.8.8'],
            ['4294967295', '255.255.255.255'],
        ];
        foreach ($cases as [$host, $expected]) {
            $this->assertSame($expected, TargetNetworkPolicy::parseLegacyIpv4($host), $host);
        }
        foreach (['4294967296', '256.1.1.1', '1.2.3.4.5', '1.2.65536', '08.1.1.1', 'example.com', '', '0x1g', '99999999999999999999'] as $host) {
            $this->assertNull(TargetNetworkPolicy::parseLegacyIpv4($host), $host);
        }

        $this->assertTrue(TargetNetworkPolicy::endsInNumber('example.123'));
        $this->assertTrue(TargetNetworkPolicy::endsInNumber('0x7f000001'));
        $this->assertFalse(TargetNetworkPolicy::endsInNumber('123.example'));
        $this->assertFalse(TargetNetworkPolicy::endsInNumber('benchmark'));
    }

    // --- Admin routes: 403 before route-model binding (no id enumeration) --------------------------

    public function test_tester_gets_403_on_admin_routes_whether_or_not_the_id_exists(): void
    {
        $tester = User::factory()->tester()->create();
        $other = User::factory()->tester()->create();
        $target = AllowedTarget::factory()->create();

        foreach ([$other->id, 999999] as $userId) {
            $this->actingAs($tester)->patchJson("/api/v1/admin/users/{$userId}", ['name' => 'x'])
                ->assertForbidden()
                ->assertJsonPath('error.code', 'FORBIDDEN');
            $this->actingAs($tester)->patch("/admin/users/{$userId}", ['name' => 'x'])->assertForbidden();
            $this->actingAs($tester)->patch("/admin/users/{$userId}/role", ['role' => 'ADMIN'])->assertForbidden();
            $this->actingAs($tester)->post("/admin/users/{$userId}/toggle-status")->assertForbidden();
        }
        foreach ([$target->id, 999999] as $targetId) {
            $this->actingAs($tester)->deleteJson("/api/v1/admin/allowed-targets/{$targetId}")
                ->assertForbidden()
                ->assertJsonPath('error.code', 'FORBIDDEN');
            $this->actingAs($tester)->delete("/admin/allowed-targets/{$targetId}")->assertForbidden();
        }
        foreach (['/api/v1/admin/users', '/api/v1/admin/allowed-targets', '/api/v1/admin/benchmarks'] as $uri) {
            $this->actingAs($tester)->getJson($uri)->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        }

        $this->assertSame($other->name, $other->fresh()?->name);
        $this->assertNotNull($target->fresh());
    }

    public function test_admin_still_gets_404_for_a_missing_id_and_guests_401(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->patchJson('/api/v1/admin/users/999999', ['name' => 'x'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
        $this->actingAs($admin)->deleteJson('/api/v1/admin/allowed-targets/999999')->assertNotFound();
        $this->actingAs($admin)->delete('/admin/allowed-targets/999999')->assertNotFound();
    }

    public function test_guest_on_admin_api_route_gets_401(): void
    {
        $this->patchJson('/api/v1/admin/users/999999', ['name' => 'x'])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_locked_tester_is_logged_out_before_the_role_check(): void
    {
        $locked = User::factory()->tester()->create(['is_active' => false]);

        $this->actingAs($locked)->patchJson('/api/v1/admin/users/999999', ['name' => 'x'])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->actingAs($locked)->delete('/admin/allowed-targets/999999')->assertRedirect('/login');
    }

    // --- 429 / 405 keep their protocol headers in the §6 envelope ------------------------------------

    public function test_api_rate_limit_response_carries_retry_after_and_rate_limit_headers(): void
    {
        Route::middleware('throttle:1,1')->get('/api/__test/throttled', fn () => response()->json(['data' => 'ok']));

        $this->getJson('/api/__test/throttled')->assertOk();
        $response = $this->getJson('/api/__test/throttled')
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED');

        $this->assertNotNull($response->headers->get('Retry-After'));
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertSame('1', $response->headers->get('X-RateLimit-Limit'));
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
        $this->assertNotNull($response->headers->get('X-RateLimit-Reset'));
    }

    public function test_method_not_allowed_response_carries_allow_header(): void
    {
        $response = $this->putJson('/api/v1/health')
            ->assertStatus(405)
            ->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');

        $this->assertStringContainsString('GET', (string) $response->headers->get('Allow'));
    }

    // --- CORS: the internal API is same-origin only ----------------------------------------------------

    public function test_api_does_not_advertise_cross_origin_access(): void
    {
        $tester = User::factory()->tester()->create();
        $origin = ['Origin' => 'https://evil.example'];

        $preflight = $this->withHeaders($origin + [
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type,x-xsrf-token',
        ])->options('/api/v1/projects');
        $this->assertFalse($preflight->headers->has('Access-Control-Allow-Origin'));
        $this->assertFalse($preflight->headers->has('Access-Control-Allow-Methods'));
        $this->assertFalse($preflight->headers->has('Access-Control-Allow-Headers'));

        $health = $this->withHeaders($origin)->getJson('/api/v1/health');
        $this->assertFalse($health->headers->has('Access-Control-Allow-Origin'));

        $projects = $this->actingAs($tester)->withHeaders($origin)->getJson('/api/v1/projects')->assertOk();
        $this->assertFalse($projects->headers->has('Access-Control-Allow-Origin'));
    }
}
