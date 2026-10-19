<?php

namespace Tests\Feature\Api;

use App\Models\AllowedTarget;
use App\Models\User;
use App\Rules\HostOrIp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AllowedTargetCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_receives_unauthenticated_envelope(): void
    {
        $this->getJson('/api/v1/admin/allowed-targets')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_tester_cannot_list_create_or_delete_allowed_targets(): void
    {
        $tester = User::factory()->tester()->create();
        $target = AllowedTarget::factory()->create();

        $this->actingAs($tester)
            ->getJson('/api/v1/admin/allowed-targets')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->actingAs($tester)
            ->postJson('/api/v1/admin/allowed-targets', ['host' => 'api.internal'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->actingAs($tester)
            ->deleteJson("/api/v1/admin/allowed-targets/{$target->id}")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_admin_can_create_and_list_normalized_allowed_target(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/allowed-targets', [
                'host' => 'API.Internal.',
                'port' => 8080,
                'note' => 'Sandbox nội bộ',
            ])
            ->assertCreated()
            ->assertJsonPath('data.host', 'api.internal')
            ->assertJsonPath('data.port', 8080)
            ->assertJsonPath('data.created_by', $admin->id);

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/allowed-targets')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.host', 'api.internal');
    }

    public function test_duplicate_host_and_port_returns_conflict_envelope(): void
    {
        $admin = User::factory()->admin()->create();
        AllowedTarget::factory()->for($admin, 'creator')->create([
            'host' => 'benchmark.local',
            'port' => null,
        ]);

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/allowed-targets', [
                'host' => 'BENCHMARK.LOCAL.',
                'port' => null,
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'CONFLICT')
            ->assertJsonPath('error.details.host', 'benchmark.local');
    }

    public function test_host_must_not_include_scheme_port_or_path(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/allowed-targets', [
                'host' => 'https://example.com/api',
                'port' => 70000,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['host', 'port']]]);
    }

    /**
     * S14-06 regression: the message read "Trường host hoặc địa chỉ IP phải là hostname hoặc địa chỉ IP hợp lệ…".
     */
    public function test_url_host_message_does_not_repeat_the_field_label(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->postJson('/api/v1/admin/allowed-targets', ['host' => 'http://qa-admin.example.com/x'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.details.host.0', HostOrIp::MESSAGE_INVALID);

        $this->assertStringNotContainsString('Trường host hoặc địa chỉ IP phải là hostname', (string) $response->json('error.details.host.0'));
        $this->assertSame(0, AllowedTarget::query()->count());
    }

    /**
     * QA S14-06 round 3: a non-string host returned both the "string" rule's message and
     * HostOrIp::MESSAGE_NOT_STRING.
     */
    public function test_non_string_host_returns_a_single_message(): void
    {
        $admin = User::factory()->admin()->create();

        foreach ([['a'], 123, true, ['host' => 'a.example.com']] as $host) {
            $this->actingAs($admin)
                ->postJson('/api/v1/admin/allowed-targets', ['host' => $host])
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'VALIDATION_FAILED')
                ->assertJsonPath('error.details.host', [HostOrIp::MESSAGE_NOT_STRING]);
        }
        $this->assertSame(0, AllowedTarget::query()->count());
    }

    /**
     * QA S14-06 round 3 (info): TARGET_NOT_ALLOWED is a domain error, not a field validation error;
     * its details are the documented context object (normalised host string), as in the contract.
     */
    public function test_target_not_allowed_details_carry_the_normalised_host_as_context(): void
    {
        config(['ait.target_client.block_private_networks' => true]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/allowed-targets', ['host' => ' LocalHost. '])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'TARGET_NOT_ALLOWED')
            ->assertJsonPath('error.details', ['host' => 'localhost'])
            ->assertJsonPath('error.message', fn (string $m): bool => str_contains($m, 'loopback'));
    }

    public function test_admin_can_delete_allowed_target(): void
    {
        $admin = User::factory()->admin()->create();
        $target = AllowedTarget::factory()->for($admin, 'creator')->create();

        $this->actingAs($admin)
            ->deleteJson("/api/v1/admin/allowed-targets/{$target->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('allowed_targets', ['id' => $target->id]);
    }
}
