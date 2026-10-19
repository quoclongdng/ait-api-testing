<?php

namespace App\Data\Ai;

use InvalidArgumentException;

final class ResponseContract
{
    public static function jsonStructure(string $body): void
    {
        $data = json_decode($body);
        if (! $data instanceof \stdClass || ! isset($data->stats) || ! $data->stats instanceof \stdClass
            || ! isset($data->test_cases, $data->llm_calls) || ! is_array($data->test_cases) || ! is_array($data->llm_calls)) {
            throw new InvalidArgumentException('GenerateResponse must contain JSON arrays and a stats object.');
        }
        foreach ($data->test_cases as $case) {
            if (! $case instanceof \stdClass || ! isset($case->dsl) || ! $case->dsl instanceof \stdClass
                || ! isset($case->validation_errors) || ! is_array($case->validation_errors)) {
                throw new InvalidArgumentException('GeneratedTestCase must contain a dsl object and validation_errors array.');
            }
        }
        foreach ($data->llm_calls as $call) {
            self::llmCallJsonStructure($call);
        }
    }

    public static function llmCallJsonStructure(mixed $call): void
    {
        if (! $call instanceof \stdClass || ! isset($call->prompt) || ! is_array($call->prompt)) {
            throw new InvalidArgumentException('LlmCall must contain a prompt array.');
        }
        foreach ($call->prompt as $message) {
            if (! $message instanceof \stdClass) {
                throw new InvalidArgumentException('ChatMessage must be a JSON object.');
            }
        }
    }

    /** @param array<string, mixed> $data
     * @param  list<string>  $keys
     */
    public static function fields(array $data, array $keys, string $type): void
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data)) {
                throw new InvalidArgumentException("Missing required field '{$key}' in {$type}.");
            }
        }
        if (array_diff(array_keys($data), $keys) !== []) {
            throw new InvalidArgumentException("Unexpected field in {$type}.");
        }
    }

    public static function nonNegativeInteger(mixed $value, string $field): int
    {
        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException("Invalid '{$field}': expected integer >= 0.");
        }

        return $value;
    }
}
