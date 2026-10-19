<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * S14-06 QA: JSON requests to web (non-/api) routes got Laravel's default English JSON without the §6
 * envelope or a trace_id: an unknown route echoed its path, a CSRF failure said "CSRF token mismatch.",
 * a validation summary ended in "(and 1 more error)" and logout answered "Logged out successfully.".
 */
class WebJsonErrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_web_route_answers_the_envelope_without_echoing_the_path(): void
    {
        $response = $this->getJson('/khong-ton-tai-qa', ['X-Request-Id' => 'qa-trace-404'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND')
            ->assertJsonPath('error.message', 'Không tìm thấy tài nguyên.')
            ->assertJsonPath('trace_id', 'qa-trace-404');

        $this->assertStringNotContainsString('khong-ton-tai-qa', (string) $response->getContent());
        $this->assertStringNotContainsString('could not be found', (string) $response->getContent());
    }

    public function test_csrf_failure_on_a_web_route_is_a_vietnamese_envelope(): void
    {
        // CSRF verification is skipped while running tests; raise what ValidateCsrfToken throws.
        Route::middleware('web')->post('/__qa/csrf', function (): never {
            throw new TokenMismatchException('CSRF token mismatch.');
        });

        $response = $this->postJson('/__qa/csrf', [], ['X-Request-Id' => 'qa-trace-419'])
            // Same mapping as /api: a 419 becomes UNAUTHENTICATED with its §6 status (401).
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonPath('error.message', 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang và đăng nhập lại.')
            ->assertJsonPath('trace_id', 'qa-trace-419');

        $this->assertStringNotContainsString('CSRF token mismatch', (string) $response->getContent());
    }

    public function test_server_error_on_a_web_route_hides_its_cause(): void
    {
        config(['app.debug' => false]);
        Route::middleware('web')->get('/__qa/boom', function (): never {
            throw new RuntimeException('secret-sql-detail');
        });

        $response = $this->getJson('/__qa/boom')
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'INTERNAL_ERROR')
            ->assertJsonPath('error.message', 'Lỗi máy chủ nội bộ.')
            ->assertJsonStructure(['trace_id']);

        $this->assertStringNotContainsString('secret-sql-detail', (string) $response->getContent());
    }

    public function test_login_validation_keeps_its_shape_with_a_vietnamese_summary(): void
    {
        $response = $this->postJson('/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);

        $message = (string) $response->json('message');
        $this->assertStringContainsString('(và 1 lỗi khác)', $message);
        $this->assertStringNotContainsString('more error', $message);
    }

    public function test_html_requests_still_get_the_error_page(): void
    {
        $this->get('/khong-ton-tai-qa')->assertNotFound()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function test_logout_json_message_is_vietnamese(): void
    {
        $this->actingAs(User::factory()->tester()->create())
            ->postJson('/logout')
            ->assertOk()
            ->assertJsonPath('data.message', 'Đã đăng xuất.');
    }
}
