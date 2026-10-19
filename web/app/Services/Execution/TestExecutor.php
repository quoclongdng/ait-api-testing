<?php

namespace App\Services\Execution;

use App\Contracts\TargetHttpClient;
use App\Data\Execution\AssertionResult;
use App\Data\Execution\StepExecutionResult;
use App\Data\Execution\TestCaseExecutionResult;
use App\Data\Experiments\AuthFixture;
use App\Data\TargetRequest;
use App\Data\TargetResponse;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Services\Dsl\DslValidator;
use App\Support\SensitiveDataMasker;
use App\Support\StepLabel;
use RuntimeException;
use Throwable;

/**
 * Service to execute Test DSL cases sequentially against a target system.
 *
 * Implements docs/DESIGN.md §2 D07 (SSRF allowlist) and Sprint 4 (S4-01).
 * Features:
 * - Sequentially executes steps with variable interpolation ({{var}})
 * - Extracts variables from status, headers, and JSON body for subsequent steps
 * - Validates target against allowlist before sending requests
 * - Enforces per-request timeouts and handles network failures gracefully
 */
class TestExecutor
{
    private TargetAllowlistValidator $allowlistValidator;

    private AssertionEngine $assertionEngine;

    private ?DslValidator $dslValidator;

    public function __construct(
        private ?TargetHttpClient $targetClient = null,
        ?TargetAllowlistValidator $allowlistValidator = null,
        ?AssertionEngine $assertionEngine = null,
        private mixed $defaultSpec = null,
        ?DslValidator $dslValidator = null,
    ) {
        $this->allowlistValidator = $allowlistValidator ?? new TargetAllowlistValidator;
        $this->assertionEngine = $assertionEngine ?? new AssertionEngine;
        $this->dslValidator = $dslValidator;
    }

    public function setDslValidator(?DslValidator $dslValidator): self
    {
        $this->dslValidator = $dslValidator;

        return $this;
    }

    public function getDslValidator(): ?DslValidator
    {
        return $this->dslValidator;
    }

    public function getAssertionEngine(): AssertionEngine
    {
        return $this->assertionEngine;
    }

    public function setDefaultSpec(mixed $spec): self
    {
        $this->defaultSpec = $spec;

        return $this;
    }

    public function getDefaultSpec(): mixed
    {
        return $this->defaultSpec;
    }

    /**
     * Get or lazy-resolve the TargetHttpClient driver.
     */
    protected function getClient(): TargetHttpClient
    {
        if ($this->targetClient !== null) {
            return $this->targetClient;
        }

        /** @var TargetHttpClient $client */
        $client = app(TargetHttpClient::class);
        $this->targetClient = $client;

        return $client;
    }

