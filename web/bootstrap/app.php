<?php

use App\Exceptions\ApiException;
use App\Http\ApiErrorRenderer;
use App\Http\Controllers\HealthUpController;
use App\Http\Controllers\PublicAssetController;
use App\Http\Middleware\AssignTraceId;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\StartSessionWhenCookiePresent;
use App\Http\Middleware\TrustCloudflareClientIp;
use App\Http\Middleware\TrustProductionHosts;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        // No framework `health: '/up'`: its page loaded Tailwind from a CDN that our CSP blocks and
        // echoed the failing check's exception message (S14-06). The plain-text route below replaces it.
        then: function (): void {
            // Liveness probe (Docker healthchecks, scripts/deploy): outside the web group, no session.
            Route::get('up', HealthUpController::class)->name('health.up');

            // Static files of public/ in production (docker/web/php/router.php sends every request to
            // Laravel so they get the global middleware: Host check, trace id, security headers).
            // Outside the web group: no session or cookie on an asset.
            Route::get('{path}', PublicAssetController::class)
                ->where('path', 'build/.+|robots\\.txt|favicon\\.ico')
                ->name('public-asset');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // ADR-0007: one trace id per request (error pages, §6 envelope, logs) and browser security
        // headers on every response, error pages included. Trusted proxies come from TRUSTED_PROXIES
        // (config/trustedproxy.php, read by the framework TrustProxies middleware);
        // TrustCloudflareClientIp runs after it and restores the visitor IP sent by Cloudflare.
        // Host header injection (S14-02): forwarded Host/Port/Prefix headers are never trusted (only
        // the client IP and scheme come from the proxy), and in production the Host itself must be the
        // APP_URL host or localhost/127.0.0.1 (TrustProductionHosts).
        // /up keeps answering during `php artisan down`, as the framework health route did.
        $middleware->preventRequestsDuringMaintenance(except: ['up']);

        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);
        $middleware->prepend(TrustProductionHosts::class);
        $middleware->prepend(AssignTraceId::class);
        $middleware->append(TrustCloudflareClientIp::class);
        $middleware->append(SecurityHeaders::class);

        // Spec text is stored and hashed byte-exact, like an uploaded file: trimming it gave the
        // same document another checksum, so a duplicate spec was not detected (S14-06).
        $middleware->trimStrings(except: ['spec_text', 'raw_content']);

        $middleware->web(append: [
            EnsureUserIsActive::class,
        ]);

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'active' => EnsureUserIsActive::class,
        ]);

        // The account and role checks run before route-model binding: a TESTER on an admin route gets
        // 403 whether or not the {user}/{allowedTarget} id exists, so ids cannot be enumerated (S13-03).
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureUserIsActive::class);
        // "/" starts its session through this route middleware (ADR-0008): it must still run before the
        // account check of the web group, like StartSession does.
        $middleware->prependToPriorityList(EnsureUserIsActive::class, StartSessionWhenCookiePresent::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureUserHasRole::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Expected 4xx ApiExceptions (TARGET_NOT_ALLOWED, FORBIDDEN, DSL_INVALID...) are client
        // errors answered with the §6 envelope: logging them at ERROR with a stack trace buried real
        // failures. 5xx codes (AI_SERVICE_UNAVAILABLE, LLM_PROVIDER_ERROR, INTERNAL_ERROR) are kept.
        $exceptions->dontReportWhen(
            fn (Throwable $e): bool => $e instanceof ApiException && $e->errorCode->httpStatus() < 500,
        );
        $exceptions->render(new ApiErrorRenderer);
    })->create();
