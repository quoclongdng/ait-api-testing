<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Address ranges the Test Executor must never reach (D07 on a public server, S14-02).
 *
 * - {@see isAlwaysBlocked()}: link-local (cloud metadata 169.254.169.254, fe80::/10), "this network",
 *   multicast/broadcast and other provider metadata addresses. No sandbox target lives there, so these
 *   are refused for every target, whichever allowlist authorised the host.
 * - {@see isPrivate()}: loopback and private/CGNAT/ULA ranges. Refused for targets added by an ADMIN
 *   (`allowed_targets`) when `ait.target_client.block_private_networks` is on (default in production):
 *   the internal Docker benchmark services stay reachable only through the hostnames the operator lists
 *   in ALLOWED_TARGET_HOSTS.
 */
final class TargetNetworkPolicy
{
    /** @var list<string> */
    private const ALWAYS_BLOCKED = [
        '0.0.0.0/8',           // "this network"
        '169.254.0.0/16',      // IPv4 link-local, incl. cloud metadata 169.254.169.254
        '100.100.100.200/32',  // Alibaba Cloud metadata
        '224.0.0.0/4',         // multicast
        '240.0.0.0/4',         // reserved + 255.255.255.255 broadcast
        '::/128',              // unspecified
        'fe80::/10',           // IPv6 link-local
        'fd00:ec2::254/128',   // AWS IMDS over IPv6
        'ff00::/8',            // IPv6 multicast
    ];

    /** @var list<string> */
    private const PRIVATE = [
        '127.0.0.0/8',      // loopback
        '10.0.0.0/8',       // RFC 1918
        '172.16.0.0/12',    // RFC 1918 (Docker networks)
        '192.168.0.0/16',   // RFC 1918
        '100.64.0.0/10',    // CGNAT
        '192.0.0.0/24',     // IETF protocol assignments
        '198.18.0.0/15',    // benchmarking
        '::1/128',          // IPv6 loopback
        'fc00::/7',         // IPv6 unique local
        '64:ff9b:1::/48',   // local-use NAT64
    ];

    /**
     * Docker Compose services of the platform itself (docker/docker-compose*.yml). They are never test
     * targets: the database, the web app and its worker, ai-service, Ollama and Vite. The benchmark
     * services (benchmark, simple-store, ...) are not in this list and stay allowed (S14-02).
     *
     * @var list<string>
     */
    public const PLATFORM_SERVICES = ['postgres', 'web', 'worker', 'ai-service', 'ollama', 'vite'];

    /**
     * Whether $host names a platform service: the Compose service name (`postgres`) or its container
     * name (`ait-postgres-1`, `ait_postgres_1`), both resolvable by Docker's internal DNS.
     */
    public static function isPlatformServiceName(string $host): bool
    {
        $host = strtolower(rtrim(trim($host), '.'));
        $services = implode('|', array_map(static fn (string $s): string => preg_quote($s, '/'), self::PLATFORM_SERVICES));

        return in_array($host, self::PLATFORM_SERVICES, true)
            || preg_match('/^(?:[a-z0-9][a-z0-9_-]*[-_])?(?:'.$services.')[-_]\d+$/', $host) === 1;
    }

    /**
     * Well-known names of the host machine, the Docker gateway and cloud metadata services. They are
     * refused like loopback addresses when private networks are blocked (S13-03).
     *
     * @var list<string>
     */
    public const INTERNAL_NAMES = [
        'localhost.localdomain',
        'ip6-localhost',
        'ip6-loopback',
        'host.docker.internal',
        'gateway.docker.internal',
        'kubernetes.docker.internal',
        'host.containers.internal',
        'metadata',
        'metadata.google.internal',
        'instance-data',
        'instance-data.ec2.internal',
    ];

    /**
     * Hostnames that always mean the local machine, the Docker host/gateway or a cloud metadata
     * service, refused like loopback addresses.
     */
    public static function isLoopbackName(string $host): bool
    {
        $host = strtolower(rtrim(trim($host, '[]'), '.'));

        return $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || in_array($host, self::INTERNAL_NAMES, true);
    }

