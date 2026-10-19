<?php

namespace Tests\Feature\Admin;

use App\Models\AllowedTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AllowedTargetManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_open_m04(): void
    {
        $tester = User::factory()->tester()->create();
        $admin = User::factory()->admin()->create();

        $this->get('/admin/allowed-targets')->assertRedirect('/login');
        $this->actingAs($tester)->get('/admin/allowed-targets')->assertForbidden();
        $this->actingAs($admin)
            ->get('/admin/allowed-targets')
            ->assertOk()
            ->assertSee('Cấu hình &amp; allowlist', false)
            ->assertSee('DANH SÁCH TRẮNG TARGET');
    }

    public function test_admin_can_create_and_delete_allowed_target_through_m04(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/admin/allowed-targets', [
                '_form' => 'create-allowed-target',
                'host' => 'staging.internal',
                'port' => 8443,
                'note' => 'Staging',
            ])
            ->assertRedirect('/admin/allowed-targets')
            ->assertSessionHas('success');

        $target = AllowedTarget::query()->firstOrFail();
        $this->assertSame($admin->id, $target->created_by);

        $this->actingAs($admin)
            ->delete("/admin/allowed-targets/{$target->id}")
            ->assertRedirect('/admin/allowed-targets')
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('allowed_targets', ['id' => $target->id]);
    }

    public function test_duplicate_allowed_target_is_shown_as_form_error(): void
    {
        $admin = User::factory()->admin()->create();
        AllowedTarget::factory()->for($admin, 'creator')->create([
            'host' => 'duplicate.local',
            'port' => 8080,
        ]);

        $this->actingAs($admin)
            ->from('/admin/allowed-targets')
            ->post('/admin/allowed-targets', [
                '_form' => 'create-allowed-target',
                'host' => 'DUPLICATE.LOCAL.',
                'port' => 8080,
            ])
            ->assertRedirect('/admin/allowed-targets')
            ->assertSessionHasErrors('host');
    }

    public function test_admin_can_view_llm_connection_parameters(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/admin/allowed-targets')
            ->assertOk()
            ->assertSee('LLM hệ thống (chỉ đọc)')
            // FakeAiGeneratorClient reports provider "fake" (AI_SERVICE_DRIVER=fake in tests).
            ->assertSee('fake')
            ->assertDontSee('qwen2.5-coder:7b');
    }
}
