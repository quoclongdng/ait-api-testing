<?php

namespace App\Services\Execution;

use App\Data\Execution\AssertionResult;
use App\Data\OpenApi\ParsedOpenApi;
use App\Data\TargetResponse;
use App\Enums\TestVerdict;
use App\Models\ApiSpec;
use App\Services\Dsl\DslSchemaErrorFormatter;
use App\Support\SensitiveDataMasker;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator as JsonSchemaValidator;

/**
 * Service to evaluate Test DSL assertions deterministically against target HTTP responses.
 *
 * Implements docs/DESIGN.md §2 D03 (deterministic test oracle in PHP) and Sprint 4 (S4-02).
 * Supports the complete set of 6 assertion types defined in test-dsl.schema.json:
 * 1. status
 * 2. status_in
 * 3. response_time
 * 4. header
 * 5. json_path
 * 6. json_schema
 */
class AssertionEngine
{
    private ?JsonSchemaValidator $schemaValidator = null;

    /** @var array<string, JsonSchemaValidator> */
    protected array $specValidators = [];

    /** @var array<string, JsonSchemaValidator> */
    protected array $inlineValidators = [];

    /**
     * Evaluate all assertions in a DSL step against a target response.
     *
     * @param  array<string, mixed>  $step
     * @param  ParsedOpenApi|ApiSpec|array<string, mixed>|object|null  $spec
     * @param  array<string, mixed>  $variables
     * @return list<AssertionResult>
     */
    public function assertStep(
        array $step,
        TargetResponse $response,
        mixed $spec = null,
        array $variables = []
    ): array {
        if (! isset($step['assertions']) || ! is_array($step['assertions'])) {
            return [];
        }

        return $this->assertAll($step['assertions'], $response, $spec, $variables);
    }

    /**
     * Evaluate a list of DSL assertions against a target response.
     *
     * @param  array<array-key, mixed>  $assertions
     * @param  ParsedOpenApi|ApiSpec|array<string, mixed>|object|null  $spec
     * @param  array<string, mixed>  $variables
     * @return list<AssertionResult>
     */
    public function assertAll(
        array $assertions,
        TargetResponse $response,
        mixed $spec = null,
        array $variables = []
    ): array {
        $results = [];

        foreach ($assertions as $assertion) {
            if (! is_array($assertion)) {
                continue;
            }

            /** @var array<string, mixed> $assertion */
            $results[] = $this->assert($assertion, $response, $spec, $variables);
        }

        return $results;
    }

    /**
     * Evaluate a single assertion definition against a target response.
     *
     * @param  array<string, mixed>  $assertion
     * @param  ParsedOpenApi|ApiSpec|array<string, mixed>|object|null  $spec
     * @param  array<string, mixed>  $variables
     */
    public function assert(
        array $assertion,
        TargetResponse $response,
        mixed $spec = null,
        array $variables = []
    ): AssertionResult {
        $type = (string) ($assertion['type'] ?? '');

        return match ($type) {
            'status' => $this->assertStatus($assertion, $response, $variables),
            'status_in' => $this->assertStatusIn($assertion, $response, $variables),
            'response_time' => $this->assertResponseTime($assertion, $response, $variables),
            'header' => $this->assertHeader($assertion, $response, $variables),
            'json_path' => $this->assertJsonPath($assertion, $response, $variables),
            'json_schema' => $this->assertJsonSchema($assertion, $response, $spec),
            default => new AssertionResult(
                type: $type,
                passed: false,
                expected: null,
                actual: null,
                message: "Loại assertion '{$type}' không được hỗ trợ."
            ),
        };
    }

    /**
     * Determine test verdict from a list of assertion results.
     *
     * @param  list<AssertionResult>  $results
     */
    public function determineVerdict(array $results): TestVerdict
    {
        foreach ($results as $result) {
            if (! $result->passed) {
                return TestVerdict::Fail;
            }
        }

        return TestVerdict::Pass;
    }

    /**
     * 1. Assert Status Code (status).
     *
     * @param  array<string, mixed>  $assertion
     * @param  array<string, mixed>  $variables
     */
    public function assertStatus(array $assertion, TargetResponse $response, array $variables = []): AssertionResult
    {
        $rawExpected = $assertion['equals'] ?? null;
        $interpolated = $this->interpolate($rawExpected, $variables);
        $expected = is_numeric($interpolated) ? (int) $interpolated : (int) $rawExpected;
        $actual = $response->status;
        $passed = $actual === $expected;

        return new AssertionResult(
            type: 'status',
            passed: $passed,
            expected: $expected,
            actual: $actual,
            message: $passed ? null : "Mong đợi mã trạng thái {$expected}, nhưng nhận được {$actual}."
        );
    }

    /**
     * 2. Assert Status Code in List (status_in).
     *
     * @param  array<string, mixed>  $assertion
     * @param  array<string, mixed>  $variables
     */
    public function assertStatusIn(array $assertion, TargetResponse $response, array $variables = []): AssertionResult
    {
        $rawValues = $assertion['values'] ?? [];
        $rawValues = is_array($rawValues) ? $rawValues : [$rawValues];

        $expected = [];
        foreach ($rawValues as $v) {
            $interpolated = $this->interpolate($v, $variables);
            $expected[] = is_numeric($interpolated) ? (int) $interpolated : (int) $v;
        }

        $actual = $response->status;
        $passed = in_array($actual, $expected, true);

        return new AssertionResult(
            type: 'status_in',
            passed: $passed,
            expected: $expected,
            actual: $actual,
            message: $passed ? null : 'Mong đợi mã trạng thái thuộc ['.implode(', ', $expected)."], nhưng nhận được {$actual}."
        );
    }

    /**
     * 3. Assert Response Time (response_time).
     *
     * @param  array<string, mixed>  $assertion
     * @param  array<string, mixed>  $variables
     */
    public function assertResponseTime(array $assertion, TargetResponse $response, array $variables = []): AssertionResult
    {
        $rawExpected = $assertion['lte_ms'] ?? 0;
        $interpolated = $this->interpolate($rawExpected, $variables);
        $expected = is_numeric($interpolated) ? (int) $interpolated : (int) $rawExpected;

        $actual = $response->durationMs;
        $passed = $actual <= $expected;

        return new AssertionResult(
            type: 'response_time',
            passed: $passed,
            expected: $expected,
            actual: $actual,
            message: $passed ? null : "Mong đợi thời gian phản hồi ≤ {$expected} ms, nhưng mất {$actual} ms."
        );
    }

