<?php

namespace App\Support;

/**
 * Masks credentials before target requests/responses are stored in test_results or exported
 * (docs/DESIGN.md §9: logs must not keep the Authorization header of requests to the target).
 *
 * Besides Authorization, every header or query parameter that carries a credential (cookies,
 * API keys, tokens, secrets, passwords, sessions) is replaced with '***'.
 */
final class SensitiveDataMasker
{
    public const MASK = '***';

    private const SENSITIVE_NAMES = [
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
    ];

    private const SENSITIVE_PATTERN = '/token|secret|api[-_]?key|passw(or)?d|session|credential|signature/i';

    public static function isSensitiveName(string $name): bool
    {
        $normalized = strtolower(trim($name));

        return in_array($normalized, self::SENSITIVE_NAMES, true)
            || preg_match(self::SENSITIVE_PATTERN, $normalized) === 1;
    }

    /**
     * @param  array<array-key, mixed>  $headers
     * @return array<array-key, mixed>
     */
    public static function maskHeaders(array $headers): array
    {
        $masked = [];
        foreach ($headers as $key => $value) {
            $masked[$key] = self::isSensitiveName((string) $key)
                ? (is_array($value) ? [self::MASK] : self::MASK)
                : $value;
        }

        return $masked;
    }

    /**
     * @param  array<array-key, mixed>  $query
     * @return array<array-key, mixed>
     */
    public static function maskQuery(array $query): array
    {
        $masked = [];
        foreach ($query as $key => $value) {
            $masked[$key] = self::isSensitiveName((string) $key) ? self::MASK : $value;
        }

        return $masked;
    }

    /**
     * Masks every URL embedded in free text, e.g. transport errors such as Guzzle's
     * "cURL error 28: ... for http://host/items?api_key=..." (docs/DESIGN.md §9).
     */
    public static function maskText(string $text): string
    {
        return preg_replace_callback(
            '~\b[a-z][a-z0-9+.-]*://[^\s"\'<>`]+~i',
            static fn (array $m): string => self::maskUrl($m[0]),
            $text,
        ) ?? $text;
    }

    /**
     * Masks sensitive query parameters (e.g. `?api_key=...`) and userinfo embedded in a URL.
     */
    public static function maskUrl(string $url): string
    {
        $masked = preg_replace('~^([a-z][a-z0-9+.-]*://)[^/@?#]*@~i', '$1'.self::MASK.'@', $url) ?? $url;

        $queryStart = strpos($masked, '?');
        if ($queryStart === false) {
            return $masked;
        }

        $fragmentStart = strpos($masked, '#', $queryStart);
        $query = $fragmentStart === false
            ? substr($masked, $queryStart + 1)
            : substr($masked, $queryStart + 1, $fragmentStart - $queryStart - 1);
        $fragment = $fragmentStart === false ? '' : substr($masked, $fragmentStart);

        $pairs = array_map(static function (string $pair): string {
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2 && self::isSensitiveName(rawurldecode($parts[0]))) {
                return $parts[0].'='.self::MASK;
            }

            return $pair;
        }, explode('&', $query));

        return substr($masked, 0, $queryStart + 1).implode('&', $pairs).$fragment;
    }
}
