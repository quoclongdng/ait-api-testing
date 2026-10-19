<?php

namespace App\Services\TestEngine;

use App\Models\TestCase;
use InvalidArgumentException;
use JsonException;
use stdClass;

class TestFingerprintService
{
    /**
     * Root-level metadata and wrapper keys that do not affect test execution semantics.
     * Ignored ONLY at the root DSL/record level to prevent stripping user payload data
     * (e.g. request body fields named 'title' or 'description').
     * Source of truth: docs/DESIGN.md §10.5 ("bỏ title/description, sắp key").
     *
     * @var list<string>
     */
    protected array $ignoredRootKeys = [
        'title',
        'description',
        'tags',
        'fingerprint',
        'id',
        'test_suite_id',
        'endpoint_id',
        'generation_job_id',
        'created_at',
        'updated_at',
        'status',
        'validation_errors',
        'repair_attempts',
    ];

    /**
     * Calculate the canonical SHA-256 fingerprint for a Test DSL payload.
     *
     * @param  array<mixed>|string|TestCase|stdClass  $dsl
     *
     * @throws InvalidArgumentException
     */
    public function calculate(array|string|TestCase|stdClass $dsl): string
    {
        $canonicalJson = $this->toCanonicalJson($dsl);

        return hash('sha256', $canonicalJson);
    }