    /**
     * Execute a Test DSL case against a base target URL.
     *
     * @param  array<string, mixed>|string|object  $dsl
     * @param  array<string, mixed>|int  $initialVariables  Initial variables, or the legacy timeout override.
     * @param  mixed  $spec  OpenAPI specification for schema/schema_ref resolution (ParsedOpenApi, ApiSpec, array, object, or null).
     *
     * @throws ApiException when target host is outside allowlist
     */
    public function execute(
        mixed $dsl,
        string $baseUrl,
        array|int $initialVariables = [],
        ?int $timeoutMs = null,
        mixed $spec = null,
    ): TestCaseExecutionResult {
        if (is_int($initialVariables)) {
            $timeoutMs ??= $initialVariables;
            $initialVariables = [];
        }

        $effectiveSpec = $spec ?? $this->defaultSpec;

        if ($this->dslValidator !== null) {
            $this->dslValidator->validateOrThrow($dsl, $effectiveSpec);
        }

        // 1. Assert target host is in allowlist (D07)
        $this->allowlistValidator->assertAllowed($baseUrl);

        // 2. Parse DSL if passed as string or object
        $dslArray = $this->normalizeDsl($dsl);

        // 3. Initialize execution context
        $rawRootVariables = $dslArray['variables'] ?? [];
        $rootVariables = is_array($rawRootVariables)
            ? $rawRootVariables
            : (is_object($rawRootVariables) ? get_object_vars($rawRootVariables) : []);
        $variables = array_merge($rootVariables, $initialVariables);

        $steps = isset($dslArray['steps']) && is_array($dslArray['steps'])
            ? $dslArray['steps']
            : [];

        /** @var list<StepExecutionResult> $stepResults */
        $stepResults = [];
        $totalDurationMs = 0;
        $overallSuccess = true;
        $overallError = null;

        $defaultTimeoutMs = $timeoutMs ?? $this->getDefaultTimeoutMs();
        if ($defaultTimeoutMs <= 0) {
            throw new ApiException(ErrorCode::DslInvalid, 'timeout_ms của request phải lớn hơn 0.');
        }

        // 4. Sequentially execute each step
        foreach ($steps as $index => $step) {
            $stepIndex = $index + 1;
            $stepName = is_string($step['name'] ?? null) ? $step['name'] : "Step {$stepIndex}";

            if (! isset($step['request']) || ! is_array($step['request'])) {
                continue;
            }

            $reqDef = $step['request'];
            $method = strtoupper((string) ($reqDef['method'] ?? 'GET'));
            $pathTemplate = (string) ($reqDef['path'] ?? '/');
            $requestBuildError = null;
            $targetRequest = null;

            $timeoutMs = isset($reqDef['timeout_ms']) && is_numeric($reqDef['timeout_ms'])
                ? (int) $reqDef['timeout_ms']
                : $defaultTimeoutMs;
            if ($timeoutMs <= 0) {
                throw new ApiException(ErrorCode::DslInvalid, 'timeout_ms của request phải lớn hơn 0.');
            }

            try {
                // A. Interpolate path parameters & build URL
                $rawPathParams = isset($reqDef['path_params']) && is_array($reqDef['path_params'])
                    ? $reqDef['path_params']
                    : (isset($reqDef['path_params']) && is_object($reqDef['path_params']) ? get_object_vars($reqDef['path_params']) : []);
                $pathParams = $this->interpolateVariables($rawPathParams, $variables);
                $pathParams = is_array($pathParams) ? $pathParams : [];

                $resolvedPath = $this->buildResolvedPath($pathTemplate, $pathParams, $variables);
                $fullUrl = rtrim($baseUrl, '/').'/'.ltrim($resolvedPath, '/');

                // Check full URL against allowlist
                $this->allowlistValidator->assertAllowed($fullUrl);

                // B. Interpolate query, headers, and body
                $rawQuery = isset($reqDef['query']) && is_array($reqDef['query'])
                    ? $reqDef['query']
                    : (isset($reqDef['query']) && is_object($reqDef['query']) ? get_object_vars($reqDef['query']) : []);
                $query = $this->interpolateVariables($rawQuery, $variables);
                $query = is_array($query) ? $query : [];

                $rawHeaders = isset($reqDef['headers']) && is_array($reqDef['headers'])
                    ? $reqDef['headers']
                    : (isset($reqDef['headers']) && is_object($reqDef['headers']) ? get_object_vars($reqDef['headers']) : []);
                $headers = $this->interpolateHeaders($rawHeaders, $variables);

                $hasBody = array_key_exists('body', $reqDef);
                $body = $hasBody
                    ? $this->interpolateVariables($reqDef['body'], $variables)
                    : null;

                $this->assertCredentialVariablesResolved([$resolvedPath, $query, $headers, $body]);

                // Ensure JSON header if body is present (including null, false, [], {})
                if ($hasBody && ! $this->hasHeader($headers, 'Content-Type')) {
                    $headers['Content-Type'] = 'application/json';
                }

                $targetRequest = new TargetRequest(
                    method: $method,
                    url: $fullUrl,
                    headers: $headers,
                    query: $query,
                    body: $body,
                    timeoutMs: $timeoutMs,
                    hasBody: $hasBody,
                );
            } catch (Throwable $e) {
                // Preserve the public allowlist error contract; represent other request
                // construction failures as a failed step, just like transport failures.
                if ($e instanceof ApiException) {
                    throw $e;
                }
                $requestBuildError = $e;
                $fallbackUrl = rtrim($baseUrl, '/').'/'.ltrim($pathTemplate, '/');
                $targetRequest = new TargetRequest($method, $fallbackUrl, timeoutMs: $timeoutMs);
            }

            // C. Send HTTP request
            $stepStart = hrtime(true);
            $response = null;
            $stepError = null;
            $extractedInStep = [];

            try {
                if ($requestBuildError !== null) {
                    throw $requestBuildError;
                }
                $response = $this->getClient()->send($targetRequest);
                $stepDurationMs = (int) intdiv(hrtime(true) - $stepStart, 1_000_000);

                // D. Extract variables from response
                if (isset($step['extract']) && is_array($step['extract'])) {
                    foreach ($step['extract'] as $extractDef) {
                        if (! is_array($extractDef)) {
                            continue;
                        }

                        $varName = (string) ($extractDef['var'] ?? '');
                        $from = (string) ($extractDef['from'] ?? '');
                        // Extract paths may reference variables available before this step
                        // (e.g. "$.items[{{idx}}].id"), matching DslValidator::validateVariables.
                        $extractPath = $this->interpolateString((string) ($extractDef['path'] ?? ''), $variables);

                        if ($varName === '') {
                            continue;
                        }

                        $val = $this->extractValue($response, $from, $extractPath);
                        $extractedInStep[$varName] = $val;
                    }
                    $variables = array_replace($variables, $extractedInStep);
                }
            } catch (Throwable $e) {
                $extractedInStep = [];
                $stepDurationMs = (int) intdiv(hrtime(true) - $stepStart, 1_000_000);
                // Transport errors embed the request URI (query included): mask credentials (§9).
                $stepError = SensitiveDataMasker::maskText($e->getMessage());
                $overallSuccess = false;
                $overallError = "Bước {$stepIndex} ('".StepLabel::display($stepName)."') thất bại: {$stepError}";
            }

            /** @var list<AssertionResult> $assertionResults */
            $assertionResults = [];
            if ($response instanceof TargetResponse) {
                try {
                    $assertionResults = $this->assertionEngine->assertStep($step, $response, $effectiveSpec, $variables);
                } catch (Throwable $e) {
                    $assertionResults = [
                        new AssertionResult(
                            type: 'error',
                            passed: false,
                            expected: null,
                            actual: null,
                            message: "Lỗi khi đánh giá assertion: {$e->getMessage()}",
                        ),
                    ];
                }
                foreach ($assertionResults as $aResult) {
                    if (! $aResult->passed) {
                        $overallSuccess = false;
                        $overallError ??= "Bước {$stepIndex} ('".StepLabel::display($stepName)."'): assertion không đạt: ".($aResult->message ?? $aResult->type);
                    }
                }
            }

            $stepResult = new StepExecutionResult(
                stepIndex: $stepIndex,
                stepName: $stepName,
                request: $targetRequest,
                response: $response,
                extractedVariables: $extractedInStep,
                durationMs: $stepDurationMs,
                errorMessage: $stepError,
                isSuccess: $stepError === null && ! in_array(false, array_map(fn ($r) => $r->passed, $assertionResults), true),
                assertionResults: $assertionResults,
            );

            $stepResults[] = $stepResult;
            $totalDurationMs += $stepDurationMs;

            // Short-circuit execution if a step experiences an unexpected network/transport failure
            if ($stepError !== null) {
                break;
            }
        }

        return new TestCaseExecutionResult(
            isSuccess: $overallSuccess,
            stepResults: $stepResults,
            initialVariables: $rootVariables,
            finalVariables: $variables,
            totalDurationMs: $totalDurationMs,
            errorMessage: $overallError,
            dsl: $dslArray,
            baseUrl: $baseUrl,
        );
    }

