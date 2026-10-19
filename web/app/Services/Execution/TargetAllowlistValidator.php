<?php

namespace App\Services\Execution;

use App\Contracts\TargetHostResolver;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\AllowedTarget;
use App\Services\Target\DnsTargetHostResolver;
use App\Support\TargetNetworkPolicy;
use Throwable;

/**
 * Validates target hosts against SSRF protection allowlist (docs/DESIGN.md §2 D07).
 *
 * Rules:
 * - Allowed hosts are the union of config('ait.allowed_target_hosts') and allowed_targets table.
 * - If port is specified in allowlist, it must match; otherwise any port on that host is allowed.
 * - Disallowed hosts throw ApiException with ErrorCode::TargetNotAllowed.
 * - Resolved addresses in link-local/metadata/multicast ranges are always refused; targets that only
 *   the allowed_targets table authorises are also refused on loopback/private addresses when
 *   ait.target_client.block_private_networks is on (default in production, S14-02).
 */
class TargetAllowlistValidator
{
    /**
     * @param  list<string>|null  $customAllowedHosts  Override config/db allowlist for testing
     * @param  (\Closure(string): iterable<mixed>)|null  $dbTargetsResolver
     */
    public function __construct(
        private ?array $customAllowedHosts = null,
        private ?TargetHostResolver $resolver = null,
        private ?\Closure $dbTargetsResolver = null,
    ) {}

    /**
     * Check if the given URL target host is in the allowlist.
     */
    public function isAllowed(string $url): bool
    {
        return $this->allowedAddresses($url) !== [];
    }

    /**
     * Resolve before authorizing. A listed hostname authorizes its resolved addresses;
     * local/Docker/private targets remain supported as required by D07.
     *
     * @return list<string>
     */
    private function allowedAddresses(string $url): array
    {
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return [];
        }
        $parsed = parse_url($url);
        if ($parsed === false || ! isset($parsed['host'], $parsed['scheme'])
            || ! in_array(strtolower($parsed['scheme']), ['http', 'https'], true)
            || isset($parsed['user']) || isset($parsed['pass'])) {
            return [];
        }

        $host = strtolower(trim($parsed['host'], '[]'));
        $port = $parsed['port'] ?? $this->getDefaultPortForScheme($parsed['scheme']);
        if ($port === null || $port < 1 || $port > 65535) {
            return [];
        }
        try {
            $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
                ? [$host]
                : ($this->resolver ?? new DnsTargetHostResolver)->resolve($host);
        } catch (Throwable) {
            return [];
        }
        if ($addresses === []) {
            return [];
        }
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false
                || TargetNetworkPolicy::isAlwaysBlocked($address)) {
                return [];
            }
        }

        $allowedHosts = $this->customAllowedHosts ?? $this->getConfigAllowedHosts();
        if ($this->matchesHostList($host, $port, $allowedHosts)) {
            return $addresses;
        }

        if ($this->customAllowedHosts !== null && $this->dbTargetsResolver === null) {
            return [];
        }

        // ADMIN-added targets never reach loopback/private networks on a public server: a hostname
        // there could otherwise be pointed (or DNS-rebound) at internal services.
        if ($this->blockPrivateNetworks() && (TargetNetworkPolicy::isLoopbackName($host)
            || array_filter($addresses, TargetNetworkPolicy::isPrivate(...)) !== [])) {
            return [];
        }

        // 3. Check allowed_targets database table
        try {
            $dbTargets = $this->dbTargetsResolver !== null
                ? ($this->dbTargetsResolver)($host)
                : AllowedTarget::query()->whereRaw('LOWER(host) = ?', [$host])->get(['port']);

            foreach ($dbTargets as $target) {
                $targetPort = is_array($target) ? ($target['port'] ?? null) : ($target->port ?? null);
                if ($targetPort === null || $targetPort === $port) {
                    return $addresses;
                }
            }
        } catch (Throwable) {
            // In unit tests without database connection, continue
        }

        return [];
    }

    /** @return list<string> Addresses to pin in the transport, with no second DNS lookup. */
    public function resolveAllowedAddresses(string $url): array
    {
        $addresses = $this->allowedAddresses($url);
        if ($addresses === []) {
            $this->reject($url);
        }

        return $addresses;
    }

    /**
     * Assert that the target URL is allowed, or throw ApiException(TARGET_NOT_ALLOWED).
     *
     * @throws ApiException
     */
    public function assertAllowed(string $url): void
    {
        if (! $this->isAllowed($url)) {
            $this->reject($url);
        }
    }

    private function reject(string $url): never
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? $url;
        throw new ApiException(
            ErrorCode::TargetNotAllowed,
            "Target '{$host}' không hợp lệ, không resolve được hoặc không thuộc allowlist.",
            ['url' => $url, 'host' => $host]
        );
    }

    /**
     * Match a host and port against a list of entries formatted as "host" or "host:port".
     *
     * @param  list<string>  $allowedList
     */
    protected function matchesHostList(string $host, ?int $port, array $allowedList): bool
    {
        foreach ($allowedList as $entry) {
            $entry = trim(strtolower($entry));
            if ($entry === '') {
                continue;
            }

            $allowedPort = null;
            if (filter_var($entry, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                $allowedHost = $entry;
            } elseif (preg_match('/^\[([^\]]+)\](?::([0-9]+))?$/', $entry, $matches)) {
                $allowedHost = $matches[1];
                if (filter_var($allowedHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                    continue;
                }
                $allowedPort = isset($matches[2]) ? (int) $matches[2] : null;
            } elseif (preg_match('/^([^:]+)(?::([0-9]+))?$/', $entry, $matches)) {
                $allowedHost = $matches[1];
                $allowedPort = isset($matches[2]) ? (int) $matches[2] : null;
            } else {
                continue;
            }

            if ($allowedPort !== null && ($allowedPort < 1 || $allowedPort > 65535)) {
                continue;
            }
            if ($host === $allowedHost && ($allowedPort === null || $port === $allowedPort)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine default port based on scheme.
     */
    protected function getDefaultPortForScheme(?string $scheme): ?int
    {
        return match (strtolower((string) $scheme)) {
            'http' => 80,
            'https' => 443,
            default => null,
        };
    }

    /**
     * Whether allowed_targets entries may resolve to loopback/private addresses (config-driven).
     */
    protected function blockPrivateNetworks(): bool
    {
        try {
            return function_exists('config') && (bool) config('ait.target_client.block_private_networks', false);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Safely resolve config allowed hosts with fallback when container is not booted.
     *
     * @return list<string>
     */
    protected function getConfigAllowedHosts(): array
    {
        try {
            if (function_exists('config')) {
                $hosts = config('ait.allowed_target_hosts');
                if (is_array($hosts)) {
                    return array_values(array_map('strval', $hosts));
                }
            }
        } catch (Throwable) {
        }

        return ['localhost', '127.0.0.1', 'benchmark'];
    }
}