    /**
     * 4. Assert HTTP Header (header).
     *
     * @param  array<string, mixed>  $assertion
     * @param  array<string, mixed>  $variables
     */
    public function assertHeader(array $assertion, TargetResponse $response, array $variables = []): AssertionResult
    {
        $rawName = (string) ($assertion['name'] ?? '');
        $name = (string) $this->interpolate($rawName, $variables);
        $op = (string) ($assertion['op'] ?? 'eq');

        $rawValue = $assertion['value'] ?? null;
        $expected = $rawValue !== null ? $this->interpolate($rawValue, $variables) : null;

        // Case-insensitive header lookup
        $targetLower = strtolower($name);
        $found = false;
        $actualValues = [];

        foreach ($response->headers as $hName => $hValues) {
            if (strtolower((string) $hName) === $targetLower) {
                $found = true;
                $actualValues = (array) $hValues;
                break;
            }
        }

        $firstValue = $found && isset($actualValues[0]) ? (string) $actualValues[0] : null;
        $joinedValue = $found ? implode(', ', array_map('strval', $actualValues)) : null;

        $result = match ($op) {
            'exists' => new AssertionResult(
                type: 'header',
                passed: $found,
                expected: true,
                actual: $found ? $joinedValue : null,
                message: $found ? null : "Mong đợi có header '{$name}', nhưng phản hồi không có."
            ),
            'not_exists' => new AssertionResult(
                type: 'header',
                passed: ! $found,
                expected: false,
                actual: $found ? $joinedValue : null,
                message: ! $found ? null : "Mong đợi không có header '{$name}', nhưng nhận được '{$joinedValue}'."
            ),
            'eq' => $this->evaluateHeaderEq($name, $expected, $found, $firstValue, $joinedValue),
            'contains' => $this->evaluateHeaderContains($name, $expected, $found, $joinedValue),
            'matches' => $this->evaluateHeaderMatches($name, $expected, $found, $firstValue, $joinedValue),
            default => new AssertionResult(
                type: 'header',
                passed: false,
                expected: $expected,
                actual: $joinedValue,
                message: "Toán tử header '{$op}' không được hỗ trợ."
            ),
        };

        return SensitiveDataMasker::isSensitiveName($name) ? $this->maskHeaderResult($result, $name) : $result;
    }

    /**
     * Credential headers (Set-Cookie, Authorization, API keys...) must not be stored in
     * assertion_results (docs/DESIGN.md §9): keep the verdict, mask the compared values.
     */
    private function maskHeaderResult(AssertionResult $result, string $name): AssertionResult
    {
        $mask = static fn (mixed $value): mixed => is_string($value) ? SensitiveDataMasker::MASK : $value;

        return new AssertionResult(
            type: $result->type,
            passed: $result->passed,
            expected: $mask($result->expected),
            actual: $mask($result->actual),
            // Without an actual value (header absent) the message names the header only.
            message: $result->passed ? null : ($result->actual === null ? $result->message : "Assertion header '{$name}' không đạt (giá trị nhạy cảm đã được che)."),
        );
    }

    private function evaluateHeaderEq(
        string $name,
        mixed $expected,
        bool $found,
        ?string $firstValue,
        ?string $joinedValue
    ): AssertionResult {
        if (! $found || $firstValue === null) {
            return new AssertionResult(
                type: 'header',
                passed: false,
                expected: $expected,
                actual: null,
                message: "Cần header '{$name}' để so sánh 'eq', nhưng phản hồi không có."
            );
        }

        $expectedStr = (string) $expected;
        $passed = ($firstValue === $expectedStr || $joinedValue === $expectedStr);

        return new AssertionResult(
            type: 'header',
            passed: $passed,
            expected: $expectedStr,
            actual: $joinedValue,
            message: $passed ? null : "Mong đợi header '{$name}' bằng '{$expectedStr}', nhưng nhận được '{$joinedValue}'."
        );
    }

    private function evaluateHeaderContains(
        string $name,
        mixed $expected,
        bool $found,
        ?string $joinedValue
    ): AssertionResult {
        if (! $found || $joinedValue === null) {
            return new AssertionResult(
                type: 'header',
                passed: false,
                expected: $expected,
                actual: null,
                message: "Cần header '{$name}' để so sánh 'contains', nhưng phản hồi không có."
            );
        }

        $expectedStr = (string) $expected;
        $passed = str_contains($joinedValue, $expectedStr);

        return new AssertionResult(
            type: 'header',
            passed: $passed,
            expected: $expectedStr,
            actual: $joinedValue,
            message: $passed ? null : "Mong đợi header '{$name}' chứa '{$expectedStr}', nhưng nhận được '{$joinedValue}'."
        );
    }

    private function evaluateHeaderMatches(
        string $name,
        mixed $expected,
        bool $found,
        ?string $firstValue,
        ?string $joinedValue
    ): AssertionResult {
        if (! $found || $joinedValue === null) {
            return new AssertionResult(
                type: 'header',
                passed: false,
                expected: $expected,
                actual: null,
                message: "Cần header '{$name}' để so khớp 'matches', nhưng phản hồi không có."
            );
        }

        $pattern = (string) $expected;
        $regex = $this->compileDslPattern($pattern);
        if (! $this->isValidRegex($regex)) {
            return new AssertionResult(
                type: 'header',
                passed: false,
                expected: $pattern,
                actual: $joinedValue,
                message: "Biểu thức chính quy (regex) không hợp lệ: '{$pattern}'."
            );
        }

        $target = $firstValue ?? $joinedValue;
        $matchJoined = $this->safePregMatch($regex, $joinedValue);
        $matchFirst = $this->safePregMatch($regex, $target);
        $passed = ($matchJoined === 1 || $matchFirst === 1);

        return new AssertionResult(
            type: 'header',
            passed: $passed,
            expected: $pattern,
            actual: $joinedValue,
            message: $passed ? null : "Mong đợi header '{$name}' khớp regex '{$pattern}', nhưng nhận được '{$joinedValue}'."
        );
    }

    /**
     * 5. Assert JSONPath (json_path).
     *
     * @param  array<string, mixed>  $assertion
     * @param  array<string, mixed>  $variables
     */
    public function assertJsonPath(array $assertion, TargetResponse $response, array $variables = []): AssertionResult
    {
        $rawPath = (string) ($assertion['path'] ?? '$');
        $path = (string) $this->interpolate($rawPath, $variables);
        $op = (string) ($assertion['op'] ?? 'eq');

        $rawValue = $assertion['value'] ?? null;
        $expected = array_key_exists('value', $assertion) ? $this->interpolate($rawValue, $variables) : null;

        // Ensure response body is not empty and valid JSON
        if (trim($response->body) === '') {
            return new AssertionResult(
                type: 'json_path',
                passed: false,
                expected: $expected,
                actual: null,
                message: 'Body phản hồi rỗng, không thể đánh giá JSONPath.'
            );
        }

        $data = json_decode($response->body, false);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return new AssertionResult(
                type: 'json_path',
                passed: false,
                expected: $expected,
                actual: null,
                message: 'Body phản hồi không phải JSON hợp lệ.'
            );
        }

        // Resolve JSONPath against decoded data
        [$found, $actualValue, $resolveError] = $this->resolveJsonPath($data, $path);
        if ($resolveError !== null) {
            return new AssertionResult(
                type: 'json_path',
                passed: false,
                expected: $expected,
                actual: null,
                message: $resolveError
            );
        }

        if ($op === 'exists') {
            return new AssertionResult(
                type: 'json_path',
                passed: $found,
                expected: true,
                actual: $found ? $actualValue : null,
                message: $found ? null : "Đường dẫn '{$path}' không tồn tại trong JSON phản hồi."
            );
        }

        if ($op === 'not_exists') {
            return new AssertionResult(
                type: 'json_path',
                passed: ! $found,
                expected: false,
                actual: $found ? $actualValue : null,
                message: ! $found ? null : "Mong đợi đường dẫn '{$path}' không tồn tại, nhưng lại có trong phản hồi."
            );
        }

