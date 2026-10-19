<?php

namespace Tests\Feature;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * S14-06 QA: `GET /up` was the framework's English HTML page, loading Tailwind from cdn.jsdelivr.net
 * (blocked by the application's CSP) and printing the exception message of a failing health check.
 * It is now a plain-text probe without external assets, session or error detail.
 */
class HealthUpTest extends TestCase
{
    public function test_up_answers_plain_text_without_external_assets_or_session(): void
    {
        $response = $this->get('/up')->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertHeader('X-Request-Id')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeaderMissing('Set-Cookie');

        $body = (string) $response->getContent();
        $this->assertSame('OK', $body);
        $this->assertStringNotContainsString('cdn.jsdelivr.net', $body);
        $this->assertStringNotContainsString('<script', $body);
    }

    public function test_a_failing_health_check_returns_500_without_the_exception_message(): void
    {
        config(['app.debug' => false]);
        Event::listen(DiagnosingHealth::class, function (): void {
            throw new RuntimeException('secret-detail');
        });

        $response = $this->get('/up')->assertStatus(500)
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

        $this->assertSame('Lỗi máy chủ', $response->getContent());
        $this->assertStringNotContainsString('secret-detail', (string) $response->getContent());
    }

    public function test_up_stays_available_in_maintenance_mode(): void
    {
        $this->assertSame(0, Artisan::call('down'));

        try {
            $this->get('/up')->assertOk();
            $this->get('/login')->assertStatus(503);
        } finally {
            $this->assertSame(0, Artisan::call('up'));
        }
    }
}
