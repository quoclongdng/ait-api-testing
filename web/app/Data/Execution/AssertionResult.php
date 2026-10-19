<?php

namespace App\Data\Execution;

/**
 * Result of evaluating a single DSL assertion against a target response.
 *
 * Implements Sprint 4 (S4-02).
 * Strictly conforms to docs/contracts/web-api.openapi.yaml AssertionResult schema:
 * required: [type, passed, expected, actual, message]
 * additionalProperties: false
 */
final readonly class AssertionResult
{
    /**
     * Max serialized size of each of expected/actual/message when logged.
     * Keeps test_results.assertion_results bounded so a large target body
     * cannot bypass the 64 KB response_log cap (docs/DESIGN.md §9).
     */
    public const MAX_VALUE_LOG_BYTES = 4096;

    public function __construct(
        public string $type,
        public bool $passed,
        public mixed $expected,
        public mixed $actual,
        public ?string $message = null,
    ) {}

    /**
     * Convert to contract-compliant associative array.
     *
     * @return array{
     *     type: string,
     *     passed: bool,
     *     expected: mixed,
     *     actual: mixed,
     *     message: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'passed' => $this->passed,
            'expected' => self::capValue($this->expected),
            'actual' => self::capValue($this->actual),
            'message' => $this->message === null ? null : self::capString($this->message),
        ];
    }

    /**
     * Return the value unchanged when its JSON form fits the cap; otherwise a
     * truncated JSON string marked with a "[truncated] " prefix.
     */
    public static function capValue(mixed $value, int $maxBytes = self::MAX_VALUE_LOG_BYTES): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return self::capString($value, $maxBytes);
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($encoded === false) {
            return '[unserializable value]';
        }

        if (strlen($encoded) <= $maxBytes) {
            return $value;
        }

        return self::capString($encoded, $maxBytes);
    }

    public static function capString(string $value, int $maxBytes = self::MAX_VALUE_LOG_BYTES): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_scrub($value, 'UTF-8');
        }

        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        $prefix = '[truncated] ';

        return $prefix.mb_strcut($value, 0, max(0, $maxBytes - strlen($prefix)), 'UTF-8');
    }
}