    /**
     * Build the resolved path substituting {param} and {{var}} placeholders.
     *
     * @param  array<string, mixed>  $pathParams
     * @param  array<string, mixed>  $variables
     */
    public function buildResolvedPath(string $pathTemplate, array $pathParams, array $variables): string
    {
        // 1. Substitute {{var}} placeholders in path using rawurlencode
        $resolved = (string) preg_replace_callback(
            '/\{\{([A-Za-z_][A-Za-z0-9_]*)\}\}/',
            function (array $matches) use ($variables): string {
                $varName = $matches[1];
                if (! array_key_exists($varName, $variables)) {
                    return $matches[0];
                }

                $val = $variables[$varName];
                $valStr = $this->formatUrlParamValue($val);

                return rawurlencode($valStr);
            },
            $pathTemplate
        );

        // 2. Substitute single {param} from path_params (with fallback to variables) using rawurlencode
        return (string) preg_replace_callback(
            '/(?<!\{)\{([^{}]+)\}(?!\})/',
            function (array $matches) use ($pathParams, $variables): string {
                $paramName = $matches[1];
                if (array_key_exists($paramName, $pathParams)) {
                    $val = $pathParams[$paramName];
                    $valStr = $this->formatUrlParamValue($val);

                    return rawurlencode($valStr);
                }

                if (array_key_exists($paramName, $variables)) {
                    $val = $variables[$paramName];
                    $valStr = $this->formatUrlParamValue($val);

                    return rawurlencode($valStr);
                }

                return $matches[0];
            },
            $resolved
        );
    }

