<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/dashboard');

        $response->assertRedirect('/login');
    }

    public function test_authenticated_tester_can_view_dashboard(): void
    {
        $user = User::factory()->tester()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk()
            ->assertSee('Tổng quan của bạn')
            ->assertSee('Dự án')
            ->assertSee('Test case đã duyệt')
            ->assertSee('Tỉ lệ đạt 7 ngày')
            ->assertSee('Dịch vụ nền tảng')
            ->assertSee('Hành động nhanh')
            ->assertSee('Tạo dự án mới');
    }

    public function test_authenticated_admin_can_view_dashboard(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/dashboard');

        // S14-04: an ADMIN gets the system-wide dashboard in the admin shell.
        $response->assertOk()
            ->assertSee('Tổng quan quản trị')
            ->assertDontSee('Tổng quan của bạn')
            ->assertSee('Quản lý người dùng');
    }
}
