<?php

namespace App\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * Production defaults for the public deployment (S14-02).
 *
 * Applied to the loaded configuration when APP_ENV=production; local development and tests are left
 * untouched. Each correction is returned as a warning so AppServiceProvider can log it loudly once.
 */
final class ProductionHardening
{
    /** Allowlist entries that always mean the web container itself. */
    private const LOOPBACK_TARGETS = ['localhost', '127.0.0.1', '::1', '[::1]', '0.0.0.0'];

    /**
     * @return list<string> warnings describing every unsafe value that was corrected
     */
    public static function apply(Repository $config, string $environment): array
    {
        if ($environment !== 'production') {
            return [];
        }

        $warnings = [];

        // Never render stack traces, SQL or env values to visitors (refusing to boot would take the
        // whole site down for a configuration slip, so debug is forced off and reported instead).
        if ((bool) $config->get('app.debug')) {
            $config->set('app.debug', false);
            $warnings[] = 'APP_DEBUG=true bị bỏ qua khi APP_ENV=production: đã ép APP_DEBUG=false. Hãy sửa web/.env.';
        }

        // Session cookie: Secure behind HTTPS unless explicitly disabled, always HttpOnly, SameSite lax/strict.
        $secure = $config->get('session.secure');
        if ($secure === null || $secure === '') {
            $config->set('session.secure', true);
        } elseif (! filter_var($secure, FILTER_VALIDATE_BOOL)) {
            $warnings[] = 'SESSION_SECURE_COOKIE=false khi APP_ENV=production: cookie phiên có thể bị gửi qua HTTP.';
        }
        $config->set('session.http_only', true);
        $sameSite = strtolower((string) $config->get('session.same_site'));
        if (! in_array($sameSite, ['lax', 'strict'], true)) {
            $config->set('session.same_site', 'lax');
            if ($sameSite !== '') {
                $warnings[] = "SESSION_SAME_SITE={$sameSite} không an toàn khi APP_ENV=production: đã dùng lax.";
            }
        }

        // D07 on a public server: localhost/127.0.0.1 is the web container itself, never a target.
        $hosts = $config->get('ait.allowed_target_hosts', []);
        if (is_array($hosts)) {
            $kept = [];
            $dropped = [];
            foreach ($hosts as $entry) {
                $entry = (string) $entry;
                if (self::isLoopbackEntry($entry)) {
                    $dropped[] = $entry;
                } else {
                    $kept[] = $entry;
                }
            }
            if ($dropped !== []) {
                $config->set('ait.allowed_target_hosts', $kept);
                $warnings[] = 'Đã bỏ '.implode(', ', $dropped).' khỏi ALLOWED_TARGET_HOSTS khi APP_ENV=production (chống SSRF, D07).';
            }
        }

        return $warnings;
    }

    private static function isLoopbackEntry(string $entry): bool
    {
        $entry = strtolower(trim($entry));
        if (in_array($entry, self::LOOPBACK_TARGETS, true)) {
            return true;
        }
        // host:port or [v6]:port
        $host = preg_match('/^\[([^\]]+)\](?::\d+)?$/', $entry, $m) === 1
            ? $m[1]
            : (substr_count($entry, ':') === 1 ? explode(':', $entry)[0] : $entry);

        if (TargetNetworkPolicy::isLoopbackName($host)) {
            return true;
        }
        $ip = TargetNetworkPolicy::normalize($host);

        return $ip !== null && (str_starts_with($ip, '127.') || $ip === '::1' || $ip === '0.0.0.0');
    }
}
