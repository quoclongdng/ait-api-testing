<?php

namespace App\Services\Dsl;

use App\Data\Dsl\DslValidationResult;
use App\Data\OpenApi\ParsedEndpoint;
use App\Data\OpenApi\ParsedOpenApi;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Services\Execution\AssertionEngine;
use App\Services\Execution\JsonPathSubset;
use App\Services\Support\NumericRationalHelper;
use Illuminate\Support\Collection;
use Opis\JsonSchema\Validator as JsonSchemaValidator;

/**
 * Service to validate Test DSL documents against JSON Schema and OpenAPI semantics.
 *
 * Implements docs/DESIGN.md §2 D04, D05, D06 and §10 step 3.
 */
class DslValidator
{
    use NumericRationalHelper;

    private JsonSchemaValidator $jsonSchemaValidator;

    private object $schema;

    private ?string $schemaPath;

    public function __construct(?string $schemaPath = null)
    {
        $this->schemaPath = $schemaPath;
        $this->jsonSchemaValidator = new JsonSchemaValidator;
        $this->registerMetaSchemas($this->jsonSchemaValidator);
        $this->schema = $this->loadSchema();
    }

    /**
     * Validate Test DSL against JSON Schema and optional OpenAPI specification.
     *
     * @param  mixed  $dsl  JSON string, array or stdClass object
     * @param  ParsedOpenApi|ApiSpec|Endpoint|Collection<int, Endpoint>|array<string, mixed>|null  $spec
     * @param  list<string>  $contextVariables  Variables the run defines before step 1 (ADR-0006:
     *                                          `auth_token_<role>` of an experiment), usable as `{{name}}`.
     */
    public function validate(mixed $dsl, mixed $spec = null, array $contextVariables = []): DslValidationResult
    {
        // 1. Parse and normalize DSL input
        [$dslArray, $dslObject, $parseError] = $this->normalizeInput($dsl);
        if ($parseError !== null) {
            return DslValidationResult::invalid(schemaErrors: [$parseError]);
        }

        /** @var array<string, mixed> $dslArray */
        /** @var object $dslObject */

        // 2. Validate against Test DSL v1.0 JSON Schema
        $schemaValidation = $this->jsonSchemaValidator->validate($dslObject, $this->schema);
        if (! $schemaValidation->isValid()) {
            $validationError = $schemaValidation->error();
            // Vietnamese messages with the location in the DSL, not the raw opis English (S13-03); a
            // step is named "Bước N" (1-based) like the semantic errors below (S14-06).
            $schemaErrors = $validationError !== null
                ? DslSchemaErrorFormatter::forDslDocument($dslObject)->format($validationError)
                : ['Lỗi cấu trúc Test DSL không hợp lệ.'];

            return DslValidationResult::invalid(schemaErrors: $schemaErrors, dsl: $dslArray);
        }

        // 3. Perform semantic validation
        $semanticErrors = $this->validateSemantics($dslArray, $spec, $dslObject, $contextVariables);

        if ($semanticErrors !== []) {
            return DslValidationResult::invalid(
                schemaErrors: [],
                semanticErrors: $semanticErrors,
                dsl: $dslArray
            );
        }

        return DslValidationResult::valid($dslArray);
    }

    /**
     * Validate Test DSL or throw an ApiException with 422 DSL_INVALID envelope.
     *
     *
     * @param  list<string>  $contextVariables
     *
     * @throws ApiException
     */
    public function validateOrFail(mixed $dsl, mixed $spec = null, array $contextVariables = []): DslValidationResult
    {
        $result = $this->validate($dsl, $spec, $contextVariables);

        if (! $result->isValid) {
            throw new ApiException(
                ErrorCode::DslInvalid,
                'Test DSL không vượt qua kiểm tra cấu trúc hoặc ngữ nghĩa: '.implode('; ', $result->errors),
                [
                    'errors' => $result->errors,
                    'schema_errors' => $result->schemaErrors,
                    'semantic_errors' => $result->semanticErrors,
                ]
            );
        }

        return $result;
    }

    /**
     * Alias for validateOrFail.
     *
     * @throws ApiException
     */
    public function validateOrThrow(mixed $dsl, mixed $spec = null): DslValidationResult
    {
        return $this->validateOrFail($dsl, $spec);
    }

    /**
     * Load the Test DSL v1.0 JSON Schema from disk.
     */
    protected function loadSchema(): object
    {
        $path = $this->schemaPath;

        if ($path === null || ! file_exists($path)) {
            $basePathCandidate = null;
            $basePathDirect = null;
            try {
                if (function_exists('base_path')) {
                    /** @var string $basePathCandidate */
                    $basePathCandidate = base_path('../docs/contracts/test-dsl.schema.json');
                    /** @var string $basePathDirect */
                    $basePathDirect = base_path('contracts/test-dsl.schema.json');
                }
            } catch (\Throwable) {
                $basePathCandidate = null;
                $basePathDirect = null;
            }

            $candidates = [
                $this->schemaPath,
                getenv('TEST_DSL_SCHEMA_PATH') ?: null,
                '/contracts/test-dsl.schema.json',
                $basePathDirect,
                dirname(__DIR__, 4).'/docs/contracts/test-dsl.schema.json',
                $basePathCandidate,
                dirname(__DIR__, 3).'/../docs/contracts/test-dsl.schema.json',
            ];

            foreach ($candidates as $candidate) {
                if ($candidate !== null && file_exists($candidate)) {
                    $path = $candidate;
                    break;
                }
            }
        }

        if ($path === null || ! file_exists($path)) {
            throw new \RuntimeException('Không tìm thấy tệp JSON Schema tại: docs/contracts/test-dsl.schema.json');
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Không thể đọc tệp JSON Schema tại: {$path}");
        }

        $decoded = json_decode($raw);
        if (! is_object($decoded)) {
            throw new \RuntimeException("Tệp JSON Schema tại {$path} không chứa đối tượng JSON hợp lệ.");
        }

        return $decoded;
    }

    /**
     * Normalize input DSL into array and object representations.
     *
     * @return array{0: array<string, mixed>|null, 1: object|null, 2: string|null}
     */
    protected function normalizeInput(mixed $dsl): array
    {
        if (is_string($dsl)) {
            $array = json_decode($dsl, true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($array)) {
                return [null, null, 'Lỗi cú pháp JSON trong tài liệu Test DSL: '.json_last_error_msg()];
            }

            /** @var object $object */
            $object = json_decode($dsl, false);

            return [$array, $object, null];
        }

        if (is_array($dsl)) {
            $json = json_encode($dsl);
            if ($json === false) {
                return [null, null, 'Không thể mã hóa cấu trúc mảng Test DSL sang JSON.'];
            }

            /** @var object $object */
            $object = json_decode($json, false);

            return [$dsl, $object, null];
        }

        if (is_object($dsl)) {
            $json = json_encode($dsl);
            if ($json === false) {
                return [null, null, 'Không thể mã hóa đối tượng Test DSL sang JSON.'];
            }

            /** @var array<string, mixed> $array */
            $array = json_decode($json, true);

            // Validate a deep copy: opis writes meta-schema defaults into the data it validates, which
            // would change the caller's DSL (and its fingerprint) as a side effect of validation.
            /** @var object $object */
            $object = json_decode($json, false);

            return [$array, $object, null];
        }

        return [null, null, 'Dữ liệu Test DSL phải là chuỗi JSON, mảng hoặc đối tượng.'];
    }