    /**
     * Convert Test DSL into a deterministic, compact canonical JSON string.
     * Follows RFC 8785 (JSON Canonicalization Scheme - JCS) rules:
     * - Preserves distinction between empty object {} and empty array [].
     * - UTF-16 code unit key sorting for object properties.
     * - ECMAScript number serialization (1e30 -> 1e+30, -0.0 -> 0).
     * - No whitespace, standard string escaping.
     *
     * @param  array<mixed>|string|TestCase|stdClass  $dsl
     *
     * @throws InvalidArgumentException
     */
    public function toCanonicalJson(array|string|TestCase|stdClass $dsl): string
    {
        if ($dsl instanceof TestCase) {
            $raw = $dsl->getRawOriginal('dsl');
            if (is_string($raw) && $raw !== '') {
                try {
                    $dsl = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    throw new InvalidArgumentException('Malformed JSON provided for Test DSL fingerprint calculation: '.$e->getMessage(), 0, $e);
                }
            } else {
                $dsl = $dsl->dsl;
            }
        } elseif (is_string($dsl)) {
            try {
                /** @var mixed $decoded */
                $decoded = json_decode($dsl, false, 512, JSON_THROW_ON_ERROR);
                if (! is_object($decoded) && ! is_array($decoded)) {
                    throw new InvalidArgumentException('Test DSL string must decode to a JSON object/array.');
                }
                $dsl = $decoded;
            } catch (JsonException $e) {
                throw new InvalidArgumentException('Malformed JSON provided for Test DSL fingerprint calculation: '.$e->getMessage(), 0, $e);
            }
        }

        // Unwrap if wrapped inside a record with 'dsl' key or property
        if (is_object($dsl) && isset($dsl->dsl)) {
            if (is_string($dsl->dsl)) {
                try {
                    $dsl = json_decode($dsl->dsl, false, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    throw new InvalidArgumentException('Malformed JSON provided for Test DSL fingerprint calculation: '.$e->getMessage(), 0, $e);
                }
            } else {
                $dsl = $dsl->dsl;
            }
        } elseif (is_array($dsl) && isset($dsl['dsl'])) {
            if (is_string($dsl['dsl'])) {
                try {
                    $dsl = json_decode($dsl['dsl'], false, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    throw new InvalidArgumentException('Malformed JSON provided for Test DSL fingerprint calculation: '.$e->getMessage(), 0, $e);
                }
            } else {
                $dsl = $dsl['dsl'];
            }
        }

        if ($dsl === [] || (is_object($dsl) && (array) $dsl === [])) {
            throw new InvalidArgumentException('Test DSL payload cannot be empty.');
        }

        return $this->serialize($dsl, isRoot: true);
    }

    /**
     * Recursively serialize any data value according to RFC 8785 rules.
     *
     *
     * @throws InvalidArgumentException
     */
    public function serialize(mixed $data, bool $isRoot = false): string
    {
        if ($data === null) {
            return 'null';
        }

        if (is_bool($data)) {
            return $data ? 'true' : 'false';
        }

        if (is_int($data)) {
            return $this->formatNumber((float) $data);
        }

        if (is_float($data)) {
            return $this->formatNumber($data);
        }

        if (is_string($data)) {
            return $this->serializeString($data);
        }

        if (is_object($data)) {
            /** @var array<string, mixed> $props */
            $props = (array) $data;

            return $this->serializeObject($props, $isRoot);
        }

        if (is_array($data)) {
            if (array_is_list($data)) {
                // Sequential array (list): preserve element order
                $elements = [];
                foreach ($data as $item) {
                    $elements[] = $this->serialize($item, false);
                }

                return '['.implode(',', $elements).']';
            }

            /** @var array<string, mixed> $data */
            return $this->serializeObject($data, $isRoot);
        }

        throw new InvalidArgumentException('Unsupported data type for canonicalization: '.get_debug_type($data));
    }

    /**
     * Serialize a string according to RFC 8785 Section 3.2.2.2.
     * Escapes only ASCII control chars (0x00-0x1F), quotes ("), and backslashes (\).
     * Unicode code points outside ASCII control chars (including U+2028 and U+2029) remain unescaped.
     */
    public function serializeString(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException('Invalid UTF-8 string provided for canonicalization.');
        }

        $len = strlen($value);
        $result = '"';
        for ($i = 0; $i < $len; $i++) {
            $byte = ord($value[$i]);
            if ($byte === 0x22) { // "
                $result .= '\\"';
            } elseif ($byte === 0x5C) { // \
                $result .= '\\\\';
            } elseif ($byte === 0x08) { // \b
                $result .= '\\b';
            } elseif ($byte === 0x09) { // \t
                $result .= '\\t';
            } elseif ($byte === 0x0A) { // \n
                $result .= '\\n';
            } elseif ($byte === 0x0C) { // \f
                $result .= '\\f';
            } elseif ($byte === 0x0D) { // \r
                $result .= '\\r';
            } elseif ($byte < 0x20) {
                $result .= sprintf('\\u%04x', $byte);
            } else {
                $result .= $value[$i];
            }
        }
        $result .= '"';

        return $result;
    }

    /**
     * Serialize an object/dictionary with UTF-16 code unit key sorting.
     *
     * @param  array<string, mixed>  $properties
     */
    protected function serializeObject(array $properties, bool $isRoot): string
    {
        if ($isRoot) {
            foreach ($this->ignoredRootKeys as $ignoredKey) {
                unset($properties[$ignoredKey]);
            }
        }

        if ($properties === []) {
            return '{}';
        }

        // UTF-16 code unit key sorting as mandated by RFC 8785 Section 3.2.3
        uksort($properties, function (string $a, string $b): int {
            $u16a = mb_convert_encoding($a, 'UTF-16BE', 'UTF-8');
            $u16b = mb_convert_encoding($b, 'UTF-16BE', 'UTF-8');

            return strcmp($u16a, $u16b);
        });

        $pairs = [];
        foreach ($properties as $key => $value) {
            $keyJson = $this->serializeString((string) $key);
            $pairs[] = $keyJson.':'.$this->serialize($value, false);
        }

        return '{'.implode(',', $pairs).'}';
    }

    /**
     * Format a floating point number according to ECMAScript (RFC 8785 Section 3.2.2.3 / ECMA-262 §7.1.12.1).
     */
    public function formatNumber(float $value): string
    {
        if (is_nan($value) || is_infinite($value)) {
            throw new InvalidArgumentException('Non-finite numbers (NaN/Infinity) are not permitted in JSON.');
        }

        if ($value == 0.0) {
            return '0';
        }

        $sign = $value < 0 ? '-' : '';
        $abs = abs($value);

        $raw = json_encode($abs);
        if ($raw === false) {
            throw new InvalidArgumentException('Failed to format number: '.$value);
        }

        // Check if PHP serialized it in scientific notation
        if (preg_match('/^(\d+)(?:\.(\d+))?e([+-]\d+)$/i', $raw, $matches)) {
            $intPart = $matches[1];
            $fracPart = $matches[2];
            $exp = (int) $matches[3];

            // Strip trailing zeros from fractional part in mantissa (e.g. 1.0 -> 1)
            $fracPart = rtrim($fracPart, '0');
            $digits = $intPart.$fracPart;
            $k = strlen($digits);
            $n = $exp + 1;

            // Rule 6: k <= n <= 21 -> integer representation with (n - k) trailing zeros
            if ($k <= $n && $n <= 21) {
                return $sign.$digits.str_repeat('0', $n - $k);
            }

            // Rule 7: 0 < n <= 21 -> decimal representation
            if ($n > 0 && $n <= 21) {
                return $sign.substr($digits, 0, $n).'.'.substr($digits, $n);
            }

            // Rule 8: -6 < n <= 0 -> fixed decimal representation starting with '0.'
            if ($n > -6 && $n <= 0) {
                return $sign.'0.'.str_repeat('0', -$n).$digits;
            }

            // Rule 9 & 10: Exponential representation
            $expSign = $exp >= 0 ? '+' : '-';
            $expAbs = abs($exp);
            $expFormatted = 'e'.$expSign.$expAbs;

            if ($k === 1) {
                return $sign.$digits.$expFormatted;
            }

            return $sign.$digits[0].'.'.substr($digits, 1).$expFormatted;
        }

        return $sign.$raw;
    }

    /**
     * Check if a given string matches the expected 64-char lowercase hex format.
     */
    public function isValidFingerprint(?string $fingerprint): bool
    {
        if ($fingerprint === null) {
            return false;
        }

        return (bool) preg_match('/^[a-f0-9]{64}$/', $fingerprint);
    }
}
