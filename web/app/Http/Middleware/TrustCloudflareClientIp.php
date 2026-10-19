<?php

namespace App\Http\Middleware;

use App\Support\TrustedProxyList;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Real client IP behind Cloudflare → host nginx → web container (ADR-0007).
 *
 * TrustProxies (TRUSTED_PROXIES, config/trustedproxy.php) resolves request()->ip() from
 * X-Forwarded-For. With TRUSTED_PROXIES=* only the calling hop (the host nginx) is trusted, so the
 * resolved address is the Cloudflare edge that connected to nginx, and rate limits (login, jobs)
 * would bucket every visitor behind one edge together.
 *
 * Cloudflare puts the visitor's address in CF-Connecting-IP. That header is honoured only when:
 * - the request reached the app through a trusted proxy (TrustProxies already validated the hop), and
 * - the address that proxy saw is a published Cloudflare edge range.
 * A client that bypasses Cloudflare and talks to nginx directly therefore cannot spoof its IP with
 * CF-Connecting-IP: the resolved peer is its own (non-Cloudflare) address and the header is ignored.
 * Must run after TrustProxies (it is appended to the global middleware stack).
 */
final class TrustCloudflareClientIp
{
    public const HEADER = 'CF-Connecting-IP';

    public function handle(Request $request, Closure $next): Response
    {
        $this->apply($request);

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    public function apply(Request $request): void
    {
        $visitor = trim((string) $request->headers->get(self::HEADER, ''));
        if ($visitor === '' || filter_var($visitor, FILTER_VALIDATE_IP) === false) {
            return;
        }
        if (! $request->isFromTrustedProxy()) {
            return;
        }

        $peer = $request->getClientIp();
        if ($peer === null || $peer === $visitor || ! IpUtils::checkIp($peer, TrustedProxyList::CLOUDFLARE)) {
            return;
        }

        // The trusted proxy vouches for the Cloudflare edge, which vouches for the visitor: make the
        // visitor the only forwarded hop so getClientIp()/ip() return it. X-Forwarded-Proto/Host are
        // untouched (isSecure() and URL generation keep working).
        $request->headers->set('X-Forwarded-For', $visitor);
    }
}