    /**
     * Format a path parameter or URL variable value to string before rawurlencode.
     * Serializes booleans to "true" / "false" instead of PHP's default ("1" / "").
     */
    private function formatUrlParamValue(mixed $val): string
    {
        if (is_bool($val)) {
            return $val ? 'true' : 'false';
        }

        if (is_scalar($val)) {
            return (string) $val;
        }

        return $this->encodeInterpolatedJson($val);
    }

    /**
     * Recursively interpolate {{varName}} placeholders in data.
     * Preserves scalar/complex types when the entire value is a single placeholder "{{varName}}".
     *
     * @param  array<string, mixed>  $variables
     */
    public function interpolateVariables(mixed $value, array $variables): mixed
    {
        if (is_string($value)) {
            // Exact single placeholder: return original variable type
            if (preg_match('/^\{\{([A-Za-z_][A-Za-z0-9_]*)\}\}$/', $value, $matches)) {
                $varName = $matches[1];
                if (array_key_exists($varName, $variables)) {
                    return $variables[$varName];
                }
            }

            return $this->interpolateString($value, $variables);
        }

        if (is_object($value)) {
            $vars = get_object_vars($value);
            if (empty($vars)) {
                return $value;
            }

            $result = new \stdClass;
            foreach ($vars as $k => $v) {
                $interpolatedKey = is_string($k) ? $this->interpolateString($k, $variables) : (string) $k;
                $result->{$interpolatedKey} = $this->interpolateVariables($v, $variables);
            }

            return $result;
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $k => $v) {
                $interpolatedKey = is_string($k) ? $this->interpolateString($k, $variables) : $k;
                $result[$interpolatedKey] = $this->interpolateVariables($v, $variables);
            }

            return $result;
        }

