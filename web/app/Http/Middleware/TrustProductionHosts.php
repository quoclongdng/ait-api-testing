<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Host header allowlist in production (S14-02, ADR-0007).
 *
 * Absolute URLs (redirect Location headers, links) are built from the request host. Only the APP_URL
 * host is accepted, plus localhost/127.0.0.1 for the health checks that reach the container directly
 * (Docker healthcheck, server-deploy.sh). Any other Host is answered with a plain 400 before the
 * application runs, so a foreign host never ends up in a generated URL. X-Forwarded-Host is not
 * trusted at all (bootstrap/app.php, trustProxies headers). Like Laravel's TrustHosts, nothing is
 * enforced outside production (local development, tests).
 */
final class TrustProductionHosts
{
    public function __construct(private readonly Application $app) {}

    /**
     * Patterns for Request::setTrustedHosts() (matched case-insensitively, port excluded).
     *
     * @return list<string>
     */
    public function hosts(): array
    {
        $hosts = ['^localhost$', '^127\.0\.0\.1$'];
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($appHost) && $appHost !== '') {
            array_unshift($hosts, '^'.preg_quote(strtolower($appHost)).'$');
        }

        return $hosts;
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->app->environment('production')) {
            return $next($request);
        }

        Request::setTrustedHosts($this->hosts());
        try {
            $request->getHost();
        } catch (SuspiciousOperationException) {
            // No view rendering here: error pages would build URLs from the rejected host.
            return new Response('Bad request: untrusted host.', Response::HTTP_BAD_REQUEST, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return $next($request);
    }
}
