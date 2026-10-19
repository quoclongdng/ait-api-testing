<?php

use App\Support\TrustedProxyList;

/*
 * Read by Laravel's TrustProxies middleware (global middleware). See docs/DESIGN.md §7 TRUSTED_PROXIES and
 * ADR-0007. Production (only the host nginx can reach 127.0.0.1:8091): TRUSTED_PROXIES=*; the visitor
 * IP then comes from CF-Connecting-IP (App\Http\Middleware\TrustCloudflareClientIp). Alternative:
 * TRUSTED_PROXIES=cloudflare,172.16.0.0/12 resolves it from X-Forwarded-For alone.
 */
return [
    'proxies' => TrustedProxyList::parse(is_string($proxies = env('TRUSTED_PROXIES')) ? $proxies : null),
];