        return $value;
    }

    /**
     * Interpolate headers ensuring string values.
     *
     * @param  array<string, mixed>  $headers
     * @param  array<string, mixed>  $variables
     * @return array<string, string>
     */
    protected function interpolateHeaders(array $headers, array $variables): array
    {
        $result = [];
        foreach ($headers as $k => $v) {
            $key = $this->interpolateString((string) $k, $variables);
            $val = $this->interpolateVariables($v, $variables);
            if (is_scalar($val)) {
                $result[$key] = (string) $val;
            } else {
                $result[$key] = $this->encodeInterpolatedJson($val);
            }
        }

        return $result;
    }

    /**
     * Case-insensitive header existence check (RFC 9110 §5.1).
     *
     * @param  array<string, mixed>  $headers
     */
    private function hasHeader(array $headers, string $name): bool
    {
        foreach (array_keys($headers) as $key) {
            if (strcasecmp((string) $key, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * `{{auth_token_<role>}}` is only defined by a run with a benchmark `auth` fixture: an experiment run
     * (ADR-0006) or an M12 run on one of the platform's benchmark sandboxes (ADR-0006 addendum
     * 2026-10-14). A case stored by an experiment keeps that placeholder in its `Authorization` header;
     * running it against another target would send the literal placeholder and get a confusing 401.
     * The step fails before sending instead, with a message for the user (no internal ADR or screen
     * code; the experiment is suggested only where this deployment can create one, QA S14-06).
     *
     * @param  list<mixed>  $parts  resolved path, query, headers and body of the request
     *
     * @throws RuntimeException
     */
    private function assertCredentialVariablesResolved(array $parts): void
    {
        $encoded = json_encode($parts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $pattern = '/\{\{('.preg_quote(AuthFixture::VARIABLE_PREFIX, '/').'[A-Za-z0-9_]+)\}\}/';
        if (! is_string($encoded) || preg_match($pattern, $encoded, $match) !== 1) {
            return;
        }

        $where = (bool) config('ait.experiments.enabled', true)
            ? 'khi hệ thống đích là benchmark của nền tảng hoặc khi chạy trong thực nghiệm'
            : 'khi hệ thống đích là benchmark của nền tảng';

        throw new RuntimeException(
            "Biến {{{$match[1]}}} chưa có giá trị nên request không được gửi: token sandbox của benchmark chỉ được cấp {$where}. "
            .'Hãy chạy trên benchmark đó, hoặc sửa header Authorization của test case (dùng token của hệ thống đích) rồi chạy lại.'
        );
    }

    /**
     * Replace all {{var}} placeholders inside a string.
     *
     * @param  array<string, mixed>  $variables
     */
    public function interpolateString(string $text, array $variables): string
    {
        return (string) preg_replace_callback(
            '/\{\{([A-Za-z_][A-Za-z0-9_]*)\}\}/',
            function (array $matches) use ($variables): string {
                $varName = $matches[1];
                if (! array_key_exists($varName, $variables)) {
                    return $matches[0]; // leave untouched if undefined
                }

                $val = $variables[$varName];
                if (is_scalar($val)) {
                    return (string) $val;
                }

                return $this->encodeInterpolatedJson($val);
            },
            $text
        );
    }

    /**
     * Encode complex interpolated values consistently and fail explicitly when they
     * cannot be represented as JSON, allowing execute() to report a failed step.
     */
    private function encodeInterpolatedJson(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Extract a variable value from TargetResponse.
     */
    public function extractValue(TargetResponse $response, string $from, string $path): mixed
    {
        return match ($from) {
            'status' => $response->status,
            'header' => $this->extractHeader($response->headers, $path),
            'body' => $this->extractJsonPath($this->decodeBodyForExtraction($response->body), $path),
            default => throw new \InvalidArgumentException("Nguồn trích xuất không được hỗ trợ: '{$from}'."),
        };
    }

    private function decodeBodyForExtraction(string $body): mixed
    {
        try {
            return json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Không trích xuất được: response body không phải JSON hợp lệ.', 0, $exception);
        }
    }

    /**
     * Case-insensitive header value extraction.
     *
     * @param  array<string, list<string>>  $headers
     */
    protected function extractHeader(array $headers, string $headerName): string
    {
        $targetLower = strtolower($headerName);
        foreach ($headers as $key => $values) {
            if (strtolower($key) === $targetLower) {
                if (array_key_exists(0, $values)) {
                    return $values[0];
                }
                break;
            }
        }

        throw new RuntimeException("Không trích xuất được: response không có header '{$headerName}'.");
    }

    /**
     * Extract value from parsed JSON data using dotted or JSONPath syntax.
     * Supports member names, quoted keys and nonnegative array indexes.
     *
     * @throws \InvalidArgumentException for unsupported or invalid expressions
     */
    public function extractJsonPath(mixed $data, string $path): mixed
    {
        $path = trim($path);
        if ($path === '' || $path === '$') {
            return $data;
        }
        // Messages quote the expression as written in the DSL ("$.id"), shown on M12/M13.
        $expression = $path;
        $invalid = "JSONPath không hợp lệ hoặc không được hỗ trợ: '{$expression}'.";
        $missing = "Không trích xuất được: JSONPath '{$expression}' không tồn tại trong response.";

        // Same parser as the Assertion Engine and the DSL validator (JsonPathSubset): wildcards,
        // recursive descent, filters and slices fail explicitly rather than resolving another path.
        $parsed = JsonPathSubset::parse($path);
        if ($parsed === null) {
            throw new \InvalidArgumentException($invalid);
        }
        $segments = array_map(static fn (array $segment): string => (string) $segment['value'], $parsed);

        $current = $data;

        foreach ($segments as $segment) {
            if (is_array($current)) {
                if (array_key_exists($segment, $current)) {
                    $current = $current[$segment];
                } else {
                    throw new RuntimeException($missing);
                }
            } elseif (is_object($current)) {
                if (property_exists($current, $segment)) {
                    $current = $current->{$segment};
                } else {
                    throw new RuntimeException($missing);
                }
            } else {
                throw new RuntimeException($missing);
            }
        }

        return $current;
    }

    /**
     * Safely resolve default timeout with fallback when container is not booted.
     */
    protected function getDefaultTimeoutMs(): int
    {
        try {
            if (function_exists('config')) {
                $val = config('ait.target_client.default_timeout_ms');
                if (is_numeric($val)) {
                    return (int) $val;
                }
            }
        } catch (Throwable) {
        }

        return 10000;
    }

    /**
     * Normalize DSL input to array.
     *
     * @return array<string, mixed>
     */
    protected function normalizeDsl(mixed $dsl): array
    {
        if (is_array($dsl)) {
            $converted = $this->convertDecodedDsl($dsl);

            return is_array($converted) ? $converted : [];
        }

        if (is_string($dsl)) {
            $decoded = json_decode($dsl, false);
            if (! is_object($decoded) && ! is_array($decoded)) {
                return [];
            }

            $converted = $this->convertDecodedDsl($decoded);

            return is_array($converted) ? $converted : [];
        }

        if (is_object($dsl)) {
            $converted = $this->convertDecodedDsl($dsl);

            return is_array($converted) ? $converted : [];
        }

        return [];
    }

    /**
     * Recursively convert decoded stdClass into array while preserving payload objects and variable data.
     */
    protected function convertDecodedDsl(mixed $value, bool $isPayload = false, ?string $parentKey = null): mixed
    {
        if (is_object($value)) {
            $vars = get_object_vars($value);
            if ($isPayload) {
                $obj = new \stdClass;
                foreach ($vars as $k => $v) {
                    $obj->{(string) $k} = $this->convertDecodedDsl($v, isPayload: true);
                }

                return $obj;
            }

            $arr = [];
            foreach ($vars as $k => $v) {
                $childIsPayload = ($parentKey === 'variables')
                    || ($k === 'body')
                    || ($k === 'value')
                    || ($k === 'schema')
                    || ($k === 'expected');
                $arr[$k] = $this->convertDecodedDsl($v, isPayload: $childIsPayload, parentKey: (string) $k);
            }

            return $arr;
        }

        if (is_array($value)) {
            if ($isPayload) {
                $arr = [];
                foreach ($value as $k => $v) {
                    $arr[$k] = $this->convertDecodedDsl($v, isPayload: true, parentKey: is_string($k) ? $k : $parentKey);
                }

                return $arr;
            }

            $arr = [];
            foreach ($value as $k => $v) {
                $childIsPayload = ($parentKey === 'variables') || ($k === 'body');
                $childParentKey = is_string($k) ? $k : $parentKey;
                $arr[$k] = $this->convertDecodedDsl($v, isPayload: $childIsPayload, parentKey: $childParentKey);
            }

            return $arr;
        }

        return $value;
    }
}
