<?php

namespace Tests\Feature\Api;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ErrorEnvelopeTest extends TestCase
{
    public function test_unknown_api_route_returns_not_found_envelope(): void
    {
        app()->setLocale('en');

        $this->getJson('/api/v1/does-not-exist', ['X-Request-Id' => 'req-123'])
            ->assertNotFound()
            ->assertExactJson([
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Resource not found.', 'details' => []],
                'trace_id' => 'req-123',
            ]);
    }

    public function test_framework_error_messages_are_vietnamese_under_vi_locale(): void
    {
        app()->setLocale('vi');

        $this->getJson('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND')
            ->assertJsonPath('error.message', 'Không tìm thấy tài nguyên.');

        $this->postJson('/api/v1/projects', [])
            ->assertUnauthorized()
            ->assertJsonPath('error.message', 'Bạn chưa đăng nhập.');
    }

    public function test_api_exception_is_rendered_with_its_code_and_status(): void
    {
        Route::get('/api/_test/target', function () {
            throw new ApiException(ErrorCode::TargetNotAllowed, 'Host is not allowed.', ['host' => 'example.com']);
        });

        $this->getJson('/api/_test/target')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TARGET_NOT_ALLOWED')
            ->assertJsonPath('error.details.host', 'example.com')
            ->assertJsonStructure(['error' => ['code', 'message', 'details'], 'trace_id']);
    }

    public function test_validation_errors_use_validation_failed_code(): void
    {
        Route::post('/api/_test/validate', fn () => request()->validate(['name' => 'required']));

        $this->postJson('/api/_test/validate', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['name']]]);
    }

    public function test_csrf_token_mismatch_returns_unauthenticated_instead_of_internal_error(): void
    {
        app()->setLocale('en');
        Route::post('/api/_test/csrf', function () {
            throw new TokenMismatchException('CSRF token mismatch.');
        });

        $this->postJson('/api/_test/csrf')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonPath('error.message', 'Session expired. Please reload the page and sign in again.');
    }

    public function test_post_too_large_returns_validation_failed_instead_of_internal_error(): void
    {
        Route::post('/api/_test/too-large', function () {
            throw new PostTooLargeException;
        });

        $this->postJson('/api/_test/too-large')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_generic_http_exceptions_map_to_the_closest_error_code(): void
    {
        Route::get('/api/_test/http/{status}', fn (int $status) => abort($status));

        $expected = [
            400 => ['VALIDATION_FAILED', 422],
            401 => ['UNAUTHENTICATED', 401],
            409 => ['CONFLICT', 409],
            410 => ['NOT_FOUND', 404],
            503 => ['INTERNAL_ERROR', 500],
        ];

        foreach ($expected as $status => [$code, $http]) {
            $this->getJson('/api/_test/http/'.$status)
                ->assertStatus($http)
                ->assertJsonPath('error.code', $code);
        }
    }

    /**
     * Regression (S10-01 r2): every expected 4xx ApiException was written to laravel.log at ERROR
     * level with a full stack trace.
     */
    public function test_client_api_exceptions_are_not_reported_but_server_ones_are(): void
    {
        $handler = app(ExceptionHandler::class);
        $this->assertInstanceOf(Handler::class, $handler);

        foreach ([ErrorCode::TargetNotAllowed, ErrorCode::Forbidden, ErrorCode::DslInvalid, ErrorCode::InvalidStateTransition] as $code) {
            $this->assertFalse($handler->shouldReport(new ApiException($code, 'client error')), $code->value);
        }
        foreach ([ErrorCode::AiServiceUnavailable, ErrorCode::LlmProviderError, ErrorCode::InternalError] as $code) {
            $this->assertTrue($handler->shouldReport(new ApiException($code, 'server error')), $code->value);
        }
    }

    /**
     * Regression (QA S13-03): the envelope dropped the headers of the exception, so a 429 had no
     * Retry-After (contract components.responses.RateLimited) and a 405 had no Allow (RFC 9110).
     */
    public function test_rate_limited_envelope_keeps_retry_after_and_rate_limit_headers(): void
    {
        RateLimiter::for('qa-envelope', fn () => Limit::perMinute(1));
        Route::middleware('throttle:qa-envelope')->get('/api/_test/throttled', fn () => response()->json(['data' => true]));

        $this->getJson('/api/_test/throttled')->assertOk();

        $response = $this->getJson('/api/_test/throttled')
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED');
        $this->assertNotNull($response->headers->get('Retry-After'));
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertSame('1', $response->headers->get('X-RateLimit-Limit'));
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function test_method_not_allowed_envelope_keeps_allow_header(): void
    {
        Route::get('/api/_test/only-get', fn () => response()->json(['data' => true]));

        $response = $this->putJson('/api/_test/only-get')
            ->assertStatus(405)
            ->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');
        $allow = (string) $response->headers->get('Allow');
        $this->assertStringContainsString('GET', $allow);
        $this->assertStringContainsString('HEAD', $allow);
    }
}
