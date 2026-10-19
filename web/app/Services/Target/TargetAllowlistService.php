<?php

namespace App\Services\Target;

use App\Contracts\TargetHostResolver;
use App\Services\Execution\TargetAllowlistValidator;

/**
 * Retains the published service API while sharing HTTP scheme checks and DNS
 * authorization with the validator used by the pinned target transport.
 */
class TargetAllowlistService extends TargetAllowlistValidator
{
    /**
     * @param  list<string>|null  $configuredHosts
     * @param  (\Closure(string): iterable<mixed>)|null  $dbTargetsResolver
     */
    public function __construct(
        ?array $configuredHosts = null,
        ?\Closure $dbTargetsResolver = null,
        ?TargetHostResolver $resolver = null,
    ) {
        parent::__construct($configuredHosts, $resolver, $dbTargetsResolver);
    }

    public function isAllowed(string $url): bool
    {
        return parent::isAllowed($this->normalizeUrl($url));
    }

    /** @return list<string> */
    public function resolveAllowedAddresses(string $url): array
    {
        return parent::resolveAllowedAddresses($this->normalizeUrl($url));
    }

    /** @return array{0: string|null, 1: int|null} */
    public function extractHostAndPort(string $url): array
    {
        $parts = parse_url($this->normalizeUrl($url));
        if (! is_array($parts) || ! isset($parts['host'])) {
            return [null, null];
        }

        return [strtolower(trim($parts['host'], '[]')), $parts['port'] ?? null];
    }

    private function normalizeUrl(string $url): string
    {
        // Preserve the service's host[:port] input API; explicit schemes are
        // always validated by the parent before DNS or transport is used.
        return str_contains($url, '://') ? $url : 'http://'.$url;
    }
}