        if (! $found) {
            return new AssertionResult(
                type: 'json_path',
                passed: false,
                expected: $expected,
                actual: null,
                message: "Đường dẫn '{$path}' không tồn tại trong JSON phản hồi."
            );
        }

        return $this->evaluateJsonPathOperator($op, $actualValue, $expected, $path);
    }

    /**
     * Evaluate JSONPath operators when the target node is found.
     */
    private function evaluateJsonPathOperator(string $op, mixed $actual, mixed $expected, string $path): AssertionResult
    {
        return match ($op) {
            'eq' => $this->evaluateEq($actual, $expected, $path),
            'ne' => $this->evaluateNe($actual, $expected, $path),
            'gt' => $this->evaluateGt($actual, $expected, $path),
            'gte' => $this->evaluateGte($actual, $expected, $path),
            'lt' => $this->evaluateLt($actual, $expected, $path),
            'lte' => $this->evaluateLte($actual, $expected, $path),
            'contains' => $this->evaluateContains($actual, $expected, $path),
            'matches' => $this->evaluateMatches($actual, $expected, $path),
            'type' => $this->evaluateType($actual, $expected, $path),
            default => new AssertionResult(
                type: 'json_path',
                passed: false,
                expected: $expected,
                actual: $actual,
                message: "Toán tử JSONPath '{$op}' không được hỗ trợ."
            ),
        };
    }

    private function evaluateEq(mixed $actual, mixed $expected, string $path): AssertionResult
    {
        $passed = $this->areValuesEqual($actual, $expected);

        return new AssertionResult(
            type: 'json_path',
            passed: $passed,
            expected: $expected,
            actual: $actual,
            message: $passed ? null : "Mong đợi '{$path}' bằng ".json_encode($expected).', nhận được '.json_encode($actual).'.'
        );
    }

    private function evaluateNe(mixed $actual, mixed $expected, string $path): AssertionResult
    {
        $passed = ! $this->areValuesEqual($actual, $expected);

        return new AssertionResult(
            type: 'json_path',
            passed: $passed,
            expected: $expected,
            actual: $actual,
            message: $passed ? null : "Mong đợi '{$path}' khác ".json_encode($expected).'.'
        );
    }

    private function evaluateGt(mixed $actual, mixed $expected, string $path): AssertionResult
    {
        $passed = $this->isOrderable($actual, $expected) && $actual > $expected;

        return new AssertionResult(
            type: 'json_path',
            passed: $passed,
            expected: $expected,
            actual: $actual,
            message: $passed ? null : "Mong đợi '{$path}' > ".json_encode($expected).', nhận được '.json_encode($actual).'.'
        );
    }

    private function evaluateGte(mixed $actual, mixed $expected, string $path): AssertionResult
    {
        $passed = $this->isOrderable($actual, $expected) && $actual >= $expected;

        return new AssertionResult(
            type: 'json_path',
            passed: $passed,
            expected: $expected,
            actual: $actual,
            message: $passed ? null : "Mong đợi '{$path}' ≥ ".json_encode($expected).', nhận được '.json_encode($actual).'.'
        );
    }

    private function evaluateLt(mixed $actual, mixed $expected, string $path): AssertionResult
    {
        $passed = $this->isOrderable($actual, $expected) && $actual < $expected;

        return new AssertionResult(
            type: 'json_path',
            passed: $passed,
            expected: $expected,
            actual: $actual,
            message: $passed ? null : "Mong đợi '{$path}' < ".json_encode($expected).', nhận được '.json_encode($actual).'.'
        );
    }

    private function evaluateLte(mixed $actual, mixed $expected, string $path): AssertionResult
    {
        $passed = $this->isOrderable($actual, $expected) && $actual <= $expected;

        return new AssertionResult(
            type: 'json_path',
            passed: $passed,
            expected: $expected,
            actual: $actual,
            message: $passed ? null : "Mong đợi '{$path}' ≤ ".json_encode($expected).', nhận được '.json_encode($actual).'.'
        );
    }

    /**
     * Ordering operators compare JSON numbers only: a numeric string in the response (e.g. "10")
     * is a schema error, not a number. The expected value may be a numeric string because
     * `{{var}}` interpolation inside a string yields a string.
     */
    private function isOrderable(mixed $actual, mixed $expected): bool
    {
        return (is_int($actual) || is_float($actual))
            && (is_int($expected) || is_float($expected) || (is_string($expected) && is_numeric($expected)));
    }

    private function evaluateContains(mixed $actual, mixed $expected, string $path): AssertionResult
    {
        $passed = false;

        if (is_string($actual)) {
            $passed = is_scalar($expected) && str_contains($actual, (string) $expected);
        } elseif (is_array($actual)) {
            foreach ($actual as $item) {
                if ($this->areValuesEqual($item, $expected)) {
                    $passed = true;
                    break;
                }
            }
        } elseif (is_object($actual)) {
            foreach (get_object_vars($actual) as $item) {
                if ($this->areValuesEqual($item, $expected)) {
                    $passed = true;
                    break;
                }
            }
        }

        return new AssertionResult(
            type: 'json_path',
            passed: $passed,
            expected: $expected,
            actual: $actual,
            message: $passed ? null : "Mong đợi '{$path}' chứa ".json_encode($expected).', nhận được '.json_encode($actual).'.'
        );
    }

    private function evaluateMatches(mixed $actual, mixed $expected, string $path): AssertionResult
    {
        if (! is_string($actual)) {
            return new AssertionResult(
                type: 'json_path',
                passed: false,
                expected: $expected,
                actual: $actual,
                message: "Mong đợi '{$path}' là chuỗi để so khớp regex, nhận được kiểu ".gettype($actual).'.'
            );
        }

        $pattern = (string) $expected;
        $regex = $this->compileDslPattern($pattern);
        if (! $this->isValidRegex($regex)) {
            return new AssertionResult(
                type: 'json_path',
                passed: false,
                expected: $pattern,
                actual: $actual,
                message: "Biểu thức chính quy (regex) không hợp lệ: {$pattern}."
            );
        }

        $match = $this->safePregMatch($regex, $actual);
        $passed = $match === 1;

        return new AssertionResult(
            type: 'json_path',
            passed: $passed,
            expected: $pattern,
            actual: $actual,
            message: $passed ? null : "Mong đợi '{$path}' khớp mẫu {$pattern}, nhận được ".json_encode($actual).'.'
        );
    }

    private function evaluateType(mixed $actual, mixed $expected, string $path): AssertionResult
    {
        $actualType = $this->determineJsonType($actual);
        $expectedType = strtolower((string) $expected);

        $passed = match ($expectedType) {
            'string' => is_string($actual),
            'integer', 'int' => is_int($actual),
            'number', 'float', 'numeric' => is_int($actual) || is_float($actual),
            'boolean', 'bool' => is_bool($actual),
            'array' => is_array($actual) && array_is_list($actual),
            'object' => is_object($actual) || (is_array($actual) && ! array_is_list($actual)),
            'null' => is_null($actual),
            default => false,
        };

        return new AssertionResult(
            type: 'json_path',
            passed: $passed,
            expected: $expectedType,
            actual: $actualType,
            message: $passed ? null : "Mong đợi '{$path}' có kiểu '{$expectedType}', nhưng nhận được '{$actualType}'."
        );
    }

    /**
     * 6. Assert JSON Schema (json_schema).
     *
     * @param  array<string, mixed>  $assertion
     * @param  ParsedOpenApi|ApiSpec|array<string, mixed>|object|null  $spec
     */
    public function assertJsonSchema(
        array $assertion,
        TargetResponse $response,
        mixed $spec = null
    ): AssertionResult {
        $hasSchema = array_key_exists('schema', $assertion);
        $hasRef = isset($assertion['schema_ref']) && is_string($assertion['schema_ref']);

        $expected = $hasRef ? $assertion['schema_ref'] : ($hasSchema ? $assertion['schema'] : null);

        if (trim($response->body) === '') {
            return new AssertionResult(
                type: 'json_schema',
                passed: false,
                expected: $expected,
                actual: null,
                message: 'Body phản hồi rỗng, không thể kiểm tra JSON Schema.'
            );
        }

        $data = json_decode($response->body, false);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return new AssertionResult(
                type: 'json_schema',
                passed: false,
                expected: $expected,
                actual: $response->body,
                message: 'Body phản hồi không phải JSON hợp lệ.'
            );
        }

        if ($hasSchema) {
            $convertedSchema = $this->convertOpenApiSchemaToJsonSchema($assertion['schema']);
            $schemaJson = (string) json_encode($convertedSchema);
            $schemaHash = sha1($schemaJson);
            $validator = $this->getInlineSchemaValidator($schemaHash);

            $schemaObject = json_decode($schemaJson, false);
            if (! is_object($schemaObject) && ! is_bool($schemaObject)) {
                return new AssertionResult(
                    type: 'json_schema',
                    passed: false,
                    expected: $expected,
                    actual: null,
                    message: 'Schema inline phải là một đối tượng JSON Schema hợp lệ.'
                );
            }

            try {
                $validation = $validator->validate($data, $schemaObject);

                return $this->formatSchemaValidationResult($validation, $expected, $response);
            } catch (\Throwable $e) {
                return new AssertionResult(
                    type: 'json_schema',
                    passed: false,
                    expected: $expected,
                    actual: null,
                    message: 'Lỗi khi kiểm tra JSON Schema: '.$e->getMessage()
                );
            }
        }

        if ($hasRef) {
            $ref = (string) $assertion['schema_ref'];
            $normalizedSpec = $this->normalizeSpec($spec);

            if ($normalizedSpec === null) {
                return new AssertionResult(
                    type: 'json_schema',
                    passed: false,
                    expected: $ref,
                    actual: null,
                    message: "Không thể phân giải schema '{$ref}' vì không có đặc tả OpenAPI."
                );
            }

            if (! preg_match('~^#/components/schemas/([a-zA-Z0-9._-]+)$~', $ref, $matches)) {
                return new AssertionResult(
                    type: 'json_schema',
                    passed: false,
                    expected: $ref,
                    actual: null,
                    message: "Tham chiếu schema '{$ref}' không đúng định dạng '#/components/schemas/{name}'."
                );
            }

            $schemaName = $matches[1];
            $componentsSchemas = $normalizedSpec['components']['schemas'] ?? [];
            if (! is_array($componentsSchemas) || ! array_key_exists($schemaName, $componentsSchemas)) {
                return new AssertionResult(
                    type: 'json_schema',
                    passed: false,
                    expected: $ref,
                    actual: null,
                    message: "Tham chiếu schema '{$ref}' không tồn tại trong components.schemas của đặc tả OpenAPI."
                );
            }

            // Register complete spec with local resolver so internal refs resolve correctly
            $specJson = (string) json_encode($normalizedSpec);
            $specHash = sha1($specJson);
            $specObj = json_decode($specJson, false);
            $baseUri = "https://spec.local/{$specHash}/openapi.json";

            $validator = $this->getSpecSchemaValidator($specHash, $specObj);

            try {
                $validation = $validator->validate($data, "{$baseUri}#/components/schemas/{$schemaName}");

                return $this->formatSchemaValidationResult($validation, $ref, $response);
            } catch (\Throwable $e) {
                return new AssertionResult(
                    type: 'json_schema',
                    passed: false,
                    expected: $ref,
                    actual: null,
                    message: 'Lỗi khi kiểm tra JSON Schema: '.$e->getMessage()
                );
            }
        }

        return new AssertionResult(
            type: 'json_schema',
            passed: false,
            expected: null,
            actual: null,
            message: 'Assertion json_schema phải khai báo schema hoặc schema_ref.'
        );
    }

    private function formatSchemaValidationResult(
        ValidationResult $validation,
        mixed $expected,
        TargetResponse $response
    ): AssertionResult {
        // The response body itself is already stored (truncated at 64 KB) in
        // response_log, so it is never copied into `actual` (§9 size cap).
        if ($validation->isValid()) {
            return new AssertionResult(
                type: 'json_schema',
                passed: true,
                expected: $expected,
                actual: null,
                message: null
            );
        }

        // Vietnamese messages with the JSON location in the response (S13-03).
        $error = $validation->error();
        $messages = $error !== null
            ? (new DslSchemaErrorFormatter(rootLabel: '$', pathPrefix: '$'))->format($error)
            : ['Phản hồi không khớp JSON Schema.'];

        return new AssertionResult(
            type: 'json_schema',
            passed: false,
            expected: $expected,
            actual: null,
            message: implode('; ', $messages)
        );
    }

    /**
     * Resolve JSONPath expression into [found, value, error].
     *
     * @return array{0: bool, 1: mixed, 2: string|null}
     */
    public function resolveJsonPath(mixed $data, string $path): array
    {
        // Same parser as the DSL validator (JsonPathSubset), so a VALID case never hits this error.
        $segments = JsonPathSubset::parse($path);
        if ($segments === null) {
            return [false, null, "JSONPath không hợp lệ hoặc không được hỗ trợ: {$path}"];
        }

        $current = $data;

        foreach ($segments as $segment) {
            $type = $segment['type'];
            $val = $segment['value'];

            if ($type === 'index') {
                if (! is_array($current)) {
                    return [false, null, null];
                }

                /** @var int $idx */
                $idx = $val;
                if (array_key_exists($idx, $current)) {
                    $current = $current[$idx];

                    continue;
                }

                return [false, null, null];
            }

            /** @var string $key */
            $key = (string) $val;

            if (is_object($current)) {
                if (property_exists($current, $key)) {
                    $current = $current->{$key};

                    continue;
                }

                return [false, null, null];
            }

            if (is_array($current)) {
                if (! array_is_list($current) && array_key_exists($key, $current)) {
                    $current = $current[$key];

                    continue;
                }

                return [false, null, null];
            }

            return [false, null, null];
        }

        return [true, $current, null];
    }

    /**
     * Strict and type-respecting recursive equality comparison for JSON-derived values.
     * Prevents loose PHP array comparison where {"x":"1"} would equal {"x":1}.
     */
    public function areValuesEqual(mixed $a, mixed $b): bool
    {
        if ($a === $b) {
            return true;
        }

        // Float and integer numeric equality (e.g. 10 == 10.0), but forbid string-to-number
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a == $b;
        }

        // If either is not an array or object, they cannot be equal since $a !== $b and not both numbers
        if (! is_array($a) && ! is_object($a)) {
            return false;
        }
        if (! is_array($b) && ! is_object($b)) {
            return false;
        }

        $isObjectA = is_object($a) || ! array_is_list($a);
        $isObjectB = is_object($b) || ! array_is_list($b);

        if ($isObjectA !== $isObjectB) {
            return false;
        }

        /** @var array<array-key, mixed> $arrA */
        $arrA = is_object($a) ? get_object_vars($a) : $a;
        /** @var array<array-key, mixed> $arrB */
        $arrB = is_object($b) ? get_object_vars($b) : $b;

        if (count($arrA) !== count($arrB)) {
            return false;
        }

        if (! $isObjectA) {
            foreach ($arrA as $i => $itemA) {
                if (! array_key_exists($i, $arrB) || ! $this->areValuesEqual($itemA, $arrB[$i])) {
                    return false;
                }
            }

            return true;
        }

        foreach ($arrA as $key => $valA) {
            $keyStr = (string) $key;
            if (! array_key_exists($keyStr, $arrB) && ! array_key_exists($key, $arrB)) {
                return false;
            }
            $valB = array_key_exists($keyStr, $arrB) ? $arrB[$keyStr] : $arrB[$key];
            if (! $this->areValuesEqual($valA, $valB)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine JSON-equivalent data type string.
     */
    public function determineJsonType(mixed $val): string
    {
        if (is_null($val)) {
            return 'null';
        }
        if (is_bool($val)) {
            return 'boolean';
        }
        if (is_int($val)) {
            return 'integer';
        }
        if (is_float($val)) {
            return 'number';
        }
        if (is_string($val)) {
            return 'string';
        }
        if (is_object($val)) {
            return 'object';
        }
        if (is_array($val)) {
            return array_is_list($val) ? 'array' : 'object';
        }

        return gettype($val);
    }

    /**
     * Compile a Test DSL `matches` pattern into a PCRE regex.
     *
     * DSL v1.0 is language-neutral (shared with Python), so `value` is a bare
     * pattern in the common ECMA-262 / Python `re` subset (like JSON Schema
     * `pattern`), e.g. `^[0-9]+$`. It is never a PHP-delimited regex: `/abc/`
     * matches the literal text "/abc/". Matching is unanchored (search) and
     * Unicode-aware.
     */
    public function compileDslPattern(string $pattern): string
    {
        // Escape every '~' not already escaped (preceded by an even number of backslashes).
        $escaped = (string) preg_replace('/(?<!\\\\)((?:\\\\\\\\)*)~/', '$1\\~', $pattern);

        return '~'.$escaped.'~u';
    }

    /**
     * Validate regex pattern without emitting PHPUnit/PHP E_WARNING.
     */
    public function isValidRegex(string $pattern): bool
    {
        $valid = true;
        set_error_handler(static function () use (&$valid): bool {
            $valid = false;

            return true;
        });

        try {
            if (preg_match($pattern, '') === false) {
                $valid = false;
            }
        } catch (\Throwable) {
            $valid = false;
        } finally {
            restore_error_handler();
        }

        return $valid;
    }

    /**
     * Perform preg_match without emitting PHP warnings.
     */
    public function safePregMatch(string $pattern, string $subject): ?int
    {
        $result = null;
        set_error_handler(static fn (): bool => true);

        try {
            $matched = preg_match($pattern, $subject);
            $result = $matched === false ? null : $matched;
        } catch (\Throwable) {
            $result = null;
        } finally {
            restore_error_handler();
        }

        return $result;
    }

    /**
     * Convert an OpenAPI 3.0 Schema Object into JSON Schema Draft-07 / 2020-12 compatible structure.
     *
     * Handles:
     * - nullable: true -> converts type to [type, "null"] or wraps in anyOf with null
     * - exclusiveMinimum: bool + minimum: number -> exclusiveMinimum: number
     * - exclusiveMaximum: bool + maximum: number -> exclusiveMaximum: number
     * - recursive conversion of properties, items, additionalProperties, allOf, anyOf, oneOf, not, definitions, $defs, schemas.
     */
    /**
     * Convert an OpenAPI 3.0 schema to JSON Schema draft-2020-12/draft-07 compatible representation.
     * Preserves object and array data structures without destroying types.
     */
    public function convertOpenApiSchemaToJsonSchema(mixed $schema): mixed
    {
        if (is_object($schema)) {
            return $this->convertOpenApiSchemaObject($schema);
        }

        if (is_array($schema)) {
            if ($schema !== [] && array_is_list($schema)) {
                // A JSON list is never a valid schema; leave it for the validator to reject.
                return $schema;
            }

            // Associative decoding (e.g. OpenApiParser normalized output) turns `{}` into `[]`.
            // In schema position an array is always a JSON object, so restore object shapes.
            return $this->schemaArrayToObject($this->convertOpenApiSchemaArray($schema));
        }

        return $schema;
    }

    /**
     * Cast a converted schema array to stdClass, including schema-map containers
     * (properties, $defs, ...) whose empty form would otherwise encode as `[]`.
     *
     * @param  array<string, mixed>  $schema
     */
    private function schemaArrayToObject(array $schema): object
    {
        foreach (['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas', 'schemas'] as $mapKey) {
            if (isset($schema[$mapKey]) && is_array($schema[$mapKey])) {
                $schema[$mapKey] = (object) $schema[$mapKey];
            }
        }

        return (object) $schema;
    }

    /**
     * Convert an OpenAPI 3.0 schema object to JSON Schema draft-2020-12/draft-07 compatible object.
     */
    public function convertOpenApiSchemaObject(object $schema): object
    {
        $result = clone $schema;
        if (! ($result instanceof \stdClass)) {
            $result = (object) (array) $result;
        }

        // 1. Recursively convert nested schema containers
        if (isset($result->properties)) {
            if (is_object($result->properties)) {
                $props = clone $result->properties;
                foreach (get_object_vars($props) as $propName => $propSchema) {
                    $props->{$propName} = $this->convertOpenApiSchemaToJsonSchema($propSchema);
                }
                $result->properties = $props;
            } elseif (is_array($result->properties)) {
                $props = [];
                foreach ($result->properties as $propName => $propSchema) {
                    $props[$propName] = $this->convertOpenApiSchemaToJsonSchema($propSchema);
                }
                $result->properties = $props;
            }
        }

        if (isset($result->patternProperties)) {
            if (is_object($result->patternProperties)) {
                $props = clone $result->patternProperties;
                foreach (get_object_vars($props) as $pattern => $propSchema) {
                    $props->{$pattern} = $this->convertOpenApiSchemaToJsonSchema($propSchema);
                }
                $result->patternProperties = $props;
            } elseif (is_array($result->patternProperties)) {
                $props = [];
                foreach ($result->patternProperties as $pattern => $propSchema) {
                    $props[$pattern] = $this->convertOpenApiSchemaToJsonSchema($propSchema);
                }
                $result->patternProperties = $props;
            }
        }

        if (isset($result->items)) {
            if (is_array($result->items)) {
                $result->items = array_map(fn ($it) => $this->convertOpenApiSchemaToJsonSchema($it), $result->items);
            } else {
                $result->items = $this->convertOpenApiSchemaToJsonSchema($result->items);
            }
        }

        if (isset($result->prefixItems) && is_array($result->prefixItems)) {
            $result->prefixItems = array_map(fn ($it) => $this->convertOpenApiSchemaToJsonSchema($it), $result->prefixItems);
        }

        if (isset($result->additionalProperties) && (is_object($result->additionalProperties) || is_array($result->additionalProperties))) {
            $result->additionalProperties = $this->convertOpenApiSchemaToJsonSchema($result->additionalProperties);
        }

        if (isset($result->unevaluatedProperties) && (is_object($result->unevaluatedProperties) || is_array($result->unevaluatedProperties))) {
            $result->unevaluatedProperties = $this->convertOpenApiSchemaToJsonSchema($result->unevaluatedProperties);
        }

        if (isset($result->unevaluatedItems) && (is_object($result->unevaluatedItems) || is_array($result->unevaluatedItems))) {
            $result->unevaluatedItems = $this->convertOpenApiSchemaToJsonSchema($result->unevaluatedItems);
        }

        if (isset($result->contains) && (is_object($result->contains) || is_array($result->contains))) {
            $result->contains = $this->convertOpenApiSchemaToJsonSchema($result->contains);
        }

        if (isset($result->propertyNames) && (is_object($result->propertyNames) || is_array($result->propertyNames))) {
            $result->propertyNames = $this->convertOpenApiSchemaToJsonSchema($result->propertyNames);
        }

        foreach (['allOf', 'anyOf', 'oneOf'] as $combinator) {
            if (isset($result->{$combinator}) && is_array($result->{$combinator})) {
                $result->{$combinator} = array_map(
                    fn ($sub) => $this->convertOpenApiSchemaToJsonSchema($sub),
                    $result->{$combinator}
                );
            }
        }

        foreach (['not', 'if', 'then', 'else'] as $singleSchemaKeyword) {
            if (isset($result->{$singleSchemaKeyword}) && (is_object($result->{$singleSchemaKeyword}) || is_array($result->{$singleSchemaKeyword}))) {
                $result->{$singleSchemaKeyword} = $this->convertOpenApiSchemaToJsonSchema($result->{$singleSchemaKeyword});
            }
        }

        foreach (['$defs', 'definitions', 'schemas'] as $defContainer) {
            if (isset($result->{$defContainer})) {
                if (is_object($result->{$defContainer})) {
                    $defs = clone $result->{$defContainer};
                    foreach (get_object_vars($defs) as $name => $def) {
                        $defs->{$name} = $this->convertOpenApiSchemaToJsonSchema($def);
                    }
                    $result->{$defContainer} = $defs;
                } elseif (is_array($result->{$defContainer})) {
                    $defs = [];
                    foreach ($result->{$defContainer} as $name => $def) {
                        $defs[$name] = $this->convertOpenApiSchemaToJsonSchema($def);
                    }
                    $result->{$defContainer} = $defs;
                }
            }
        }

        // 2. Handle OpenAPI 3.0 boolean exclusiveMinimum / exclusiveMaximum
        if (isset($result->exclusiveMinimum) && is_bool($result->exclusiveMinimum)) {
            if ($result->exclusiveMinimum === true && isset($result->minimum) && is_numeric($result->minimum)) {
                $result->exclusiveMinimum = $result->minimum;
                unset($result->minimum);
            } else {
                unset($result->exclusiveMinimum);
            }
        }

        if (isset($result->exclusiveMaximum) && is_bool($result->exclusiveMaximum)) {
            if ($result->exclusiveMaximum === true && isset($result->maximum) && is_numeric($result->maximum)) {
                $result->exclusiveMaximum = $result->maximum;
                unset($result->maximum);
            } else {
                unset($result->exclusiveMaximum);
            }
        }

        // 3. Handle OpenAPI 3.0 nullable: true
        if (isset($result->nullable) && $result->nullable === true) {
            unset($result->nullable);

            $hasType = isset($result->type);
            if ($hasType) {
                if (is_string($result->type)) {
                    $result->type = [$result->type, 'null'];
                } elseif (is_array($result->type) && ! in_array('null', $result->type, true)) {
                    $result->type[] = 'null';
                }

                if (isset($result->enum) && is_array($result->enum) && ! in_array(null, $result->enum, true)) {
                    $result->enum[] = null;
                }
            } else {
                $nullAlternative = (object) ['type' => 'null'];
                if (isset($result->{'$ref'})) {
                    $refSchema = (object) ['$ref' => $result->{'$ref'}];
                    unset($result->{'$ref'});
                    $result->anyOf = [$refSchema, $nullAlternative];
                } else {
                    $result = (object) [
                        'anyOf' => [
                            $result,
                            $nullAlternative,
                        ],
                    ];
                }
            }
        } elseif (property_exists($result, 'nullable')) {
            unset($result->nullable);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function convertOpenApiSchemaArray(array $schema): array
    {
        // 1. Recursively convert nested schema containers first
        if (isset($schema['properties'])) {
            if (is_array($schema['properties'])) {
                foreach ($schema['properties'] as $propName => $propSchema) {
                    $schema['properties'][$propName] = $this->convertOpenApiSchemaToJsonSchema($propSchema);
                }
            } elseif (is_object($schema['properties'])) {
                $schema['properties'] = $this->convertOpenApiSchemaObject($schema['properties']);
            }
        }

        if (isset($schema['patternProperties'])) {
            if (is_array($schema['patternProperties'])) {
                foreach ($schema['patternProperties'] as $pattern => $propSchema) {
                    $schema['patternProperties'][$pattern] = $this->convertOpenApiSchemaToJsonSchema($propSchema);
                }
            } elseif (is_object($schema['patternProperties'])) {
                $schema['patternProperties'] = $this->convertOpenApiSchemaObject($schema['patternProperties']);
            }
        }

        if (isset($schema['items'])) {
            if (is_array($schema['items'])) {
                // An empty array is an associatively decoded `{}` (any item), not an empty tuple.
                if ($schema['items'] !== [] && array_is_list($schema['items'])) {
                    $schema['items'] = array_map(fn ($it) => $this->convertOpenApiSchemaToJsonSchema($it), $schema['items']);
                } else {
                    $schema['items'] = $this->convertOpenApiSchemaToJsonSchema($schema['items']);
                }
            } else {
                $schema['items'] = $this->convertOpenApiSchemaToJsonSchema($schema['items']);
            }
        }

        if (isset($schema['prefixItems']) && is_array($schema['prefixItems'])) {
            $schema['prefixItems'] = array_map(fn ($it) => $this->convertOpenApiSchemaToJsonSchema($it), $schema['prefixItems']);
        }

        if (isset($schema['additionalProperties']) && (is_array($schema['additionalProperties']) || is_object($schema['additionalProperties']))) {
            $schema['additionalProperties'] = $this->convertOpenApiSchemaToJsonSchema($schema['additionalProperties']);
        }

        if (isset($schema['unevaluatedProperties']) && (is_array($schema['unevaluatedProperties']) || is_object($schema['unevaluatedProperties']))) {
            $schema['unevaluatedProperties'] = $this->convertOpenApiSchemaToJsonSchema($schema['unevaluatedProperties']);
        }

        if (isset($schema['unevaluatedItems']) && (is_array($schema['unevaluatedItems']) || is_object($schema['unevaluatedItems']))) {
            $schema['unevaluatedItems'] = $this->convertOpenApiSchemaToJsonSchema($schema['unevaluatedItems']);
        }

        if (isset($schema['contains']) && (is_array($schema['contains']) || is_object($schema['contains']))) {
            $schema['contains'] = $this->convertOpenApiSchemaToJsonSchema($schema['contains']);
        }

        if (isset($schema['propertyNames']) && (is_array($schema['propertyNames']) || is_object($schema['propertyNames']))) {
            $schema['propertyNames'] = $this->convertOpenApiSchemaToJsonSchema($schema['propertyNames']);
        }

        foreach (['allOf', 'anyOf', 'oneOf'] as $combinator) {
            if (isset($schema[$combinator]) && is_array($schema[$combinator])) {
                $schema[$combinator] = array_map(
                    fn ($sub) => $this->convertOpenApiSchemaToJsonSchema($sub),
                    $schema[$combinator]
                );
            }
        }

        foreach (['not', 'if', 'then', 'else'] as $singleSchemaKeyword) {
            if (isset($schema[$singleSchemaKeyword]) && (is_array($schema[$singleSchemaKeyword]) || is_object($schema[$singleSchemaKeyword]))) {
                $schema[$singleSchemaKeyword] = $this->convertOpenApiSchemaToJsonSchema($schema[$singleSchemaKeyword]);
            }
        }

        foreach (['$defs', 'definitions', 'schemas'] as $defContainer) {
            if (isset($schema[$defContainer]) && is_array($schema[$defContainer])) {
                foreach ($schema[$defContainer] as $name => $def) {
                    $schema[$defContainer][$name] = $this->convertOpenApiSchemaToJsonSchema($def);
                }
            }
        }

        // 2. Handle OpenAPI 3.0 boolean exclusiveMinimum / exclusiveMaximum
        if (isset($schema['exclusiveMinimum']) && is_bool($schema['exclusiveMinimum'])) {
            if ($schema['exclusiveMinimum'] === true && isset($schema['minimum']) && is_numeric($schema['minimum'])) {
                $schema['exclusiveMinimum'] = $schema['minimum'];
                unset($schema['minimum']);
            } else {
                unset($schema['exclusiveMinimum']);
            }
        }

        if (isset($schema['exclusiveMaximum']) && is_bool($schema['exclusiveMaximum'])) {
            if ($schema['exclusiveMaximum'] === true && isset($schema['maximum']) && is_numeric($schema['maximum'])) {
                $schema['exclusiveMaximum'] = $schema['maximum'];
                unset($schema['maximum']);
            } else {
                unset($schema['exclusiveMaximum']);
            }
        }

        // 3. Handle OpenAPI 3.0 nullable: true
        if (isset($schema['nullable']) && $schema['nullable'] === true) {
            unset($schema['nullable']);

            $hasType = isset($schema['type']);
            if ($hasType) {
                if (is_string($schema['type'])) {
                    $schema['type'] = [$schema['type'], 'null'];
                } elseif (is_array($schema['type']) && ! in_array('null', $schema['type'], true)) {
                    $schema['type'][] = 'null';
                }

                // If enum is present, allow null in enum as well
                if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array(null, $schema['enum'], true)) {
                    $schema['enum'][] = null;
                }
            } else {
                // If there is no explicit type (e.g. $ref, allOf, anyOf, oneOf)
                // Wrap in anyOf: [originalSchema, {type: 'null'}]
                $nullAlternative = ['type' => 'null'];
                if (isset($schema['$ref'])) {
                    $refSchema = ['$ref' => $schema['$ref']];
                    unset($schema['$ref']);
                    $schema['anyOf'] = [$refSchema, $nullAlternative];
                } else {
                    $schema = [
                        'anyOf' => [
                            $this->schemaArrayToObject($schema),
                            $nullAlternative,
                        ],
                    ];
                }
            }
        } elseif (array_key_exists('nullable', $schema)) {
            unset($schema['nullable']);
        }

        return $schema;
    }

    /**
     * Recursively interpolate {{var}} placeholders from variable context.
     *
     * @param  array<string, mixed>  $variables
     */
    public function interpolate(mixed $value, array $variables): mixed
    {
        if ($variables === []) {
            return $value;
        }

        if (is_string($value)) {
            // Exact single placeholder: return original variable type
            if (preg_match('/^\{\{([A-Za-z_][A-Za-z0-9_]*)\}\}$/', $value, $matches)) {
                $varName = $matches[1];
                if (array_key_exists($varName, $variables)) {
                    return $variables[$varName];
                }
            }

            return (string) preg_replace_callback(
                '/\{\{([A-Za-z_][A-Za-z0-9_]*)\}\}/',
                function (array $matches) use ($variables): string {
                    $varName = $matches[1];
                    if (! array_key_exists($varName, $variables)) {
                        return $matches[0];
                    }
                    $v = $variables[$varName];

                    return is_scalar($v) ? (string) $v : (string) json_encode($v);
                },
                $value
            );
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = $this->interpolate($v, $variables);
            }

            return $result;
        }

        return $value;
    }

    /**
     * Get or create Opis JSON Schema validator instance.
     */
    protected function getSchemaValidator(): JsonSchemaValidator
    {
        return $this->schemaValidator ??= $this->createSchemaValidator();
    }

    /**
     * Create a new isolated Opis JSON Schema validator with meta schemas registered.
     */
    protected function createSchemaValidator(): JsonSchemaValidator
    {
        $validator = new JsonSchemaValidator;
        $this->registerMetaSchemas($validator);

        return $validator;
    }

    /**
     * Get or create an isolated Opis JSON Schema validator for an OpenAPI spec by content hash.
     */
    protected function getSpecSchemaValidator(string $specHash, mixed $specObj): JsonSchemaValidator
    {
        if (isset($this->specValidators[$specHash])) {
            return $this->specValidators[$specHash];
        }

        $validator = $this->createSchemaValidator();
        $baseUri = "https://spec.local/{$specHash}/openapi.json";
        $resolver = $validator->resolver();
        if ($resolver !== null) {
            $resolver->registerRaw($specObj, $baseUri);
        }

        $this->specValidators[$specHash] = $validator;

        return $validator;
    }

    /**
     * Get or create an isolated Opis JSON Schema validator for an inline schema by content hash.
     */
    protected function getInlineSchemaValidator(string $schemaHash): JsonSchemaValidator
    {
        if (isset($this->inlineValidators[$schemaHash])) {
            return $this->inlineValidators[$schemaHash];
        }

        $validator = $this->createSchemaValidator();
        $this->inlineValidators[$schemaHash] = $validator;

        return $validator;
    }

    /**
     * Register standard JSON Schema Draft 2020-12 meta schemas if available.
     */
    protected function registerMetaSchemas(JsonSchemaValidator $validator): void
    {
        $resolver = $validator->resolver();
        if ($resolver === null) {
            return;
        }

        $resourceDir2020 = null;
        try {
            if (function_exists('resource_path')) {
                $resourceDir2020 = resource_path('schemas/draft2020-12');
            }
        } catch (\Throwable) {
            $resourceDir2020 = null;
        }

        $candidates2020 = [
            $resourceDir2020,
            dirname(__DIR__, 3).'/resources/schemas/draft2020-12',
            '/var/www/html/resources/schemas/draft2020-12',
        ];

        $dir2020 = null;
        foreach ($candidates2020 as $candidate) {
            if ($candidate !== null && is_dir($candidate)) {
                $dir2020 = $candidate;
                break;
            }
        }

        if ($dir2020 !== null) {
            $resolver->registerFile('https://json-schema.org/draft/2020-12/schema', "{$dir2020}/metaschema.json");
            $resolver->registerFile('https://json-schema.org/draft/2020-12/meta/core', "{$dir2020}/vocabularies/core");
            $resolver->registerFile('https://json-schema.org/draft/2020-12/meta/applicator', "{$dir2020}/vocabularies/applicator");
            $resolver->registerFile('https://json-schema.org/draft/2020-12/meta/unevaluated', "{$dir2020}/vocabularies/unevaluated");
            $resolver->registerFile('https://json-schema.org/draft/2020-12/meta/validation', "{$dir2020}/vocabularies/validation");
        }
    }

    /**
     * Normalize OpenAPI spec into an associative array with converted components.schemas.
     *
     * @return array<string, mixed>|null
     */
    protected function normalizeSpec(mixed $spec): ?array
    {
        if ($spec === null) {
            return null;
        }

        $normalized = null;

        if ($spec instanceof ParsedOpenApi) {
            return $this->normalizeSpec($spec->normalized ?? []);
        } elseif ($spec instanceof ApiSpec) {
            // Read raw uncast JSON attribute first to avoid Eloquent 'array' cast destroying JSON object/array types
            $rawNormalized = $spec->getAttributes()['normalized'] ?? null;
            if (is_string($rawNormalized) && $rawNormalized !== '') {
                $decodedObj = json_decode($rawNormalized, false);
                if (is_object($decodedObj) || is_array($decodedObj)) {
                    return $this->normalizeSpec($decodedObj);
                }
            }

            // Read parsed normalized specification from ApiSpec model
            $content = $spec->normalized ?? $spec->getAttribute('normalized');
            if (is_string($content) && $content !== '') {
                $decodedObj = json_decode($content, false);
                if (is_object($decodedObj) || is_array($decodedObj)) {
                    return $this->normalizeSpec($decodedObj);
                }
                $decoded = json_decode($content, true);
                $normalized = is_array($decoded) ? $decoded : null;
            } elseif (is_array($content)) {
                $normalized = $content;
            } elseif ($spec->raw_content) {
                $decodedObj = json_decode((string) $spec->raw_content, false);
                if (is_object($decodedObj) || is_array($decodedObj)) {
                    return $this->normalizeSpec($decodedObj);
                }
                $decoded = json_decode((string) $spec->raw_content, true);
                $normalized = is_array($decoded) ? $decoded : null;
            }
        } elseif (is_array($spec)) {
            // Check if it's already an OpenAPI spec array with components
            if (isset($spec['components'])) {
                $normalized = $spec;
            } else {
                $normalized = ['components' => ['schemas' => $spec]];
            }
        } elseif (is_object($spec)) {
            if (isset($spec->components) && (is_object($spec->components) || is_array($spec->components))) {
                $schemas = [];
                $rawSchemas = is_object($spec->components)
                    ? ($spec->components->schemas ?? null)
                    : ($spec->components['schemas'] ?? null);

                if (is_object($rawSchemas)) {
                    foreach (get_object_vars($rawSchemas) as $name => $s) {
                        $schemas[$name] = $s;
                    }
                } elseif (is_array($rawSchemas)) {
                    $schemas = $rawSchemas;
                }

                $normalized = [
                    'components' => [
                        'schemas' => $schemas,
                    ],
                ];
                foreach (get_object_vars($spec) as $k => $v) {
                    if ($k !== 'components') {
                        $normalized[$k] = $v;
                    }
                }
            } else {
                $normalized = ['components' => ['schemas' => (array) $spec]];
            }
        }

        if ($normalized === null) {
            return null;
        }

        // Convert OpenAPI 3.0 schemas inside components.schemas
        $components = $normalized['components'] ?? null;
        if (is_object($components)) {
            $components = (array) $components;
            $normalized['components'] = $components;
        }
        if (is_array($components) && isset($components['schemas'])) {
            $schemas = $components['schemas'];
            if (is_object($schemas)) {
                $schemas = get_object_vars($schemas);
                $normalized['components']['schemas'] = $schemas;
            }
            if (is_array($schemas)) {
                foreach ($schemas as $name => $schema) {
                    if (is_array($schema) || is_object($schema)) {
                        $normalized['components']['schemas'][$name] = $this->convertOpenApiSchemaToJsonSchema(
                            $this->restoreSchemaShape($schema)
                        );
                    }
                }
            }
        }

        return $normalized;
    }

    /**
     * Restore JSON object shape at schema positions.
     *
     * Associative decoding (and OpenApiParser normalization) represents an empty
     * schema `{}` as an empty PHP array, which would be re-encoded as JSON `[]` and
     * become an invalid JSON Schema. Schema positions are always JSON objects, so
     * convert them back to stdClass recursively. Literal values (const, enum,
     * default, example(s)) are left untouched.
     */
    protected function restoreSchemaShape(mixed $schema): mixed
    {
        if (is_array($schema) && $schema !== [] && array_is_list($schema)) {
            // Legacy tuple form (e.g. items: [schema, ...]) - restore each element.
            return array_map(fn (mixed $s): mixed => $this->restoreSchemaShape($s), $schema);
        }

        if (! is_array($schema) && ! $schema instanceof \stdClass) {
            return $schema;
        }

        $result = new \stdClass;
        foreach ((is_array($schema) ? $schema : get_object_vars($schema)) as $key => $value) {
            $keyName = (string) $key;
            if (in_array($keyName, ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'], true)
                && (is_array($value) || is_object($value))) {
                $map = new \stdClass;
                foreach ((is_array($value) ? $value : get_object_vars($value)) as $name => $sub) {
                    $map->{(string) $name} = $this->restoreSchemaShape($sub);
                }
                $result->{$keyName} = $map;
            } elseif (in_array($keyName, ['allOf', 'anyOf', 'oneOf', 'prefixItems'], true) && is_array($value)) {
                $result->{$keyName} = array_map(fn (mixed $s): mixed => $this->restoreSchemaShape($s), array_values($value));
            } elseif (in_array($keyName, [
                'additionalProperties', 'additionalItems', 'items', 'not', 'if', 'then', 'else',
                'contains', 'propertyNames', 'unevaluatedProperties', 'unevaluatedItems', 'contentSchema',
            ], true)) {
                $result->{$keyName} = $this->restoreSchemaShape($value);
            } else {
                $result->{$keyName} = $value;
            }
        }

        return $result;
    }
}
