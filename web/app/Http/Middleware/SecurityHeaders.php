<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Global middleware adding browser security headers (public deployment, S14-02).
 *
 * Every response: X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy,
 * Cross-Origin-Opener-Policy, Cross-Origin-Resource-Policy and, over HTTPS, Strict-Transport-Security.
 * Static files of public/ get them too: in production they are served through Laravel
 * (docker/web/php/router.php, App\Http\Controllers\PublicAssetController).
 *
 * HTML responses also get a Content-Security-Policy, unless the response already carries its own
 * (the self-contained run export keeps `default-src 'none'`):
 * - scripts: same-origin Vite bundle + inline `<script nonce>` blocks (nonce shared with @vite);
 *   `'unsafe-eval'` because the standard Alpine.js build evaluates x-data/x-on expressions with
 *   `new Function` (switching to the @alpinejs/csp build would allow dropping it);
 * - styles: `'unsafe-inline'` for the `style` attributes and Alpine `:style` bindings, plus the
 *   Bunny Fonts stylesheet; no inline event handlers (confirmations use `data-confirm`);
 * - when the Vite dev server is running (`public/hot`), its origin is allowed so `make up` keeps HMR.
 *
 * Responses rendered for a signed-in user (dashboard pages, /api/v1 JSON) get
 * `Cache-Control: no-store, private` (S14-06): with Laravel's default `no-cache, private` the browser
 * kept them in its back/forward cache, so Back after logout showed the previous user's pages. Guest
 * pages (`/`, /login) and public assets keep their own caching; file downloads are left untouched.
 * `no-transform` keeps intermediaries from rewriting them: Cloudflare Web Analytics otherwise injects
 * its beacon script into every signed-in page and into the downloaded HTML run report (S14-06).
 * This one also applies when SECURITY_HEADERS_ENABLED=false.
 */
final class SecurityHeaders
{
    private const FONT_ORIGIN = 'https://fonts.bunny.net';

    public function __construct(private readonly Vite $vite) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('ait.security.headers_enabled', true)) {
            /** @var Response $response */
            $response = $next($request);

            // Not a browser-hardening header: a signed-in user's pages never stay in the browser cache,
            // whatever SECURITY_HEADERS_ENABLED says (it only turns off CSP and friends).
            return $this->noStoreForSignedInUser($response);
        }

        // Before the view renders, so @vite tags and inline scripts carry the same nonce.
        $nonce = $this->vite->useCspNonce();

        /** @var Response $response */
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        $hstsMaxAge = (int) config('ait.security.hsts_max_age', 0);
        if ($request->isSecure() && $hstsMaxAge > 0) {
            $headers->set('Strict-Transport-Security', "max-age={$hstsMaxAge}; includeSubDomains");
        }

        $this->noStoreForSignedInUser($response);

        $isHtml = str_contains(strtolower((string) $headers->get('Content-Type', 'text/html')), 'text/html');
        if ($isHtml && ! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', $this->contentSecurityPolicy($nonce, $request->isSecure()));
        }

        return $response;
    }

    private function noStoreForSignedInUser(Response $response): Response
    {
        if ($this->isAuthenticated() && ! $response instanceof BinaryFileResponse && ! $response instanceof StreamedResponse) {
            $response->headers->set('Cache-Control', 'no-store, no-transform, private');
            $response->headers->set('Pragma', 'no-cache');
        }

        return $response;
    }

    /**
     * Only a user the route already resolved (web group: EnsureUserIsActive, auth middleware): asking
     * the guard here would read the session or remember-me cookie, and query the database, on routes
     * that never needed a user (public assets, `/`, `/up`).
     */
    private function isAuthenticated(): bool
    {
        return Auth::guard()->hasUser();
    }

    private function contentSecurityPolicy(string $nonce, bool $secure): string
    {
        $dev = $this->viteDevOrigins();
        $join = static fn (string ...$sources): string => implode(' ', array_filter($sources, static fn (string $s): bool => $s !== ''));

        $directives = [
            "default-src 'self'",
            'script-src '.$join("'self'", "'nonce-{$nonce}'", "'unsafe-eval'", $dev['http']),
            'style-src '.$join("'self'", "'unsafe-inline'", self::FONT_ORIGIN, $dev['http']),
            'font-src '.$join("'self'", self::FONT_ORIGIN, 'data:', $dev['http']),
            "img-src 'self' data:",
            'connect-src '.$join("'self'", $dev['http'], $dev['ws']),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ];
        if ($secure) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives);
    }

    /**
     * @return array{http: string, ws: string}
     */
    private function viteDevOrigins(): array
    {
        if (! $this->vite->isRunningHot()) {
            return ['http' => '', 'ws' => ''];
        }
        $url = trim((string) @file_get_contents($this->vite->hotFile()));
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return ['http' => '', 'ws' => ''];
        }
        $host = str_contains($parts['host'], ':') && ! str_starts_with($parts['host'], '[') ? "[{$parts['host']}]" : $parts['host'];
        $authority = $host.(isset($parts['port']) ? ':'.$parts['port'] : '');
        $wsScheme = $parts['scheme'] === 'https' ? 'wss' : 'ws';

        return ['http' => "{$parts['scheme']}://{$authority}", 'ws' => "{$wsScheme}://{$authority}"];
    }
}