    /**
     * Run semantic validation rules on parsed DSL.
     *
     * @param  array<string, mixed>  $dsl
     * @param  ParsedOpenApi|ApiSpec|Endpoint|Collection<int, Endpoint>|array<string, mixed>|null  $spec
     * @param  list<string>  $contextVariables
     * @return list<string> list of semantic error messages
     */
    protected function validateSemantics(array $dsl, mixed $spec, ?object $dslObject = null, array $contextVariables = []): array
    {
        $errors = [];

        // 1. Variable extraction and usage lifecycle validation
        $varErrors = $this->validateVariables($dsl, $contextVariables);
        if ($varErrors !== []) {
            $errors = array_merge($errors, $varErrors);
        }

        // 2. Validate inline assertion schemas (independent of OpenAPI spec)
        $inlineSchemaErrors = $this->validateInlineSchemas($dsl, $dslObject);
        if ($inlineSchemaErrors !== []) {
            $errors = array_merge($errors, $inlineSchemaErrors);
        }

        // 3. Validate against OpenAPI specification if provided
        if ($spec !== null) {
            $specErrors = $this->validateAgainstSpec($dsl, $spec, $dslObject);
            if ($specErrors !== []) {
                $errors = array_merge($errors, $specErrors);
            }
        }

        return array_values(array_unique($errors));
    }

    /** JSON type names accepted by json_path op=type (AssertionEngine::evaluateType). */
    private const JSON_PATH_TYPE_NAMES = ['string', 'integer', 'int', 'number', 'float', 'numeric', 'boolean', 'bool', 'array', 'object', 'null'];

    /**
     * A `matches` pattern that does not compile or an unknown `type` name can never be evaluated:
     * reject it here (D04) instead of storing a VALID case whose assertion always FAILs.
     * Values with `{{var}}` placeholders are only known at run time and are skipped.
     *
     * @param  array<array-key, mixed>  $assertion
     */
    private function validateAssertionOperatorValue(array $assertion): ?string
    {
        $type = $assertion['type'] ?? null;
        $op = $assertion['op'] ?? null;
        if (! in_array($type, ['json_path', 'header'], true) || ! array_key_exists('value', $assertion)) {
            return null;
        }

        $value = $assertion['value'];
        if (is_string($value) && str_contains($value, '{{')) {
            return null;
        }

        if ($op === 'matches') {
            $engine = new AssertionEngine;
            if (! is_string($value) || ! $engine->isValidRegex($engine->compileDslPattern($value))) {
                return 'Pattern của op "matches" không phải biểu thức chính quy hợp lệ: '.json_encode($value, JSON_UNESCAPED_UNICODE).'.';
            }
        }

        if ($type === 'json_path' && $op === 'type'
            && (! is_string($value) || ! in_array(strtolower($value), self::JSON_PATH_TYPE_NAMES, true))) {
            return 'Giá trị của op "type" phải là một kiểu JSON (string, integer, number, boolean, array, object, null), nhận '.json_encode($value, JSON_UNESCAPED_UNICODE).'.';
        }

        return null;
    }

