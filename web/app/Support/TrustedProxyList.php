<?php

namespace App\Support;

/**
 * Parses TRUSTED_PROXIES for config/trustedproxy.php (S14-02).
 *
 * The value is a comma-separated list of IPs/CIDRs. The keyword `cloudflare` expands to Cloudflare's
 * published edge ranges, so behind the Cloudflare proxy request()->ip() is the
 * real client taken from X-Forwarded-For, not a Cloudflare edge address. `*` trusts the calling proxy
 * (only safe when the web container is reachable from the reverse proxy alone). Empty (default, local
 * development) trusts no proxy, so forwarded headers are ignored.
 */
final class TrustedProxyList
{
    /**
     * Cloudflare edge ranges, https://www.cloudflare.com/ips/ (checked 2026-10). Re-check that page
     * when deploying; a missing range only means some requests are rate-limited by edge IP.
     *
     * @var list<string>
     */
    public const CLOUDFLARE = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /**
     * @return list<string>|string|null null = trust no proxy; '*' = trust the calling proxy
     */
    public static function parse(?string $value): array|string|null
    {
        $entries = array_values(array_filter(array_map('trim', explode(',', (string) $value)), static fn (string $e): bool => $e !== ''));
        if ($entries === []) {
            return null;
        }
        if (in_array('*', $entries, true) || in_array('**', $entries, true)) {
            return '*';
        }

        $proxies = [];
        foreach ($entries as $entry) {
            if (strtolower($entry) === 'cloudflare') {
                array_push($proxies, ...self::CLOUDFLARE);
            } else {
                $proxies[] = $entry;
            }
        }

        return array_values(array_unique($proxies));
    }
}