    /**
     * Whether $host "ends in a number" (WHATWG URL Standard): its last label is decimal digits or a
     * 0x-prefixed hex number. Browsers, curl and inet_aton() read such a host as an IPv4 address
     * (2130706433, 0x7f000001, 017700000001, 127.1 all mean 127.0.0.1), never as a DNS name.
     */
    public static function endsInNumber(string $host): bool
    {
        $labels = explode('.', strtolower(rtrim(trim($host), '.')));
        $last = array_pop($labels);

        return preg_match('/^(?:\d+|0x[0-9a-f]*)$/', $last) === 1;
    }

    /**
     * Dotted-quad form of an IPv4 address written in a legacy inet_aton() notation: 1 to 4 parts,
     * each decimal, octal (leading 0) or hex (0x), the last part filling the remaining bytes
     * (127.1 → 127.0.0.1, 2130706433 → 127.0.0.1). Null when $host is not such an address.
     */
    public static function parseLegacyIpv4(string $host): ?string
    {
        $parts = explode('.', strtolower(rtrim(trim($host), '.')));
        if (count($parts) > 4) {
            return null;
        }

        $numbers = [];
        foreach ($parts as $part) {
            $number = match (true) {
                preg_match('/^0x[0-9a-f]*$/', $part) === 1 => self::parseDigits(substr($part, 2), 16),
                preg_match('/^0[0-7]+$/', $part) === 1 => self::parseDigits(substr($part, 1), 8),
                preg_match('/^(?:0|[1-9]\d*)$/', $part) === 1 => self::parseDigits($part, 10),
                default => null,
            };
            if ($number === null) {
                return null;
            }
            $numbers[] = $number;
        }

        $last = array_pop($numbers);
        foreach ($numbers as $number) {
            if ($number > 255) {
                return null;
            }
        }
        if ($last >= 256 ** (4 - count($numbers))) {
            return null;
        }

        $value = $last;
        foreach ($numbers as $index => $number) {
            $value += $number * 256 ** (3 - $index);
        }

        return long2ip($value);
    }

    /** Value of a digit string in $base (an empty string is 0, as in "0x"), null above 32 bits. */
    private static function parseDigits(string $digits, int $base): ?int
    {
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return 0;
        }
        // Longer than any 32-bit value in this base: refuse before the integer overflows.
        $maxLength = match ($base) {
            8 => 11,
            16 => 8,
            default => 10,
        };
        if (strlen($digits) > $maxLength) {
            return null;
        }
        $value = intval($digits, $base);

        return $value <= 0xFFFFFFFF ? $value : null;
    }

    public static function isAlwaysBlocked(string $ip): bool
    {
        $ip = self::normalize($ip);

        return $ip === null || IpUtils::checkIp($ip, self::ALWAYS_BLOCKED);
    }

    public static function isPrivate(string $ip): bool
    {
        $ip = self::normalize($ip);

        return $ip === null || IpUtils::checkIp($ip, self::PRIVATE);
    }

    /**
     * Whether a target address must be refused.
     *
     * @param  bool  $blockPrivate  also refuse loopback/private ranges (ADMIN-added targets on a public server)
     */
    public static function isBlocked(string $ip, bool $blockPrivate): bool
    {
        return self::isAlwaysBlocked($ip) || ($blockPrivate && self::isPrivate($ip));
    }

    /**
     * Canonical textual form; IPv4-mapped IPv6 (::ffff:127.0.0.1, ::ffff:7f00:1) becomes the IPv4
     * address so a mapped literal cannot slip past the IPv4 ranges. Null when not an IP address.
     */
    public static function normalize(string $ip): ?string
    {
        $binary = @inet_pton(trim($ip, '[]'));
        if ($binary === false) {
            return null;
        }
        if (strlen($binary) === 16 && str_starts_with($binary, str_repeat("\0", 10)."\xff\xff")) {
            $binary = substr($binary, 12);
        }
        $text = inet_ntop($binary);

        return $text === false ? null : $text;
    }
}