    /**
     * A JSONPath the Assertion Engine/executor cannot evaluate (wildcard, filter, recursive descent,
     * slice) would make a VALID case FAIL on every target: reject it here (D04). A path with a
     * `{{var}}` placeholder is only known at run time and is skipped.
     */
    private function unsupportedJsonPath(string $path): ?string
    {
        if (str_contains($path, '{{') || JsonPathSubset::isSupported($path)) {
            return null;
        }

        return 'JSONPath '.json_encode($path, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            .' không được hỗ trợ (chỉ hỗ trợ dạng '.JsonPathSubset::SUPPORTED_SYNTAX
            .'; không dùng [*], .., [?()] hay lát cắt mảng).';
    }

    /**
     * Validate syntax of all inline JSON Schemas in assertions across all steps,
     * independent of OpenAPI spec availability.
     *
     * @param  array<string, mixed>  $dsl
     * @return list<string>
     */
    protected function validateInlineSchemas(array $dsl, ?object $dslObject = null): array
    {
        $errors = [];
        $steps = isset($dsl['steps']) && is_array($dsl['steps']) ? $dsl['steps'] : [];
        $stepsObj = (is_object($dslObject) && isset($dslObject->steps) && is_array($dslObject->steps))
            ? $dslObject->steps
            : [];

        foreach ($steps as $index => $step) {
            $stepNum = $index + 1;
            $stepName = is_string($step['name'] ?? null) ? $step['name'] : "Bước {$stepNum}";
            $stepObj = $stepsObj[$index] ?? null;

            $extractList = isset($step['extract']) && is_array($step['extract']) ? $step['extract'] : [];
            foreach ($extractList as $eIndex => $extract) {
                if (is_array($extract) && ($extract['from'] ?? null) === 'body' && is_string($extract['path'] ?? null)
                    && ($pathError = $this->unsupportedJsonPath($extract['path'])) !== null) {
                    $errors[] = "Bước {$stepNum} ('{$stepName}'), extract #".($eIndex + 1).": {$pathError}";
                }
            }

            if (! isset($step['assertions']) || ! is_array($step['assertions'])) {
                continue;
            }

            $assertionsObj = (is_object($stepObj) && isset($stepObj->assertions) && is_array($stepObj->assertions))
                ? $stepObj->assertions
                : [];

            foreach ($step['assertions'] as $aIndex => $assertion) {
                if (! is_array($assertion)) {
                    continue;
                }

                $assertionObj = $assertionsObj[$aIndex] ?? null;
                $type = $assertion['type'] ?? null;

                $operatorError = $this->validateAssertionOperatorValue($assertion);
                if ($operatorError !== null) {
                    $errors[] = "Bước {$stepNum} ('{$stepName}'), assertion #".($aIndex + 1).": {$operatorError}";
                }

                if ($type === 'json_path' && is_string($assertion['path'] ?? null)
                    && ($pathError = $this->unsupportedJsonPath($assertion['path'])) !== null) {
                    $errors[] = "Bước {$stepNum} ('{$stepName}'), assertion #".($aIndex + 1).": {$pathError}";
                }

                if ($type === 'json_schema') {
                    if (array_key_exists('schema', $assertion) || (is_object($assertionObj) && property_exists($assertionObj, 'schema'))) {
                        $rawSchema = (is_object($assertionObj) && property_exists($assertionObj, 'schema'))
                            ? $assertionObj->schema
                            : $assertion['schema'];

                        $syntaxError = $this->validateJsonSchemaSyntax($rawSchema);
                        if ($syntaxError !== null) {
                            $errors[] = "Bước {$stepNum} ('{$stepName}'): Cấu trúc JSON Schema inline không hợp lệ: {$syntaxError}";
                        }
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Ensure variables referenced via {{name}} are extracted or declared before use.
     *
     * @param  array<string, mixed>  $dsl
     * @param  list<string>  $contextVariables  run-time variables defined before step 1 (ADR-0006)
     * @return list<string>
     */
    protected function validateVariables(array $dsl, array $contextVariables = []): array
    {
        $errors = [];

        // Run-time variables of the execution context, then initial variables declared at root level
        $availableVars = array_fill_keys($contextVariables, true);
        $rawVars = $dsl['variables'] ?? [];
        if (is_array($rawVars)) {
            foreach (array_keys($rawVars) as $key) {
                $availableVars[(string) $key] = true;
            }
        } elseif (is_object($rawVars)) {
            foreach (array_keys(get_object_vars($rawVars)) as $key) {
                $availableVars[(string) $key] = true;
            }
        }

        $steps = isset($dsl['steps']) && (is_array($dsl['steps']) || is_object($dsl['steps']))
            ? (array) $dsl['steps']
            : [];

        foreach ($steps as $index => $step) {
            $stepArray = is_object($step) ? (array) $step : (is_array($step) ? $step : []);
            $stepNum = $index + 1;
            $stepName = is_string($stepArray['name'] ?? null) ? $stepArray['name'] : "Bước {$stepNum}";

            // Find all {{var}} placeholders in request (path, params, query, headers, body)
            $requestVars = [];
            if (isset($stepArray['request'])) {
                $requestVars = $this->extractVariablePlaceholders($stepArray['request']);
            }

            // Find all {{var}} placeholders in assertions
            $assertionVars = [];
            if (isset($stepArray['assertions'])) {
                $assertionVars = $this->extractVariablePlaceholders($stepArray['assertions']);
            }

            // Find all {{var}} placeholders in extract paths
            $extractVars = [];
            if (isset($stepArray['extract'])) {
                $rawExtract = is_array($stepArray['extract']) || is_object($stepArray['extract'])
                    ? (array) $stepArray['extract']
                    : [];
                foreach ($rawExtract as $ext) {
                    $extArray = is_object($ext) ? (array) $ext : (is_array($ext) ? $ext : []);
                    if (isset($extArray['path'])) {
                        $extractVars = array_merge($extractVars, $this->extractVariablePlaceholders($extArray['path']));
                    }
                }
            }

            $usedVars = array_unique(array_merge($requestVars, $assertionVars, $extractVars));

            foreach ($usedVars as $varName) {
                if (! isset($availableVars[$varName])) {
                    $errors[] = "Bước {$stepNum} ('{$stepName}'): Biến '{{{$varName}}}' được sử dụng nhưng chưa được định nghĩa trong variables hoặc trích xuất (extract) từ bước trước đó.";
                }
            }

            // Register newly extracted variables from this step for subsequent steps
            if (isset($stepArray['extract'])) {
                $rawExtract = is_array($stepArray['extract']) || is_object($stepArray['extract'])
                    ? (array) $stepArray['extract']
                    : [];
                foreach ($rawExtract as $ext) {
                    $extArray = is_object($ext) ? (array) $ext : (is_array($ext) ? $ext : []);
                    if (isset($extArray['var']) && is_string($extArray['var'])) {
                        $availableVars[$extArray['var']] = true;
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Recursively scan data structure for {{varName}} placeholders.
     *
     * @return list<string>
     */
    protected function extractVariablePlaceholders(mixed $data): array
    {
        $vars = [];

        if (is_string($data)) {
            if (preg_match_all('/\{\{([A-Za-z_][A-Za-z0-9_]*)\}\}/', $data, $matches)) {
                $vars = array_merge($vars, $matches[1]);
            }
        } elseif (is_array($data)) {
            foreach ($data as $value) {
                $childVars = $this->extractVariablePlaceholders($value);
                if ($childVars !== []) {
                    $vars = array_merge($vars, $childVars);
                }
            }
        } elseif (is_object($data)) {
            foreach (get_object_vars($data) as $value) {
                $childVars = $this->extractVariablePlaceholders($value);
                if ($childVars !== []) {
                    $vars = array_merge($vars, $childVars);
                }
            }
        }

        return array_values(array_unique($vars));
    }

    /**
     * Validate DSL against OpenAPI spec (endpoints, fields, status codes, schema_ref).
     *
     * @param  array<string, mixed>  $dsl
     * @param  ParsedOpenApi|ApiSpec|Endpoint|Collection<int, Endpoint>|array<string, mixed>|null  $spec
     * @return list<string>
     */
    protected function validateAgainstSpec(array $dsl, mixed $spec, ?object $dslObject = null): array
    {
        $errors = [];
        $endpointMap = $this->buildEndpointMap($spec);
        $componentsSchemas = $this->extractComponentsSchemas($spec);

        // 1. Validate root endpoint in DSL
        $rootEndpoint = isset($dsl['endpoint']) && is_array($dsl['endpoint']) ? $dsl['endpoint'] : null;
        if ($rootEndpoint !== null) {
            $rootMethod = strtoupper((string) ($rootEndpoint['method'] ?? ''));
            $rootPath = (string) ($rootEndpoint['path'] ?? '');
            $rootKey = "{$rootMethod}:{$rootPath}";

            if (! isset($endpointMap[$rootKey])) {
                $errors[] = "Endpoint '{$rootMethod} {$rootPath}' không tồn tại trong đặc tả OpenAPI.";
            }
        }

        // 2. Validate each step against OpenAPI
        $steps = isset($dsl['steps']) && is_array($dsl['steps']) ? $dsl['steps'] : [];

        foreach ($steps as $index => $step) {
            $stepNum = $index + 1;
            $stepName = is_string($step['name'] ?? null) ? $step['name'] : "Bước {$stepNum}";

            $stepObject = null;
            if ($dslObject !== null && isset($dslObject->steps) && is_array($dslObject->steps) && isset($dslObject->steps[$index]) && is_object($dslObject->steps[$index])) {
                $stepObject = $dslObject->steps[$index];
            }

            if (! isset($step['request']) || ! is_array($step['request'])) {
                continue;
            }

            $req = $step['request'];
            $method = strtoupper((string) ($req['method'] ?? ''));
            $path = (string) ($req['path'] ?? '');
            $stepKey = "{$method}:{$path}";

            if (! isset($endpointMap[$stepKey])) {
                $errors[] = "Bước {$stepNum} ('{$stepName}'): Endpoint '{$method} {$path}' không tồn tại trong đặc tả OpenAPI.";

                continue;
            }

            $endpointData = $endpointMap[$stepKey];

            // A. Path parameters validation
            $expectedPathParams = $this->extractExpectedPathParams($path, $endpointData);
            $providedPathParams = isset($req['path_params']) && is_array($req['path_params']) ? $req['path_params'] : [];

            // A1. Check for missing required path parameters
            foreach ($expectedPathParams as $expectedParam) {
                if (! array_key_exists($expectedParam, $providedPathParams)) {
                    $errors[] = "Bước {$stepNum} ('{$stepName}'): Thiếu tham số đường dẫn bắt buộc '{$expectedParam}' cho endpoint '{$method} {$path}'.";
                }
            }

            // A2. Check for unexpected path parameters
            foreach (array_keys($providedPathParams) as $paramName) {
                $paramStr = (string) $paramName;
                if (! in_array($paramStr, $expectedPathParams, true)) {
                    $errors[] = "Bước {$stepNum} ('{$stepName}'): Tham số đường dẫn '{$paramStr}' không tồn tại trong endpoint '{$method} {$path}'.";
                }
            }

            // B. Query parameters validation
            if (isset($req['query']) && is_array($req['query'])) {
                $declaredQueryParams = $this->getDeclaredParameters($endpointData, 'query') ?? [];
                foreach (array_keys($req['query']) as $queryParam) {
                    $queryStr = (string) $queryParam;
                    if (! in_array($queryStr, $declaredQueryParams, true)) {
                        $errors[] = "Bước {$stepNum} ('{$stepName}'): Tham số query '{$queryStr}' không được khai báo trong endpoint '{$method} {$path}'.";
                    }
                }
            }

            // C. Request body fields validation
            if (array_key_exists('body', $req) && $req['body'] !== null) {
                if (empty($endpointData['request_body'])) {
                    // Endpoint doesn't accept a request body
                    $errors[] = "Bước {$stepNum} ('{$stepName}'): Endpoint '{$method} {$path}' không khai báo request body nhưng ca kiểm thử lại truyền body.";
                } elseif (is_array($req['body']) || (isset($stepObject->request->body) && is_object($stepObject->request->body))) {
                    $bodySchema = $this->extractJsonBodySchema($endpointData['request_body'], $componentsSchemas);
                    if ($bodySchema !== null) {
                        $rawBody = (isset($stepObject->request->body) && (is_object($stepObject->request->body) || is_array($stepObject->request->body)))
                            ? $stepObject->request->body
                            : $req['body'];
                        $bodyErrors = $this->validateBodyRecursively(
                            $rawBody,
                            $bodySchema,
                            $componentsSchemas,
                            '',
                            $stepNum,
                            $stepName,
                            $method,
                            $path
                        );
                        if ($bodyErrors !== []) {
                            $errors = array_merge($errors, $bodyErrors);
                        }
                    }
                }
            }

            // D. Status code assertions validation
            if (isset($step['assertions']) && is_array($step['assertions'])) {
                $declaredStatuses = array_keys($endpointData['responses'] ?? []);

                foreach ($step['assertions'] as $aIndex => $assertion) {
                    if (! is_array($assertion)) {
                        continue;
                    }

                    $assertionObject = null;
                    if ($stepObject !== null && isset($stepObject->assertions) && is_array($stepObject->assertions) && isset($stepObject->assertions[$aIndex]) && is_object($stepObject->assertions[$aIndex])) {
                        $assertionObject = $stepObject->assertions[$aIndex];
                    }

                    $type = (string) ($assertion['type'] ?? '');

                    if ($type === 'status') {
                        $status = (string) ($assertion['equals'] ?? '');
                        if (! $this->isStatusCodeDeclared($status, $declaredStatuses)) {
                            $errors[] = "Bước {$stepNum} ('{$stepName}'): Mã trạng thái HTTP '{$status}' không được khai báo trong responses của endpoint '{$method} {$path}'.";
                        }
                    } elseif ($type === 'status_in') {
                        $values = isset($assertion['values']) && is_array($assertion['values']) ? $assertion['values'] : [];
                        foreach ($values as $val) {
                            $status = (string) $val;
                            if (! $this->isStatusCodeDeclared($status, $declaredStatuses)) {
                                $errors[] = "Bước {$stepNum} ('{$stepName}'): Mã trạng thái HTTP '{$status}' trong status_in không được khai báo trong responses của endpoint '{$method} {$path}'.";
                            }
                        }
                    } elseif ($type === 'json_schema') {
                        if (isset($assertion['schema_ref']) && is_string($assertion['schema_ref'])) {
                            $ref = $assertion['schema_ref'];
                            if (! preg_match('~^#/components/schemas/([a-zA-Z0-9._-]+)$~', $ref, $matches)) {
                                $errors[] = "Bước {$stepNum} ('{$stepName}'): Schema reference '{$ref}' không đúng định dạng '#/components/schemas/{name}'.";
                            } else {
                                $schemaName = $matches[1];
                                if (! array_key_exists($schemaName, $componentsSchemas)) {
                                    $errors[] = "Bước {$stepNum} ('{$stepName}'): Schema reference '{$ref}' không tồn tại trong components.schemas của đặc tả OpenAPI.";
                                }
                            }
                        }
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Check if a status code is covered by declared response codes, wildcards (2XX), or default.
     *
     * @param  list<int|string>  $declaredStatuses
     */
    protected function isStatusCodeDeclared(string $status, array $declaredStatuses): bool
    {
        if ($declaredStatuses === []) {
            return true;
        }

        $statusStr = (string) $status;
        $declaredStrings = array_map(fn ($code) => (string) $code, $declaredStatuses);

        if (in_array($statusStr, $declaredStrings, true)) {
            return true;
        }

        if (in_array('default', $declaredStrings, true)) {
            return true;
        }

        $wildcard = substr($statusStr, 0, 1).'XX';
        if (in_array($wildcard, $declaredStrings, true)) {
            return true;
        }

        return false;
    }

    /**
     * Extract expected path parameters from path template and endpoint parameters.
     *
     * @param  array<string, mixed>  $endpointData
     * @return list<string>
     */
    protected function extractExpectedPathParams(string $path, array $endpointData): array
    {
        $params = [];

        if (preg_match_all('/(?<!\{)\{([^{}]+)\}(?!\})/', $path, $matches)) {
            $params = array_merge($params, $matches[1]);
        }

        $declaredPathParams = $this->getDeclaredParameters($endpointData, 'path');
        if ($declaredPathParams !== null) {
            $params = array_merge($params, $declaredPathParams);
        }

        return array_values(array_unique($params));
    }

    /**
     * Extract parameter names matching the given 'in' location (path, query, header).
     *
     * @param  array<string, mixed>  $endpointData
     * @return list<string>|null
     */
    protected function getDeclaredParameters(array $endpointData, string $location): ?array
    {
        if (! isset($endpointData['parameters']) || ! is_array($endpointData['parameters'])) {
            return null;
        }

        $params = [];
        foreach ($endpointData['parameters'] as $param) {
            if (is_array($param) && ($param['in'] ?? null) === $location && isset($param['name'])) {
                $params[] = (string) $param['name'];
            }
        }

        return $params;
    }

    /**
     * Extract schema for application/json request body.
     *
     * @param  array<string, mixed>  $requestBody
     * @param  array<string, mixed>  $componentsSchemas
     * @return array<string, mixed>|null
     */
    protected function extractJsonBodySchema(array $requestBody, array $componentsSchemas = []): ?array
    {
        if (isset($requestBody['schema']) && is_array($requestBody['schema'])) {
            return $this->resolveSchema($requestBody['schema'], $componentsSchemas);
        }

        $content = $requestBody['content'] ?? null;
        if (! is_array($content)) {
            return null;
        }

        $jsonMedia = $content['application/json'] ?? reset($content);
        if (! is_array($jsonMedia) || ! isset($jsonMedia['schema']) || ! is_array($jsonMedia['schema'])) {
            return null;
        }

        return $this->resolveSchema($jsonMedia['schema'], $componentsSchemas);
    }

    /**
     * Resolve $ref in schema against components.schemas (with circular reference protection).
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $componentsSchemas
     * @return array<string, mixed>
     */
    protected function resolveSchema(array $schema, array $componentsSchemas, int $depth = 0): array
    {
        if ($depth > 10) {
            return $schema;
        }

        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $ref = $schema['$ref'];
            if (str_starts_with($ref, '#/components/schemas/')) {
                $schemaName = substr($ref, strlen('#/components/schemas/'));
                if (isset($componentsSchemas[$schemaName]) && is_array($componentsSchemas[$schemaName])) {
                    $resolved = $this->resolveSchema($componentsSchemas[$schemaName], $componentsSchemas, $depth + 1);

                    $override = array_diff_key($schema, ['$ref' => true]);
                    if (empty($override)) {
                        return $resolved;
                    }

                    return $this->intersectSchemaConstraints($resolved, $override);
                }
            }
        }

        return $schema;
    }

    /**
     * Intersect constraints between two schemas (e.g. $ref target and sibling constraints).
     * Sibling constraints cannot loosen component constraints.
     *
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @return array<string, mixed>
     */
    protected function intersectSchemaConstraints(array $left, array $right): array
    {
        foreach ($right as $keyword => $value) {
            if ($keyword === 'allOf' && is_array($value)) {
                $existing = isset($left['allOf']) && is_array($left['allOf']) ? $left['allOf'] : [];
                $left['allOf'] = array_values([...$existing, ...$value]);

                continue;
            }
            if (! array_key_exists($keyword, $left)) {
                $left[$keyword] = $value;

                continue;
            }
            if (in_array($keyword, ['minimum', 'exclusiveMinimum', 'minLength', 'minItems', 'minProperties'], true)
                && is_numeric($left[$keyword]) && is_numeric($value)) {
                $left[$keyword] = max($left[$keyword], $value);
            } elseif (in_array($keyword, ['maximum', 'exclusiveMaximum', 'maxLength', 'maxItems', 'maxProperties'], true)
                && is_numeric($left[$keyword]) && is_numeric($value)) {
                $left[$keyword] = min($left[$keyword], $value);
            } elseif ($keyword === 'required' && is_array($left[$keyword]) && is_array($value)) {
                $left[$keyword] = array_values(array_unique([...$left[$keyword], ...$value]));
            } elseif ($keyword === 'properties' && is_array($left[$keyword]) && is_array($value)) {
                foreach ($value as $name => $definition) {
                    if (is_array($definition) && isset($left[$keyword][$name]) && is_array($left[$keyword][$name])) {
                        $left[$keyword][$name] = $this->intersectSchemaConstraints($left[$keyword][$name], $definition);
                    } else {
                        $left[$keyword][$name] = $definition;
                    }
                }
            } elseif ($keyword === 'items' && is_array($left[$keyword]) && is_array($value)) {
                $left[$keyword] = $this->intersectSchemaConstraints($left[$keyword], $value);
            } elseif ($keyword === 'enum' && is_array($left[$keyword]) && is_array($value)) {
                $left[$keyword] = array_values(array_filter($left[$keyword], fn ($item) => in_array($item, $value, true)));
            } elseif ($keyword === 'additionalProperties') {
                if ($left[$keyword] === false || $value === false) {
                    $left[$keyword] = false;
                } elseif (is_array($left[$keyword]) && is_array($value)) {
                    $left[$keyword] = $this->intersectSchemaConstraints($left[$keyword], $value);
                } elseif (is_array($value)) {
                    $left[$keyword] = $value;
                }
            } elseif ($keyword === 'multipleOf' && is_numeric($left[$keyword]) && is_numeric($value)) {
                $left[$keyword] = $this->calculateLcm($left[$keyword], $value);
            } elseif ($keyword === 'uniqueItems') {
                $left[$keyword] = $left[$keyword] || $value;
            }
        }

        return $left;
    }

    /**
     * Recursively flatten all allOf schemas into a single composite schema.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $componentsSchemas
     * @return array<string, mixed>
     */
    protected function flattenAllOf(array $schema, array $componentsSchemas, int $depth = 0): array
    {
        if ($depth > 10) {
            return $schema;
        }

        $resolved = $this->resolveSchema($schema, $componentsSchemas, $depth);

        if (! isset($resolved['allOf']) || ! is_array($resolved['allOf'])) {
            return $resolved;
        }

        /** @var array<string, mixed> $mergedProperties */
        $mergedProperties = isset($resolved['properties']) && is_array($resolved['properties'])
            ? $resolved['properties']
            : [];
        /** @var list<string> $mergedRequired */
        $mergedRequired = isset($resolved['required']) && is_array($resolved['required'])
            ? array_values(array_map('strval', $resolved['required']))
            : [];
        /** @var array<string, mixed>|null $mergedItems */
        $mergedItems = isset($resolved['items']) && is_array($resolved['items'])
            ? $resolved['items']
            : null;
        /** @var array<string, mixed>|bool|null $mergedAdditionalProperties */
        $mergedAdditionalProperties = $resolved['additionalProperties'] ?? null;
        /** @var list<array<string, mixed>> $extraCompositions */
        $extraCompositions = [];

        foreach ($resolved['allOf'] as $subSchema) {
            if (! is_array($subSchema)) {
                continue;
            }

            $flattenedSub = $this->flattenAllOf($subSchema, $componentsSchemas, $depth + 1);

            if (isset($flattenedSub['properties']) && is_array($flattenedSub['properties'])) {
                foreach ($flattenedSub['properties'] as $propName => $propDef) {
                    $propStr = (string) $propName;
                    if (is_array($propDef)) {
                        if (isset($mergedProperties[$propStr]) && is_array($mergedProperties[$propStr])) {
                            $mergedProperties[$propStr] = $this->flattenAllOf([
                                'allOf' => [$mergedProperties[$propStr], $propDef],
                            ], $componentsSchemas, $depth + 1);
                        } else {
                            $mergedProperties[$propStr] = $propDef;
                        }
                    }
                }
            }

            if (isset($flattenedSub['items']) && is_array($flattenedSub['items'])) {
                if ($mergedItems !== null) {
                    $mergedItems = $this->flattenAllOf([
                        'allOf' => [$mergedItems, $flattenedSub['items']],
                    ], $componentsSchemas, $depth + 1);
                } else {
                    $mergedItems = $flattenedSub['items'];
                }
            }

            if (array_key_exists('additionalProperties', $flattenedSub)) {
                $subAddProps = $flattenedSub['additionalProperties'];
                if ($mergedAdditionalProperties === null) {
                    $mergedAdditionalProperties = $subAddProps;
                } elseif ($mergedAdditionalProperties === false || $subAddProps === false) {
                    $mergedAdditionalProperties = false;
                } elseif ($mergedAdditionalProperties === true) {
                    $mergedAdditionalProperties = $subAddProps;
                } elseif ($subAddProps === true) {
                    // Stricter schema wins over true
                } elseif (is_array($mergedAdditionalProperties) && is_array($subAddProps)) {
                    $mergedAdditionalProperties = $this->flattenAllOf([
                        'allOf' => [$mergedAdditionalProperties, $subAddProps],
                    ], $componentsSchemas, $depth + 1);
                }
            }

            if (isset($flattenedSub['required']) && is_array($flattenedSub['required'])) {
                $mergedRequired = array_merge($mergedRequired, array_map('strval', $flattenedSub['required']));
            }

            if (isset($flattenedSub['anyOf']) && is_array($flattenedSub['anyOf'])) {
                $extraCompositions[] = ['anyOf' => $flattenedSub['anyOf']];
            }
            if (isset($flattenedSub['oneOf']) && is_array($flattenedSub['oneOf'])) {
                $extraCompositions[] = ['oneOf' => $flattenedSub['oneOf']];
            }
        }

        $result = $resolved;
        unset($result['allOf']);
        if ($mergedProperties !== []) {
            $result['properties'] = $mergedProperties;
        }
        if ($mergedRequired !== []) {
            $result['required'] = array_values(array_unique($mergedRequired));
        }
        if ($mergedItems !== null) {
            $result['items'] = $mergedItems;
        }
        if ($mergedAdditionalProperties !== null) {
            $result['additionalProperties'] = $mergedAdditionalProperties;
        }
        if ($extraCompositions !== []) {
            foreach ($extraCompositions as $comp) {
                if (isset($comp['anyOf']) && is_array($comp['anyOf'])) {
                    $result['anyOf'] = $comp['anyOf'];
                }
                if (isset($comp['oneOf']) && is_array($comp['oneOf'])) {
                    $result['oneOf'] = $comp['oneOf'];
                }
            }
        }

        return $result;
    }

    /**
     * Recursively validate request body fields against schema.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $componentsSchemas
     * @return list<string>
     */
    protected function validateBodyRecursively(
        mixed $data,
        array $schema,
        array $componentsSchemas,
        string $currentPath,
        int $stepNum,
        string $stepName,
        string $method,
        string $path
    ): array {
        $resolvedSchema = $this->resolveSchema($schema, $componentsSchemas);
        $resolvedSchema = $this->flattenAllOf($resolvedSchema, $componentsSchemas);

        // 1. Handle oneOf: data must match exactly one branch
        if (isset($resolvedSchema['oneOf']) && is_array($resolvedSchema['oneOf']) && $resolvedSchema['oneOf'] !== []) {
            /** @var list<list<string>> $candidateErrors */
            $candidateErrors = [];
            $matchingCount = 0;

            foreach ($resolvedSchema['oneOf'] as $branch) {
                if (! is_array($branch)) {
                    continue;
                }
                $resolvedBranch = $this->resolveSchema($branch, $componentsSchemas);
                $resolvedBranch = $this->flattenAllOf($resolvedBranch, $componentsSchemas);

                $branchSchema = $resolvedBranch;
                if (isset($resolvedSchema['properties']) && is_array($resolvedSchema['properties'])) {
                    $branchProps = isset($resolvedBranch['properties']) && is_array($resolvedBranch['properties'])
                        ? $resolvedBranch['properties']
                        : [];
                    $branchSchema['properties'] = array_merge(
                        $resolvedSchema['properties'],
                        $branchProps
                    );
                }

                $errors = $this->validateBodyRecursively(
                    $data,
                    $branchSchema,
                    $componentsSchemas,
                    $currentPath,
                    $stepNum,
                    $stepName,
                    $method,
                    $path
                );

                if ($errors === []) {
                    $matchingCount++;
                } else {
                    $candidateErrors[] = $errors;
                }
            }

            if ($matchingCount === 1) {
                return [];
            }

            if ($matchingCount > 1) {
                return ["Bước {$stepNum} ('{$stepName}'): Dữ liệu tại '{$currentPath}' khớp {$matchingCount} schema trong 'oneOf', nhưng phải khớp đúng 1."];
            }

            usort($candidateErrors, fn (array $a, array $b): int => count($a) <=> count($b));

            return $candidateErrors[0] ?? [];
        }

        // 2. Handle anyOf: data must match at least one branch
        if (isset($resolvedSchema['anyOf']) && is_array($resolvedSchema['anyOf']) && $resolvedSchema['anyOf'] !== []) {
            /** @var list<list<string>> $candidateErrors */
            $candidateErrors = [];

            foreach ($resolvedSchema['anyOf'] as $branch) {
                if (! is_array($branch)) {
                    continue;
                }
                $resolvedBranch = $this->resolveSchema($branch, $componentsSchemas);
                $resolvedBranch = $this->flattenAllOf($resolvedBranch, $componentsSchemas);

                $branchSchema = $resolvedBranch;
                if (isset($resolvedSchema['properties']) && is_array($resolvedSchema['properties'])) {
                    $branchProps = isset($resolvedBranch['properties']) && is_array($resolvedBranch['properties'])
                        ? $resolvedBranch['properties']
                        : [];
                    $branchSchema['properties'] = array_merge(
                        $resolvedSchema['properties'],
                        $branchProps
                    );
                }

                $errors = $this->validateBodyRecursively(
                    $data,
                    $branchSchema,
                    $componentsSchemas,
                    $currentPath,
                    $stepNum,
                    $stepName,
                    $method,
                    $path
                );

                if ($errors === []) {
                    return [];
                }

                $candidateErrors[] = $errors;
            }

            usort($candidateErrors, fn (array $a, array $b): int => count($a) <=> count($b));

            return $candidateErrors[0] ?? [];
        }

        $errors = [];

        // 3. Object validation
        $isObject = is_object($data)
            || (is_array($data) && ! array_is_list($data))
            || (is_array($data) && (isset($resolvedSchema['properties']) || ($resolvedSchema['type'] ?? '') === 'object') && ! isset($resolvedSchema['items']));

        if ($isObject) {
            $hasProperties = isset($resolvedSchema['properties']) && is_array($resolvedSchema['properties']);
            $hasAdditionalProperties = isset($resolvedSchema['additionalProperties']);

            if ($hasProperties || $hasAdditionalProperties) {
                $allowedProps = $hasProperties ? array_map('strval', array_keys($resolvedSchema['properties'])) : [];
                $additionalPropsSchema = $hasAdditionalProperties ? $resolvedSchema['additionalProperties'] : null;

                $dataProps = is_object($data) ? get_object_vars($data) : (array) $data;

                foreach ($dataProps as $key => $val) {
                    $propKey = (string) $key;
                    $fieldDisplay = $currentPath === '' ? $propKey : "{$currentPath}.{$propKey}";

                    if (in_array($propKey, $allowedProps, true)) {
                        if ((is_array($val) || is_object($val)) && isset($resolvedSchema['properties'][$propKey]) && is_array($resolvedSchema['properties'][$propKey])) {
                            $subErrors = $this->validateBodyRecursively(
                                $val,
                                $resolvedSchema['properties'][$propKey],
                                $componentsSchemas,
                                $fieldDisplay,
                                $stepNum,
                                $stepName,
                                $method,
                                $path
                            );
                            if ($subErrors !== []) {
                                $errors = array_merge($errors, $subErrors);
                            }
                        }
                    } else {
                        // Key not declared in properties
                        if ($additionalPropsSchema === false) {
                            $errors[] = "Bước {$stepNum} ('{$stepName}'): Trường '{$fieldDisplay}' trong request body không tồn tại trong schema của endpoint '{$method} {$path}'.";
                        } elseif ($additionalPropsSchema === true) {
                            // Explicitly allowed without extra schema validation
                        } elseif (is_array($additionalPropsSchema)) {
                            // Map schema: value must conform to additionalProperties schema
                            if (is_array($val) || is_object($val)) {
                                $resolvedAdditional = $this->resolveSchema($additionalPropsSchema, $componentsSchemas);
                                $subErrors = $this->validateBodyRecursively(
                                    $val,
                                    $resolvedAdditional,
                                    $componentsSchemas,
                                    $fieldDisplay,
                                    $stepNum,
                                    $stepName,
                                    $method,
                                    $path
                                );
                                if ($subErrors !== []) {
                                    $errors = array_merge($errors, $subErrors);
                                }
                            }
                        } else {
                            // additionalProperties not defined: if properties is defined, reject undeclared property
                            if ($hasProperties) {
                                $errors[] = "Bước {$stepNum} ('{$stepName}'): Trường '{$fieldDisplay}' trong request body không tồn tại trong schema của endpoint '{$method} {$path}'.";
                            }
                        }
                    }
                }
            }
        } elseif (is_array($data) && array_is_list($data)) {
            // 4. Array items validation
            $itemSchema = null;
            if (isset($resolvedSchema['items']) && is_array($resolvedSchema['items'])) {
                $itemSchema = $this->resolveSchema($resolvedSchema['items'], $componentsSchemas);
            }

            if ($itemSchema !== null) {
                foreach ($data as $index => $item) {
                    $itemPath = $currentPath === '' ? "[{$index}]" : "{$currentPath}[{$index}]";
                    if (is_array($item) || is_object($item)) {
                        $subErrors = $this->validateBodyRecursively(
                            $item,
                            $itemSchema,
                            $componentsSchemas,
                            $itemPath,
                            $stepNum,
                            $stepName,
                            $method,
                            $path
                        );
                        if ($subErrors !== []) {
                            $errors = array_merge($errors, $subErrors);
                        }
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Normalize OpenAPI spec input into endpoint lookup map.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function buildEndpointMap(mixed $spec): array
    {
        $map = [];

        if ($spec instanceof ParsedOpenApi) {
            foreach ($spec->endpoints as $ep) {
                $key = strtoupper($ep->method->value).':'.$ep->path;
                $map[$key] = $ep->toArray();
            }

            return $map;
        }

        if ($spec instanceof ApiSpec) {
            /** @var Collection<int, Endpoint> $endpoints */
            $endpoints = $spec->relationLoaded('endpoints') ? $spec->endpoints : $spec->endpoints()->get();
            foreach ($endpoints as $ep) {
                $key = strtoupper($ep->method->value).':'.$ep->path;
                $map[$key] = [
                    'method' => $ep->method->value,
                    'path' => $ep->path,
                    'parameters' => $ep->parameters,
                    'request_body' => $ep->request_body,
                    'responses' => $ep->responses,
                    'security' => $ep->security,
                ];
            }

            return $map;
        }

        if ($spec instanceof Endpoint) {
            $key = strtoupper($spec->method->value).':'.$spec->path;
            $map[$key] = [
                'method' => $spec->method->value,
                'path' => $spec->path,
                'parameters' => $spec->parameters,
                'request_body' => $spec->request_body,
                'responses' => $spec->responses,
                'security' => $spec->security,
            ];

            return $map;
        }

        if ($spec instanceof ParsedEndpoint) {
            $key = strtoupper($spec->method->value).':'.$spec->path;
            $map[$key] = $spec->toArray();

            return $map;
        }

        if ($spec instanceof Collection) {
            foreach ($spec as $ep) {
                if ($ep instanceof Endpoint) {
                    $key = strtoupper($ep->method->value).':'.$ep->path;
                    $map[$key] = [
                        'method' => $ep->method->value,
                        'path' => $ep->path,
                        'parameters' => $ep->parameters,
                        'request_body' => $ep->request_body,
                        'responses' => $ep->responses,
                        'security' => $ep->security,
                    ];
                } elseif ($ep instanceof ParsedEndpoint) {
                    $key = strtoupper($ep->method->value).':'.$ep->path;
                    $map[$key] = $ep->toArray();
                }
            }

            return $map;
        }

        if (is_array($spec)) {
            // Support raw OpenAPI array with 'paths' dictionary
            if (isset($spec['paths']) && is_array($spec['paths'])) {
                foreach ($spec['paths'] as $path => $methods) {
                    if (! is_array($methods)) {
                        continue;
                    }
                    /** @var list<array<string, mixed>> $pathParams */
                    $pathParams = isset($methods['parameters']) && is_array($methods['parameters']) ? $methods['parameters'] : [];
                    foreach ($methods as $method => $op) {
                        if (! in_array(strtolower((string) $method), ['get', 'post', 'put', 'patch', 'delete', 'options', 'head'], true)) {
                            continue;
                        }
                        if (is_array($op)) {
                            $mapKey = strtoupper((string) $method).':'.(string) $path;
                            /** @var list<array<string, mixed>> $opParams */
                            $opParams = isset($op['parameters']) && is_array($op['parameters']) ? $op['parameters'] : [];
                            $map[$mapKey] = [
                                'method' => strtoupper((string) $method),
                                'path' => (string) $path,
                                'parameters' => array_merge($pathParams, $opParams),
                                'request_body' => $op['requestBody'] ?? $op['request_body'] ?? null,
                                'responses' => isset($op['responses']) && is_array($op['responses']) ? $op['responses'] : [],
                                'security' => $op['security'] ?? [],
                            ];
                        }
                    }
                }
            }

            $endpointsList = isset($spec['endpoints']) && is_array($spec['endpoints']) ? $spec['endpoints'] : $spec;
            foreach ($endpointsList as $key => $val) {
                if ($val instanceof ParsedEndpoint) {
                    $mapKey = strtoupper($val->method->value).':'.$val->path;
                    $map[$mapKey] = $val->toArray();
                } elseif ($val instanceof Endpoint) {
                    $mapKey = strtoupper($val->method->value).':'.$val->path;
                    $map[$mapKey] = [
                        'method' => $val->method->value,
                        'path' => $val->path,
                        'parameters' => $val->parameters,
                        'request_body' => $val->request_body,
                        'responses' => $val->responses,
                        'security' => $val->security,
                    ];
                } elseif (is_array($val) && isset($val['method'], $val['path'])) {
                    $mapKey = strtoupper((string) $val['method']).':'.(string) $val['path'];
                    $map[$mapKey] = $val;
                }
            }
        }

        return $map;
    }

    /**
     * Extract components schemas from OpenAPI spec.
     *
     * @return array<string, mixed>
     */
    protected function extractComponentsSchemas(mixed $spec): array
    {
        if ($spec instanceof ParsedOpenApi) {
            return $this->extractSchemasFromContainer($spec->normalized);
        }

        if ($spec instanceof ApiSpec) {
            return $this->extractSchemasFromContainer($spec->normalized);
        }

        if ($spec instanceof Endpoint) {
            /** @var mixed $apiSpec */
            $apiSpec = null;
            if ($spec->relationLoaded('apiSpec')) {
                $apiSpec = $spec->getRelation('apiSpec');
            } elseif (array_key_exists('apiSpec', $spec->getAttributes())) {
                $apiSpec = $spec->getAttribute('apiSpec');
            } elseif ($spec->getConnectionResolver() !== null) {
                $apiSpec = rescue(static fn () => $spec->apiSpec, null, false);
            }

            if ($apiSpec instanceof ApiSpec && is_array($apiSpec->normalized)) {
                return $apiSpec->normalized['components']['schemas'] ?? [];
            }
        }

        if ($spec instanceof Collection) {
            $first = $spec->first();
            if ($first instanceof Endpoint) {
                /** @var mixed $apiSpec */
                $apiSpec = null;
                if ($first->relationLoaded('apiSpec')) {
                    $apiSpec = $first->getRelation('apiSpec');
                } elseif (array_key_exists('apiSpec', $first->getAttributes())) {
                    $apiSpec = $first->getAttribute('apiSpec');
                } elseif ($first->getConnectionResolver() !== null) {
                    $apiSpec = rescue(static fn () => $first->apiSpec, null, false);
                }

                if ($apiSpec instanceof ApiSpec) {
                    return $this->extractSchemasFromContainer($apiSpec->normalized);
                }
            }

            return [];
        }

        if (is_array($spec) && isset($spec['normalized'])) {
            return $this->extractSchemasFromContainer($spec['normalized']);
        }

        return $this->extractSchemasFromContainer($spec);
    }

    /**
     * @return array<string, mixed>
     */
    protected function extractSchemasFromContainer(mixed $container): array
    {
        if (is_array($container)) {
            $components = $container['components'] ?? null;
            if (is_array($components) && isset($components['schemas'])) {
                $schemas = $components['schemas'];

                return is_array($schemas) ? $schemas : (is_object($schemas) ? get_object_vars($schemas) : []);
            }
            if (is_object($components) && isset($components->schemas)) {
                $schemas = $components->schemas;

                return is_array($schemas) ? $schemas : (is_object($schemas) ? get_object_vars($schemas) : []);
            }
            if (isset($container['schemas'])) {
                $schemas = $container['schemas'];

                return is_array($schemas) ? $schemas : (is_object($schemas) ? get_object_vars($schemas) : []);
            }
        } elseif (is_object($container)) {
            $components = $container->components ?? null;
            if (is_object($components) && isset($components->schemas)) {
                $schemas = $components->schemas;

                return is_array($schemas) ? $schemas : (is_object($schemas) ? get_object_vars($schemas) : []);
            }
            if (is_array($components) && isset($components['schemas'])) {
                $schemas = $components['schemas'];

                return is_array($schemas) ? $schemas : (is_object($schemas) ? get_object_vars($schemas) : []);
            }
            if (isset($container->schemas)) {
                $schemas = $container->schemas;

                return is_array($schemas) ? $schemas : (is_object($schemas) ? get_object_vars($schemas) : []);
            }
        }

        return [];
    }

    /**
     * Kiểm tra tính hợp lệ của cấu trúc JSON Schema inline bằng meta-schema.
     * Trả về thông báo lỗi nếu không hợp lệ, hoặc null nếu hợp lệ.
     */
    protected function validateJsonSchemaSyntax(mixed $schema, string $path = '$'): ?string
    {
        if (is_bool($schema)) {
            return null;
        }

        if (is_array($schema)) {
            if ($schema === []) {
                return null;
            }
            if (array_is_list($schema)) {
                return "Giá trị schema tại '{$path}' phải là một JSON object (hoặc boolean).";
            }
            $schemaObj = json_decode((string) json_encode($schema), false);
        } elseif (is_object($schema)) {
            // Deep copy: the meta-schema validation fills in `default` values (readOnly, properties...).
            $schemaObj = json_decode((string) json_encode($schema), false);
        } else {
            return "Giá trị schema tại '{$path}' phải là một JSON object (hoặc boolean).";
        }

        if (! is_object($schemaObj)) {
            return "Giá trị schema tại '{$path}' phải là một JSON object (hoặc boolean).";
        }

        $metaSchemaUri = 'https://json-schema.org/draft/2020-12/schema';
        if (isset($schemaObj->{'$schema'}) && is_string($schemaObj->{'$schema'})) {
            $declared = $schemaObj->{'$schema'};
            if (str_contains($declared, 'draft-07')) {
                $metaSchemaUri = 'http://json-schema.org/draft-07/schema#';
            }
        }

        $result = $this->jsonSchemaValidator->validate($schemaObj, $metaSchemaUri);

        if (! $result->isValid()) {
            $error = $result->error();
            if ($error !== null) {
                // Vietnamese messages with the location inside the schema, not the opis English text.
                return implode('; ', (new DslSchemaErrorFormatter('gốc schema', '$'))->format($error));
            }

            return 'Cấu trúc schema không hợp lệ theo meta-schema.';
        }

        return null;
    }

    /**
     * Register standard JSON Schema Draft 2020-12 and Draft-07 meta-schemas into Opis resolver.
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
            $resolver->registerFile('https://json-schema.org/draft/2020-12/meta/meta-data', "{$dir2020}/vocabularies/meta-data");
            $resolver->registerFile('https://json-schema.org/draft/2020-12/meta/format-annotation', "{$dir2020}/vocabularies/format-annotation");
            $resolver->registerFile('https://json-schema.org/draft/2020-12/meta/content', "{$dir2020}/vocabularies/content");
        }

        $resourceDir7 = null;
        try {
            if (function_exists('resource_path')) {
                $resourceDir7 = resource_path('schemas/draft-07');
            }
        } catch (\Throwable) {
            $resourceDir7 = null;
        }

        $candidates7 = [
            $resourceDir7,
            dirname(__DIR__, 3).'/resources/schemas/draft-07',
            '/var/www/html/resources/schemas/draft-07',
        ];

        $dir7 = null;
        foreach ($candidates7 as $candidate) {
            if ($candidate !== null && is_dir($candidate)) {
                $dir7 = $candidate;
                break;
            }
        }

        if ($dir7 !== null) {
            $resolver->registerFile('http://json-schema.org/draft-07/schema#', "{$dir7}/metaschema.json");
            $resolver->registerFile('https://json-schema.org/draft-07/schema#', "{$dir7}/metaschema.json");
        }
    }
}
