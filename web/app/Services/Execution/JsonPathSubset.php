<?php

namespace App\Services\Execution;

/**
 * The JSONPath subset the Assertion Engine and the executor can evaluate: `$`, member names
 * (`$.a.b`), quoted keys (`$['x-y']`, `$["a b"]`) and non-negative array indexes (`$[0]`).
 * Wildcards (`[*]`, `.*`), recursive descent (`..`), filters (`[?()]`), slices and unions are not
 * supported. The Assertion Engine, the executor's extract and the DSL validator use this one parser
 * (ai-service validator.py mirrors it), so a path the engine cannot evaluate makes the case INVALID
 * (D04) instead of a VALID case that always FAILs or ERRORs at run time.
 */
final class JsonPathSubset
{
    public const SUPPORTED_SYNTAX = "\$.a.b, \$[0].id, \$['x-y']";

    /**
     * Split a path into segments, or null when it uses syntax outside the subset.
     *
     * @return list<array{type: 'index', value: int}|array{type: 'property', value: string}>|null
     */
    public static function parse(string $path): ?array
    {
        $trimmed = trim($path);
        if ($trimmed === '' || $trimmed === '$') {
            return [];
        }

        if (str_starts_with($trimmed, '$')) {
            $trimmed = substr($trimmed, 1);
        }

        $segments = [];
        $offset = 0;
        $len = strlen($trimmed);
        $quoted = '(?:"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')';

        while ($offset < $len) {
            $hasDot = $trimmed[$offset] === '.';
            if ($hasDot) {
                $offset++;
            }
            if ($offset >= $len) {
                return null;
            }

            $isBracket = $trimmed[$offset] === '[' && ! $hasDot;
            $pattern = $isBracket
                ? '/\G\[\s*('.$quoted.'|0|[1-9][0-9]*)\s*\]/'
                : '/\G('.$quoted.'|[^\s.\[\]\'"$*?():,@]+)/';

            if (preg_match($pattern, $trimmed, $match, 0, $offset) !== 1) {
                return null;
            }

            $rawToken = $match[1];
            if ($isBracket && ctype_digit($rawToken)) {
                $segments[] = ['type' => 'index', 'value' => (int) $rawToken];
            } else {
                $token = str_starts_with($rawToken, '"') || str_starts_with($rawToken, "'")
                    ? self::decodeQuotedKey($rawToken)
                    : $rawToken;
                if ($token === null) {
                    // Invalid escape in a quoted key ($["bad\q"]): not a key anyone can mean.
                    return null;
                }
                $segments[] = ['type' => 'property', 'value' => $token];
            }

            $offset += strlen($match[0]);
            if ($offset < $len && $trimmed[$offset] !== '.' && $trimmed[$offset] !== '[') {
                return null;
            }
        }

        return $segments;
    }

    public static function isSupported(string $path): bool
    {
        return self::parse($path) !== null;
    }

    /**
     * The key of a quoted member (JSON escapes, `\'` in single quotes), or null when an escape is
     * invalid.
     */
    private static function decodeQuotedKey(string $token): ?string
    {
        if ($token[0] === "'") {
            $encoded = '"';
            for ($i = 1; $i < strlen($token) - 1; $i++) {
                $char = $token[$i];
                if ($char === '\\') {
                    $next = $token[++$i];
                    $encoded .= $next === "'" ? "'" : '\\'.$next;
                } else {
                    $encoded .= $char === '"' ? '\\"' : $char;
                }
            }
            $token = $encoded.'"';
        }

        try {
            $key = json_decode($token, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_string($key) ? $key : null;
    }
}
