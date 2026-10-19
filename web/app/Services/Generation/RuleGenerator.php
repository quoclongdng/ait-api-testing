<?php

namespace App\Services\Generation;

use App\Data\Experiments\AuthFixture;
use App\Data\OpenApi\ParsedEndpoint;
use App\Data\OpenApi\ParsedOpenApi;
use App\Enums\TestCaseSource;
use App\Enums\TestTechnique;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Services\Support\NumericRationalHelper;
use App\Support\OpenApiSecurity;
use Illuminate\Support\Collection;
use Opis\JsonSchema\Exceptions\SchemaException;
use Opis\JsonSchema\Validator;

/**
 * Service to generate Test DSL cases based on black-box test design techniques:
 * HAPPY_PATH, MISSING_REQUIRED, TYPE_MISMATCH, BOUNDARY, EQUIVALENCE, INVALID_INPUT.
 *
 * Implements docs/DESIGN.md §0 Conventional Commits, §3 Architecture, and Sprint 3 (S3-01).
 */
class RuleGenerator
{
    use NumericRationalHelper;

    /**
     * Supported techniques for rule-based generation in S3-01.
     *
     * @var list<TestTechnique>
     */
    public const SUPPORTED_TECHNIQUES = [
        TestTechnique::HappyPath,
        TestTechnique::MissingRequired,
        TestTechnique::TypeMismatch,
        TestTechnique::Boundary,
        TestTechnique::Equivalence,
        TestTechnique::InvalidInput,
    ];

    /**
     * Counter for generating unique values across test cases to prevent unique-constraint collision.
     */
    protected int $uniqueCounter = 100;

    /**
     * Well-formed bearer credential no sandbox accepts (AUTH case "invalid token", ADR-0006).
     */
    public const INVALID_BEARER = 'Bearer ait-rule-invalid-credential';

    /**
     * Whether the operation being generated may write a unique-looking field (code, slug, sku,
     * email): POST that declares 201 / 2XX, PUT or PATCH. For such operations a declared example
     * would collide with existing data, so a fresh value is generated; for every other operation
     * (lookups such as POST /coupons/apply, GET filters, DELETE) the declared example is honoured.
     */
    protected bool $operationWritesUniqueFields = true;

    public function resetUniqueCounter(): void
    {
        $this->uniqueCounter = 100;
    }

    /**
     * Records the operation context used by generateValidValueForSchema().
     *
     * @param  array<string, mixed>  $endpointData  normalized endpoint
     */
    protected function enterOperation(array $endpointData): void
    {
        $this->operationWritesUniqueFields = $this->operationWritesUniqueFields($endpointData);
    }

    /**
     * @param  array<string, mixed>  $endpointData  normalized endpoint
     */
    public function operationWritesUniqueFields(array $endpointData): bool
    {
        $method = strtoupper((string) ($endpointData['method'] ?? 'GET'));
        if ($method === 'PUT' || $method === 'PATCH') {
            return true;
        }
        if ($method !== 'POST') {
            return false;
        }
        $responses = is_array($endpointData['responses'] ?? null) ? $endpointData['responses'] : [];
        $declared = array_map(fn ($k): string => strtoupper((string) $k), array_keys($responses));

        return in_array('201', $declared, true) || in_array('2XX', $declared, true);
    }

    /**
     * Generate test cases for a single endpoint.
     *
     * @param  Endpoint|ParsedEndpoint|array<string, mixed>  $endpoint
     * @param  list<TestTechnique|string>  $techniques
     * @param  array<string, mixed>  $componentsSchemas
     * @return list<array<string, mixed>>
     */
    public function generate(
        Endpoint|ParsedEndpoint|array $endpoint,
        array $techniques = [],
        array $componentsSchemas = [],
        ?AuthFixture $auth = null,
    ): array {
        $endpointData = $this->normalizeEndpoint($endpoint);
        $schemas = $this->resolveComponentsSchemas($endpoint, $componentsSchemas);
        $selectedTechniques = $this->resolveTechniques($techniques);

        // Without a credential source (ordinary M11 job: RULE reads only the spec) every oracle of a
        // secured endpoint (2xx, 400/422, 404) would meet a 401 and FAIL on the clean variant,
        // inflating the False Positive Rate. Such endpoints only get the oracle the spec supports
        // without a credential: the request without Authorization is rejected with 401.
        if ($endpointData['requires_auth'] && $auth === null) {
            return $selectedTechniques === [] ? [] : $this->generateMissingCredentials($endpointData, $schemas);
        }

        $testCases = [];

        foreach ($selectedTechniques as $technique) {
            $cases = match ($technique) {
                TestTechnique::HappyPath => $this->generateHappyPath($endpointData, $schemas),
                TestTechnique::MissingRequired => $this->generateMissingRequired($endpointData, $schemas),
                TestTechnique::TypeMismatch => $this->generateTypeMismatch($endpointData, $schemas),
                TestTechnique::Boundary => $this->generateBoundary($endpointData, $schemas),
                TestTechnique::Equivalence => $this->generateEquivalence($endpointData, $schemas),
                TestTechnique::InvalidInput => $this->generateInvalidInput($endpointData, $schemas),
                default => [],
            };

            foreach ($cases as $case) {
                $testCases[] = $case;
            }
        }

        // ADR-0006: with the experiment's auth fixture the executor sends the default credential to
        // every non-AUTH case above; AUTH cases check the credential handling itself.
        if ($endpointData['requires_auth'] && $selectedTechniques !== []) {
            foreach ($this->generateAuthCases($endpointData, $schemas, $auth) as $case) {
                $testCases[] = $case;
            }
        }

        return $testCases;
    }

    /**
     * AUTH cases of a secured endpoint when the experiment declares credentials (ADR-0006):
     * - no credential, and an invalid bearer token: rejected with the documented 401;
     * - each role the fixture lists in `forbidden_roles` for the operation: rejected with the
     *   documented 403 (the header references `{{auth_token_<role>}}`, resolved by the executor).
     * AUTH cases set (or omit) Authorization themselves; the executor never injects into them.
     *
     * @param  array<string, mixed>  $endpointData
     * @param  array<string, mixed>  $componentsSchemas
     * @return list<array<string, mixed>>
     */
    public function generateAuthCases(Endpoint|ParsedEndpoint|array $endpointData, array $componentsSchemas, AuthFixture $auth): array
    {
        $endpointData = $this->normalizeEndpoint($endpointData);
        $this->enterOperation($endpointData);
        $declared = array_map(fn (int|string $code): string => (string) $code, array_keys($endpointData['responses']));
        $method = $endpointData['method'];
        $path = $endpointData['path'];

        $cases = $this->generateMissingCredentials($endpointData, $componentsSchemas);
        if (! in_array('401', $declared, true) && ! in_array('403', $declared, true)) {
            return $cases;
        }

        $baseHeaders = array_filter(
            $this->buildValidHeaders($endpointData['parameters'] ?? [], $componentsSchemas),
            static fn (mixed $name): bool => strcasecmp((string) $name, AuthFixture::HEADER) !== 0,
            ARRAY_FILTER_USE_KEY,
        );
        $requestData = [
            'path_params' => $this->buildValidPathParams($path, $endpointData['parameters'] ?? [], $componentsSchemas),
            'query' => $this->buildValidQueryParams($endpointData['parameters'] ?? [], $componentsSchemas),
            'headers' => $baseHeaders,
            'body' => $this->buildValidBody($endpointData['request_body'] ?? null, $componentsSchemas),
            'has_body' => $endpointData['has_request_body'],
        ];

        if (in_array('401', $declared, true)) {
            $cases[] = $this->buildDslDocument(
                method: $method,
                path: $path,
                title: "Xác thực: {$method} {$path} với bearer token không hợp lệ bị từ chối",
                description: "Yêu cầu hợp lệ kèm bearer token mà sandbox không biết, gửi tới endpoint có bảo mật {$method} {$path}, phải bị từ chối với mã 401.",
                technique: TestTechnique::Auth,
                requestData: ['headers' => $baseHeaders + [AuthFixture::HEADER => self::INVALID_BEARER]] + $requestData,
                expectedStatus: 401,
            );
        }

        if (in_array('403', $declared, true)) {
            foreach ($auth->forbiddenRolesFor($method, $path) as $role) {
                $cases[] = $this->buildDslDocument(
                    method: $method,
                    path: $path,
                    title: "Xác thực: {$method} {$path} với vai trò '{$role}' bị cấm truy cập",
                    description: "Thao tác {$method} {$path} không được phép với vai trò {$role} (fixture xác thực của benchmark): yêu cầu hợp lệ kèm token của vai trò đó phải bị từ chối với mã 403.",
                    technique: TestTechnique::Auth,
                    requestData: ['headers' => $baseHeaders + [AuthFixture::HEADER => AuthFixture::headerValue($role)]] + $requestData,
                    expectedStatus: 403,
                );
            }
        }

        return $cases;
    }

    /**
     * Generate test cases for all endpoints in a spec or collection.
     *
     * @param  ApiSpec|ParsedOpenApi|Collection<int, Endpoint>|list<ParsedEndpoint>|array<string, mixed>  $spec
     * @param  list<TestTechnique|string>  $techniques
     * @return list<array<string, mixed>>
     */
    public function generateForSpec(mixed $spec, array $techniques = []): array
    {
        $endpoints = [];
        $componentsSchemas = [];

        if ($spec instanceof ParsedOpenApi) {
            $endpoints = $spec->endpoints;
            $normalized = $spec->normalized ?? [];
            $componentsSchemas = $normalized['components']['schemas'] ?? [];
        } elseif ($spec instanceof ApiSpec) {
            $endpoints = $spec->relationLoaded('endpoints') ? $spec->endpoints->sortBy('id')->values() : $spec->endpoints()->orderBy('id')->get();
            $normalized = $spec->normalized ?? [];
            if (isset($normalized['components']['schemas']) && is_array($normalized['components']['schemas'])) {
                $componentsSchemas = $normalized['components']['schemas'];
            }
        } elseif ($spec instanceof Collection) {
            $endpoints = $spec->all();
            $first = $spec->first();
            if ($first instanceof Endpoint && $first->relationLoaded('apiSpec')) {
                $normalized = $first->apiSpec->normalized ?? [];
                if (isset($normalized['components']['schemas']) && is_array($normalized['components']['schemas'])) {
                    $componentsSchemas = $normalized['components']['schemas'];
                }
            }
        } elseif (is_array($spec)) {
            if (isset($spec['endpoints']) && is_array($spec['endpoints'])) {
                $endpoints = $spec['endpoints'];
                $componentsSchemas = $spec['normalized']['components']['schemas'] ?? $spec['components']['schemas'] ?? [];
            } else {
                $endpoints = $spec;
            }
        }

        $allTestCases = [];

        foreach ($endpoints as $ep) {
            $epCases = $this->generate($ep, $techniques, $componentsSchemas);
            foreach ($epCases as $c) {
                $allTestCases[] = $c;
            }
        }

        return $allTestCases;
    }

    /**
     * 1. HAPPY_PATH: Valid request with all required & optional fields conforming to schema.
     *
     * @param  array<string, mixed>  $endpointData
     * @param  array<string, mixed>  $componentsSchemas
     * @return list<array<string, mixed>>
     */
    public function generateHappyPath(Endpoint|ParsedEndpoint|array $endpointData, array $componentsSchemas = []): array
    {
        $endpointData = $this->normalizeEndpoint($endpointData);
        $this->enterOperation($endpointData);
        $status = $this->getSuccessStatusCode($endpointData);
        if ($status === null) {
            return [];
        }
        $method = $endpointData['method'];
        $path = $endpointData['path'];

        $pathParams = $this->buildValidPathParams($path, $endpointData['parameters'] ?? [], $componentsSchemas);
        $queryParams = $this->buildValidQueryParams($endpointData['parameters'] ?? [], $componentsSchemas);
        $headers = $this->buildValidHeaders($endpointData['parameters'] ?? [], $componentsSchemas);
        $body = $this->buildValidBody($endpointData['request_body'] ?? null, $componentsSchemas);

        $requestData = [
            'path_params' => $pathParams,
            'query' => $queryParams,
            'headers' => $headers,
            'body' => $body,
            'has_body' => $endpointData['has_request_body'],
        ];

        // Success oracle: prove the entire request conforms to endpoint schema definitions
        if (! $this->isRequestConformingToEndpoint($requestData, $endpointData, $componentsSchemas)) {
            return [];
        }

        $case = $this->buildDslDocument(
            method: $method,
            path: $path,
            title: "Luồng chuẩn: {$method} {$path} yêu cầu hợp lệ thành công",
            description: "Yêu cầu chuẩn hợp lệ với tham số và body đúng đặc tả cho {$method} {$path}.",
            technique: TestTechnique::HappyPath,
            requestData: $requestData,
            expectedStatus: $status,
            extraAssertions: $this->responseSchemaAssertions($endpointData, $status)
        );

        return [$case];
    }

    /**
     * AUTH: a schema-conforming request sent without credentials to an endpoint whose effective
     * security requires one must be rejected with the documented 401. Skipped when the spec
     * documents no 401 response (no reliable oracle).
     *
     * @param  array<string, mixed>  $endpointData
     * @param  array<string, mixed>  $componentsSchemas
     * @return list<array<string, mixed>>
     */
    public function generateMissingCredentials(Endpoint|ParsedEndpoint|array $endpointData, array $componentsSchemas = []): array
    {
        $endpointData = $this->normalizeEndpoint($endpointData);
        $this->enterOperation($endpointData);
        $declared = array_map(fn (int|string $code): string => (string) $code, array_keys($endpointData['responses']));
        if (! in_array('401', $declared, true)) {
            return [];
        }
        $method = $endpointData['method'];
        $path = $endpointData['path'];

        $headers = array_filter(
            $this->buildValidHeaders($endpointData['parameters'] ?? [], $componentsSchemas),
            static fn (mixed $name): bool => strcasecmp((string) $name, 'Authorization') !== 0,
            ARRAY_FILTER_USE_KEY,
        );
        $requestData = [
            'path_params' => $this->buildValidPathParams($path, $endpointData['parameters'] ?? [], $componentsSchemas),
            'query' => $this->buildValidQueryParams($endpointData['parameters'] ?? [], $componentsSchemas),
            'headers' => $headers,
            'body' => $this->buildValidBody($endpointData['request_body'] ?? null, $componentsSchemas),
            'has_body' => $endpointData['has_request_body'],
        ];

        return [$this->buildDslDocument(
            method: $method,
            path: $path,
            title: "Xác thực: {$method} {$path} không kèm thông tin xác thực bị từ chối",
            description: "Yêu cầu hợp lệ gửi không kèm thông tin xác thực tới endpoint có bảo mật {$method} {$path} phải bị từ chối với mã 401.",
            technique: TestTechnique::Auth,
            requestData: $requestData,
            expectedStatus: 401,
        )];
    }

    /**
     * json_schema oracle for the documented JSON body of the success response (inline, `$ref`s
     * already expanded by the parser). Skipped when there is no JSON schema or it still holds a
     * `$ref` the inline validator could not resolve.
     *
     * @param  array<string, mixed>  $endpointData
     * @return list<array<string, mixed>>
     */
    protected function responseSchemaAssertions(array $endpointData, int $status): array
    {
        $response = $endpointData['responses'][(string) $status] ?? $endpointData['responses'][$status] ?? null;
        $schema = is_array($response) ? ($response['content']['application/json']['schema'] ?? null) : null;
        if (! is_array($schema) || $schema === [] || $this->containsRef($schema)) {
            return [];
        }

        return [['type' => 'json_schema', 'schema' => $schema]];
    }

    /**
     * @param  array<array-key, mixed>  $node
     */
    private function containsRef(array $node): bool
    {
        foreach ($node as $key => $value) {
            if ($key === '$ref' || (is_array($value) && $this->containsRef($value))) {
                return true;
            }
        }

        return false;
    }

    /**
     * 2. MISSING_REQUIRED: Omit required fields one by one to verify rejection (422/400).
     *
     * @param  array<string, mixed>  $endpointData
     * @param  array<string, mixed>  $componentsSchemas
     * @return list<array<string, mixed>>
     */
    public function generateMissingRequired(Endpoint|ParsedEndpoint|array $endpointData, array $componentsSchemas = []): array
    {
        $endpointData = $this->normalizeEndpoint($endpointData);
        $this->enterOperation($endpointData);
        $cases = [];
        $method = $endpointData['method'];
        $path = $endpointData['path'];
        $errorStatus = $this->getErrorStatusCode($endpointData);
        if ($errorStatus === null) {
            return [];
        }

        $basePathParams = $this->buildValidPathParams($path, $endpointData['parameters'] ?? [], $componentsSchemas);
        $baseQueryParams = $this->buildValidQueryParams($endpointData['parameters'] ?? [], $componentsSchemas);
        $baseHeaders = $this->buildValidHeaders($endpointData['parameters'] ?? [], $componentsSchemas);
        $baseBody = $this->buildValidBody($endpointData['request_body'] ?? null, $componentsSchemas);

        // Case A: Missing required fields in request body
        $bodySchema = $this->extractBodySchema($endpointData['request_body'] ?? null, $componentsSchemas);
        if ($bodySchema !== null && (is_array($baseBody) || is_object($baseBody))) {
            $baseBodyArray = (array) $baseBody;
            $requiredFields = isset($bodySchema['required']) && is_array($bodySchema['required'])
                ? array_map('strval', $bodySchema['required'])
                : [];

            foreach ($requiredFields as $field) {
                if (! array_key_exists($field, $baseBodyArray)) {
                    continue;
                }

                $omittedBody = $baseBodyArray;
                unset($omittedBody[$field]);
                if ($omittedBody === []) {
                    $omittedBody = (object) [];
                }

                $cases[] = $this->buildDslDocument(
                    method: $method,
                    path: $path,
                    title: "Thiếu trường bắt buộc '{$field}' trong {$method} {$path}",
                    description: "Kiểm tra bỏ trường bắt buộc '{$field}' khỏi body trả về lỗi 4xx.",
                    technique: TestTechnique::MissingRequired,
                    requestData: [
                        'path_params' => $basePathParams,
                        'query' => $baseQueryParams,
                        'headers' => $baseHeaders,
                        'body' => $omittedBody,
                        'has_body' => true,
                    ],
                    expectedStatus: $errorStatus
                );
            }
        }

        // Case B: Missing required query parameters
        $parameters = $endpointData['parameters'];
        if ($parameters !== null) {
            $seenQueryParams = [];
            foreach ($parameters as $param) {
                $in = $param['in'] ?? null;
                $name = (string) ($param['name'] ?? '');
                $isRequired = (bool) ($param['required'] ?? false);

                if ($in === 'query' && $isRequired && $name !== '') {
                    if (isset($seenQueryParams[$name])) {
                        continue;
                    }
                    $seenQueryParams[$name] = true;

                    $omittedQuery = $baseQueryParams;
                    unset($omittedQuery[$name]);

                    $cases[] = $this->buildDslDocument(
                        method: $method,
                        path: $path,
                        title: "Thiếu tham số query bắt buộc '{$name}' trong {$method} {$path}",
                        description: "Kiểm tra bỏ tham số query bắt buộc '{$name}' trả về lỗi 4xx.",
                        technique: TestTechnique::MissingRequired,
                        requestData: [
                            'path_params' => $basePathParams,
                            'query' => $omittedQuery,
                            'headers' => $baseHeaders,
                            'body' => $baseBody,
                            'has_body' => $endpointData['has_request_body'],
                        ],
                        expectedStatus: $errorStatus
                    );
                }
            }
        }

        // Case C: Missing required headers
        if ($parameters !== null) {
            $seenHeaders = [];
            foreach ($parameters as $param) {
                $in = $param['in'] ?? null;
                $name = (string) ($param['name'] ?? '');
                $isRequired = (bool) ($param['required'] ?? false);

                if ($in === 'header' && $isRequired && $name !== '') {
                    $lowerName = strtolower($name);
                    if (isset($seenHeaders[$lowerName])) {
                        continue;
                    }
                    $seenHeaders[$lowerName] = true;

                    $omittedHeaders = $baseHeaders;
                    unset($omittedHeaders[$name]);
                    foreach (array_keys($omittedHeaders) as $k) {
                        if (strcasecmp((string) $k, $name) === 0) {
                            unset($omittedHeaders[$k]);
                        }
                    }

                    $isContentType = strcasecmp($name, 'Content-Type') === 0;

                    $cases[] = $this->buildDslDocument(
                        method: $method,
                        path: $path,
                        title: "Thiếu header bắt buộc '{$name}' trong {$method} {$path}",
                        description: "Kiểm tra bỏ header bắt buộc '{$name}' trả về lỗi 4xx.",
                        technique: TestTechnique::MissingRequired,
                        requestData: [
                            'path_params' => $basePathParams,
                            'query' => $baseQueryParams,
                            'headers' => $omittedHeaders,
                            'body' => $baseBody,
                            'has_body' => $endpointData['has_request_body'],
                            'skip_auto_content_type' => $isContentType,
                        ],
                        expectedStatus: $errorStatus
                    );
                }
            }
        }

        return $cases;
    }

    /**
     * 3. TYPE_MISMATCH: Pass incompatible data types (e.g. string for int, int for string) to verify 4xx.
     *
     * @param  Endpoint|ParsedEndpoint|array<string, mixed>  $endpointData
     * @param  array<string, mixed>  $componentsSchemas
     * @return list<array<string, mixed>>
     */
    public function generateTypeMismatch(Endpoint|ParsedEndpoint|array $endpointData, array $componentsSchemas = []): array
    {
        $endpointData = $this->normalizeEndpoint($endpointData);
        $this->enterOperation($endpointData);
        $cases = [];
        $method = $endpointData['method'];
        $path = $endpointData['path'];
        $errorStatus = $this->getErrorStatusCode($endpointData);
        if ($errorStatus === null) {
            return [];
        }

        $basePathParams = $this->buildValidPathParams($path, $endpointData['parameters'] ?? [], $componentsSchemas);
        $baseQueryParams = $this->buildValidQueryParams($endpointData['parameters'] ?? [], $componentsSchemas);
        $baseHeaders = $this->buildValidHeaders($endpointData['parameters'] ?? [], $componentsSchemas);
        $baseBody = $this->buildValidBody($endpointData['request_body'] ?? null, $componentsSchemas);

        // A. Body properties type mismatch
        $bodySchema = $this->extractBodySchema($endpointData['request_body'] ?? null, $componentsSchemas);
        if ($bodySchema !== null) {
            $baseBodyArray = is_object($baseBody) ? (array) $baseBody : (is_array($baseBody) ? $baseBody : null);
            if ($baseBodyArray !== null && isset($bodySchema['properties']) && is_array($bodySchema['properties'])) {
                foreach ($bodySchema['properties'] as $propName => $propDef) {
                    if (! is_array($propDef)) {
                        continue;
                    }
                    $propStr = (string) $propName;
                    $resolvedProp = $this->resolveSchema($propDef, $componentsSchemas);
                    $expectedType = $this->extractSchemaType($resolvedProp);

                    $mismatchedValue = $this->generateMismatchedValue($resolvedProp, $componentsSchemas);
                    if ($mismatchedValue === null) {
                        continue;
                    }
                    if ($this->conformsToJsonSchema($mismatchedValue, $resolvedProp, $componentsSchemas) === true) {
                        continue;
                    }

                    $modifiedBody = $baseBodyArray;
                    $modifiedBody[$propStr] = $mismatchedValue;

                    $cases[] = $this->buildDslDocument(
                        method: $method,
                        path: $path,
                        title: "Sai kiểu dữ liệu của trường '{$propStr}' trong {$method} {$path}",
                        description: "Gửi sai kiểu dữ liệu cho trường '{$propStr}' (cần {$expectedType}) để kiểm tra yêu cầu bị từ chối.",
                        technique: TestTechnique::TypeMismatch,
                        requestData: [
                            'path_params' => $basePathParams,
                            'query' => $baseQueryParams,
                            'headers' => $baseHeaders,
                            'body' => $modifiedBody,
                            'has_body' => true,
                        ],
                        expectedStatus: $errorStatus
                    );
                }
            } elseif (! is_array($baseBody) && ! is_object($baseBody) && $baseBody !== null) {
                $expectedType = $this->extractSchemaType($bodySchema);
                $mismatchedValue = $this->generateMismatchedValue($bodySchema, $componentsSchemas);

                if ($mismatchedValue !== null && $this->conformsToJsonSchema($mismatchedValue, $bodySchema, $componentsSchemas) !== true) {
                    $cases[] = $this->buildDslDocument(
                        method: $method,
                        path: $path,
                        title: "Sai kiểu dữ liệu của body trong {$method} {$path}",
                        description: "Gửi body sai kiểu dữ liệu (cần {$expectedType}) để kiểm tra yêu cầu bị từ chối.",
                        technique: TestTechnique::TypeMismatch,
                        requestData: [
                            'path_params' => $basePathParams,
                            'query' => $baseQueryParams,
                            'headers' => $baseHeaders,
                            'body' => $mismatchedValue,
                            'has_body' => true,
                        ],
                        expectedStatus: $errorStatus
                    );
                }
            }
        }

        // B. Query parameters type mismatch
        $parameters = $endpointData['parameters'];
        if ($parameters !== null) {
            foreach ($parameters as $param) {
                if (($param['in'] ?? null) !== 'query') {
                    continue;
                }
                $name = (string) ($param['name'] ?? '');
                $paramSchema = isset($param['schema']) && is_array($param['schema']) ? $param['schema'] : [];
                $resolvedParamSchema = $this->resolveSchema($paramSchema, $componentsSchemas);
                $allowedTypes = $this->extractAllowedTypes($resolvedParamSchema);

                $mismatchedValue = null;
                if (! in_array('string', $allowedTypes, true) && (in_array('integer', $allowedTypes, true) || in_array('number', $allowedTypes, true) || in_array('boolean', $allowedTypes, true))) {
                    $mismatchedValue = 'not_a_valid_'.$allowedTypes[0];
                }

                if ($mismatchedValue !== null) {
                    $serialized = http_build_query([$name => $mismatchedValue]);
                    if (! preg_match('/(?:^|&)'.preg_quote($name, '/').'=/', $serialized)) {
                        $mismatchedValue = null;
                    }
                }

                if ($mismatchedValue !== null) {
                    $modifiedQuery = $baseQueryParams;
                    $modifiedQuery[$name] = $mismatchedValue;
                    $expectedTypeDesc = implode('|', $allowedTypes);

                    $cases[] = $this->buildDslDocument(
                        method: $method,
                        path: $path,
                        title: "Sai kiểu dữ liệu của tham số query '{$name}' trong {$method} {$path}",
                        description: "Gửi sai kiểu dữ liệu cho tham số query '{$name}' (cần {$expectedTypeDesc}) để kiểm tra yêu cầu bị từ chối.",
                        technique: TestTechnique::TypeMismatch,
                        requestData: [
                            'path_params' => $basePathParams,
                            'query' => $modifiedQuery,
                            'headers' => $baseHeaders,
                            'body' => $baseBody,
                            'has_body' => $endpointData['has_request_body'],
                        ],
                        expectedStatus: $errorStatus
                    );
                }
            }
        }

        // C. Path parameters type mismatch (e.g. non-numeric id where an integer is declared)
        foreach ($this->pathParameterSchemas($path, $endpointData['parameters'] ?? [], $componentsSchemas) as $name => $paramSchema) {
            $allowedTypes = $this->extractAllowedTypes($paramSchema, $componentsSchemas);
            if (in_array('string', $allowedTypes, true)) {
                continue;
            }
            $scalarTypes = array_values(array_intersect($allowedTypes, ['integer', 'number', 'boolean']));
            if ($scalarTypes === []) {
                continue;
            }
            $mismatchedValue = 'not_a_valid_'.$scalarTypes[0];
            $modifiedPathParams = $basePathParams;
            $modifiedPathParams[$name] = $mismatchedValue;

            $cases[] = $this->buildDslDocument(
                method: $method,
                path: $path,
                title: "Sai kiểu dữ liệu của tham số đường dẫn '{$name}' trong {$method} {$path}",
                description: "Gửi giá trị không phải {$scalarTypes[0]} cho tham số đường dẫn '{$name}' để kiểm tra yêu cầu bị từ chối.",
                technique: TestTechnique::TypeMismatch,
                requestData: [
                    'path_params' => $modifiedPathParams,
                    'query' => $baseQueryParams,
                    'headers' => $baseHeaders,
                    'body' => $baseBody,
                    'has_body' => $endpointData['has_request_body'],
                ],
                expectedStatus: $errorStatus
            );
        }

        return $cases;
    }

    /**
     * Resolved schema of every path parameter of the endpoint path, keyed by name.
     *
     * @param  list<array<string, mixed>>|null  $parameters
     * @param  array<string, mixed>  $componentsSchemas
     * @return array<string, array<string, mixed>>
     */
    protected function pathParameterSchemas(string $path, ?array $parameters, array $componentsSchemas): array
    {
        $schemas = [];
        if (! preg_match_all('/(?<!\{)\{([^{}]+)\}(?!\})/', $path, $matches)) {
            return [];
        }
        foreach ($matches[1] as $paramName) {
            foreach ($parameters ?? [] as $param) {
                if (($param['in'] ?? null) === 'path' && ($param['name'] ?? null) === $paramName
                    && isset($param['schema']) && is_array($param['schema'])) {
                    $schemas[(string) $paramName] = $this->resolveSchema($param['schema'], $componentsSchemas);
                    break;
                }
            }
        }

        return $schemas;
    }

    /**
     * `anyOf`/`oneOf` of exactly one schema plus `{type: null}` (OpenAPI 3.1 / FastAPI optional
     * fields) is unwrapped to that schema, so its bounds drive BOUNDARY cases. The original schema
     * still rejects the out-of-bound values, so the oracle is unchanged.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected function unwrapNullable(array $schema): array
    {
        foreach (['anyOf', 'oneOf'] as $keyword) {
            if (! isset($schema[$keyword]) || ! is_array($schema[$keyword])) {
                continue;
            }
            $nonNull = [];
            $hasNull = false;
            foreach ($schema[$keyword] as $branch) {
                if (is_array($branch) && ($branch['type'] ?? null) === 'null' && count($branch) === 1) {
                    $hasNull = true;
                } else {
                    $nonNull[] = $branch;
                }
            }
            if ($hasNull && count($nonNull) === 1 && is_array($nonNull[0])) {
                $outer = array_diff_key($schema, [$keyword => true]);

                return $nonNull[0] + $outer;
            }
        }

        return $schema;
    }

    /**
     * 4. BOUNDARY: Test lower and upper boundary values (on boundary -> 2xx, off boundary -> 4xx).
     *
     * @param  array<string, mixed>  $endpointData
     * @param  array<string, mixed>  $componentsSchemas
     * @return list<array<string, mixed>>
     */
    public function generateBoundary(Endpoint|ParsedEndpoint|array $endpointData, array $componentsSchemas = []): array
    {
        $endpointData = $this->normalizeEndpoint($endpointData);
        $this->enterOperation($endpointData);
        $cases = [];
        $method = $endpointData['method'];
        $path = $endpointData['path'];
        $successStatus = $this->getSuccessStatusCode($endpointData);
        $errorStatus = $this->getErrorStatusCode($endpointData);

        $basePathParams = $this->buildValidPathParams($path, $endpointData['parameters'] ?? [], $componentsSchemas);
        $baseQueryParams = $this->buildValidQueryParams($endpointData['parameters'] ?? [], $componentsSchemas);
        $baseHeaders = $this->buildValidHeaders($endpointData['parameters'] ?? [], $componentsSchemas);
        $buildFreshBody = fn (): mixed => $this->buildValidBody($endpointData['request_body'] ?? null, $componentsSchemas);
        $sampleBody = $buildFreshBody();

        // A. Body properties boundaries
        $bodySchema = $this->extractBodySchema($endpointData['request_body'] ?? null, $componentsSchemas);
        if ($bodySchema !== null && is_array($sampleBody) && isset($bodySchema['properties']) && is_array($bodySchema['properties'])) {
            foreach ($bodySchema['properties'] as $propName => $propDef) {
                if (! is_array($propDef)) {
                    continue;
                }
                $propStr = (string) $propName;
                $resolvedProp = $this->unwrapNullable($this->resolveSchema($propDef, $componentsSchemas));

                // Numeric minimum boundary
                if (isset($resolvedProp['minimum']) && is_numeric($resolvedProp['minimum'])) {
                    $min = $resolvedProp['minimum'];
                    $isInteger = $this->extractSchemaType($resolvedProp) === 'integer';

                    // Valid at min (2xx)
                    $validBody = $buildFreshBody();
                    if (is_array($validBody)) {
                        $validBody[$propStr] = $this->numericBoundaryValue($resolvedProp, true);
                    }
                    if ($successStatus !== null) {
                        $cases[] = $this->buildDslDocument(
                            method: $method,
                            path: $path,
                            title: "Biên hợp lệ: trường '{$propStr}' bằng minimum ({$min}) trong {$method} {$path}",
                            description: "Kiểm tra trường '{$propStr}' đặt đúng bằng giới hạn minimum ({$min}) thì thành công.",
                            technique: TestTechnique::Boundary,
                            requestData: [
                                'path_params' => $basePathParams,
                                'query' => $baseQueryParams,
                                'headers' => $baseHeaders,
                                'body' => $validBody,
                                'has_body' => true,
                            ],
                            expectedStatus: $successStatus
                        );
                    }

                    // Invalid below min (4xx)
                    if ($errorStatus !== null) {
                        $invalidBelow = $isInteger ? ((int) ceil((float) $min) - 1) : ((float) $min - 1.0);
                        $invalidBody = $buildFreshBody();
                        if (is_array($invalidBody)) {
                            $invalidBody[$propStr] = $invalidBelow;
                        }
                        $cases[] = $this->buildDslDocument(
                            method: $method,
                            path: $path,
                            title: "Biên không hợp lệ: trường '{$propStr}' nhỏ hơn minimum trong {$method} {$path}",
                            description: "Kiểm tra trường '{$propStr}' nhỏ hơn giới hạn minimum ({$invalidBelow}) trả về lỗi 4xx.",
                            technique: TestTechnique::Boundary,
                            requestData: [
                                'path_params' => $basePathParams,
                                'query' => $baseQueryParams,
                                'headers' => $baseHeaders,
                                'body' => $invalidBody,
                                'has_body' => true,
                            ],
                            expectedStatus: $errorStatus
                        );
                    }
                }

                // Numeric maximum boundary
                if (isset($resolvedProp['maximum']) && is_numeric($resolvedProp['maximum'])) {
                    $max = $resolvedProp['maximum'];
                    $isInteger = $this->extractSchemaType($resolvedProp) === 'integer';

                    // Valid at max (2xx)
                    $validBody = $buildFreshBody();
                    if (is_array($validBody)) {
                        $validBody[$propStr] = $this->numericBoundaryValue($resolvedProp, false);
                    }
                    if ($successStatus !== null) {
                        $cases[] = $this->buildDslDocument(
                            method: $method,
                            path: $path,
                            title: "Biên hợp lệ: trường '{$propStr}' bằng maximum ({$max}) trong {$method} {$path}",
                            description: "Kiểm tra trường '{$propStr}' đặt đúng bằng giới hạn maximum ({$max}) thì thành công.",
                            technique: TestTechnique::Boundary,
                            requestData: [
                                'path_params' => $basePathParams,
                                'query' => $baseQueryParams,
                                'headers' => $baseHeaders,
                                'body' => $validBody,
                                'has_body' => true,
                            ],
                            expectedStatus: $successStatus
                        );
                    }

                    // Invalid above max (4xx)
                    if ($errorStatus !== null) {
                        $invalidAbove = $isInteger ? ((int) $max + 1) : ((float) $max + 1.0);
                        $invalidBody = $buildFreshBody();
                        if (is_array($invalidBody)) {
                            $invalidBody[$propStr] = $invalidAbove;
                        }
                        $cases[] = $this->buildDslDocument(
                            method: $method,
                            path: $path,
                            title: "Biên không hợp lệ: trường '{$propStr}' lớn hơn maximum trong {$method} {$path}",
                            description: "Kiểm tra trường '{$propStr}' lớn hơn giới hạn maximum ({$invalidAbove}) trả về lỗi 4xx.",
                            technique: TestTechnique::Boundary,
                            requestData: [
                                'path_params' => $basePathParams,
                                'query' => $baseQueryParams,
                                'headers' => $baseHeaders,
                                'body' => $invalidBody,
                                'has_body' => true,
                            ],
                            expectedStatus: $errorStatus
                        );
                    }
                }

                // String minLength boundary
                if (isset($resolvedProp['minLength']) && is_numeric($resolvedProp['minLength'])) {
                    $minLen = (int) $resolvedProp['minLength'];

                    // Valid at minLength (2xx) - only generate if valid string satisfying all constraints can be formed
                    $validStr = $this->generateValidStringOfExactLength($resolvedProp, $propStr, $minLen);
                    if ($validStr !== null) {
                        $validBody = $buildFreshBody();
                        if (is_array($validBody)) {
                            $validBody[$propStr] = $validStr;
                        }
                        if ($successStatus !== null) {
                            $cases[] = $this->buildDslDocument(
                                method: $method,
                                path: $path,
                                title: "Biên hợp lệ: trường '{$propStr}' có độ dài bằng minLength ({$minLen}) trong {$method} {$path}",
                                description: "Kiểm tra trường '{$propStr}' có độ dài đúng bằng minLength ({$minLen}) thì thành công.",
                                technique: TestTechnique::Boundary,
                                requestData: [
                                    'path_params' => $basePathParams,
                                    'query' => $baseQueryParams,
                                    'headers' => $baseHeaders,
                                    'body' => $validBody,
                                    'has_body' => true,
                                ],
                                expectedStatus: $successStatus
                            );
                        }
                    }

                    // Invalid below minLength (4xx)
                    if ($minLen > 0 && $errorStatus !== null) {
                        $patternChar = $this->getSafeCharForSchema($resolvedProp);
                        $invalidStr = str_repeat($patternChar, $minLen - 1);
                        $invalidBody = $buildFreshBody();
                        if (is_array($invalidBody)) {
                            $invalidBody[$propStr] = $invalidStr;
                        }
                        $cases[] = $this->buildDslDocument(
                            method: $method,
                            path: $path,
                            title: "Biên không hợp lệ: trường '{$propStr}' ngắn hơn minLength trong {$method} {$path}",
                            description: "Kiểm tra trường '{$propStr}' có độ dài nhỏ hơn minLength trả về lỗi 4xx.",
                            technique: TestTechnique::Boundary,
                            requestData: [
                                'path_params' => $basePathParams,
                                'query' => $baseQueryParams,
                                'headers' => $baseHeaders,
                                'body' => $invalidBody,
                                'has_body' => true,
                            ],
                            expectedStatus: $errorStatus
                        );
                    }
                }

                // String maxLength boundary
                if (isset($resolvedProp['maxLength']) && is_numeric($resolvedProp['maxLength'])) {
                    $maxLen = (int) $resolvedProp['maxLength'];

                    // Valid at maxLength (2xx) - only generate if valid string satisfying all constraints can be formed
                    $validStr = $this->generateValidStringOfExactLength($resolvedProp, $propStr, $maxLen);
                    if ($validStr !== null) {
                        $validBody = $buildFreshBody();
                        if (is_array($validBody)) {
                            $validBody[$propStr] = $validStr;
                        }
                        if ($successStatus !== null) {
                            $cases[] = $this->buildDslDocument(
                                method: $method,
                                path: $path,
                                title: "Biên hợp lệ: trường '{$propStr}' có độ dài bằng maxLength ({$maxLen}) trong {$method} {$path}",
                                description: "Kiểm tra trường '{$propStr}' có độ dài đúng bằng maxLength ({$maxLen}) thì thành công.",
                                technique: TestTechnique::Boundary,
                                requestData: [
                                    'path_params' => $basePathParams,
                                    'query' => $baseQueryParams,
                                    'headers' => $baseHeaders,
                                    'body' => $validBody,
                                    'has_body' => true,
                                ],
                                expectedStatus: $successStatus
                            );
                        }
                    }

                    // Invalid above maxLength (4xx)
                    if ($errorStatus !== null) {
                        $patternChar = $this->getSafeCharForSchema($resolvedProp);
                        $invalidStr = str_repeat($patternChar, $maxLen + 1);
                        $invalidBody = $buildFreshBody();
                        if (is_array($invalidBody)) {
                            $invalidBody[$propStr] = $invalidStr;
                        }
                        $cases[] = $this->buildDslDocument(
                            method: $method,
                            path: $path,
                            title: "Biên không hợp lệ: trường '{$propStr}' dài hơn maxLength trong {$method} {$path}",
                            description: "Kiểm tra trường '{$propStr}' dài hơn maxLength ({$maxLen}) trả về lỗi 4xx.",
                            technique: TestTechnique::Boundary,
                            requestData: [
                                'path_params' => $basePathParams,
                                'query' => $baseQueryParams,
                                'headers' => $baseHeaders,
                                'body' => $invalidBody,
                                'has_body' => true,
                            ],
                            expectedStatus: $errorStatus
                        );
                    }
                }

                // Array minItems boundary
                if (isset($resolvedProp['minItems']) && is_numeric($resolvedProp['minItems']) && $this->extractSchemaType($resolvedProp) === 'array') {
                    $minItems = (int) $resolvedProp['minItems'];
                    $validArray = $this->generateValidArray(
                        array_merge($resolvedProp, ['minItems' => $minItems, 'maxItems' => $minItems]),
                        $componentsSchemas,
                        $propStr,
                        0
                    );
                    $validBody = $buildFreshBody();
                    if (is_array($validBody)) {
                        $validBody[$propStr] = $validArray;
                    }
                    if ($successStatus !== null) {
                        $cases[] = $this->buildDslDocument(
                            method: $method,
                            path: $path,
                            title: "Biên hợp lệ: trường '{$propStr}' có số phần tử bằng minItems ({$minItems}) trong {$method} {$path}",
                            description: "Kiểm tra mảng '{$propStr}' có đúng minItems ({$minItems}) phần tử thì thành công.",
                            technique: TestTechnique::Boundary,
                            requestData: [
                                'path_params' => $basePathParams,
                                'query' => $baseQueryParams,
                                'headers' => $baseHeaders,
                                'body' => $validBody,
                                'has_body' => true,
                            ],
                            expectedStatus: $successStatus
                        );
                    }

                    if ($minItems > 0 && $errorStatus !== null) {
                        $invalidArray = $minItems > 1 ? array_slice($validArray, 0, $minItems - 1) : [];
                        $invalidBody = $buildFreshBody();
                        if (is_array($invalidBody)) {
                            $invalidBody[$propStr] = $invalidArray;
                        }
                        $cases[] = $this->buildDslDocument(
                            method: $method,
                            path: $path,
                            title: "Biên không hợp lệ: trường '{$propStr}' ít phần tử hơn minItems trong {$method} {$path}",
                            description: "Kiểm tra mảng '{$propStr}' có ít phần tử hơn minItems trả về lỗi 4xx.",
                            technique: TestTechnique::Boundary,
                            requestData: [
                                'path_params' => $basePathParams,
                                'query' => $baseQueryParams,
                                'headers' => $baseHeaders,
                                'body' => $invalidBody,
                                'has_body' => true,
                            ],
                            expectedStatus: $errorStatus
                        );
                    }
                }

                // Array maxItems boundary
                if (isset($resolvedProp['maxItems']) && is_numeric($resolvedProp['maxItems']) && $this->extractSchemaType($resolvedProp) === 'array') {
                    $maxItems = (int) $resolvedProp['maxItems'];
                    $validArray = $this->generateValidArray(
                        array_merge($resolvedProp, ['minItems' => $maxItems, 'maxItems' => $maxItems]),
                        $componentsSchemas,
                        $propStr,
                        0
                    );
                    $validBody = $buildFreshBody();
                    if (is_array($validBody)) {
                        $validBody[$propStr] = $validArray;
                    }
                    if ($successStatus !== null) {
                        $cases[] = $this->buildDslDocument(
                            method: $method,
                            path: $path,
                            title: "Biên hợp lệ: trường '{$propStr}' có số phần tử bằng maxItems ({$maxItems}) trong {$method} {$path}",
                            description: "Kiểm tra mảng '{$propStr}' có đúng maxItems ({$maxItems}) phần tử thì thành công.",
                            technique: TestTechnique::Boundary,
                            requestData: [
                                'path_params' => $basePathParams,
                                'query' => $baseQueryParams,
                                'headers' => $baseHeaders,
                                'body' => $validBody,
                                'has_body' => true,
                            ],
                            expectedStatus: $successStatus
                        );
                    }

                    if ($errorStatus !== null) {
                        $extraItem = $this->generateValidValueForSchema($resolvedProp['items'] ?? ['type' => 'string'], $componentsSchemas, $propStr.'_extra', 0);
                        $invalidArray = [...$validArray, $extraItem];
                        $invalidBody = $buildFreshBody();
                        if (is_array($invalidBody)) {
                            $invalidBody[$propStr] = $invalidArray;
                        }
                        $cases[] = $this->buildDslDocument(
                            method: $method,
                            path: $path,
                            title: "Biên không hợp lệ: trường '{$propStr}' nhiều phần tử hơn maxItems trong {$method} {$path}",
                            description: "Kiểm tra mảng '{$propStr}' có nhiều phần tử hơn maxItems ({$maxItems}) trả về lỗi 4xx.",
                            technique: TestTechnique::Boundary,
                            requestData: [
                                'path_params' => $basePathParams,
                                'query' => $baseQueryParams,
                                'headers' => $baseHeaders,
                                'body' => $invalidBody,
                                'has_body' => true,
                            ],
                            expectedStatus: $errorStatus
                        );
                    }
                }
            }
        }

        // B. Query parameters boundaries
        $parameters = $endpointData['parameters'];
        if ($parameters !== null) {
            foreach ($parameters as $param) {
                if (($param['in'] ?? null) !== 'query') {
                    continue;
                }
                $name = (string) ($param['name'] ?? '');
                $paramSchema = isset($param['schema']) && is_array($param['schema']) ? $param['schema'] : [];

                if (isset($paramSchema['minimum']) && is_numeric($paramSchema['minimum'])) {
                    $min = $this->numericBoundaryValue($this->resolveSchema($paramSchema, $componentsSchemas), true);
                    // Valid at min (2xx)
                    $qValid = $baseQueryParams;
                    $qValid[$name] = $min;
                    if ($successStatus !== null) {
                        $cases[] = $this->buildDslDocument(
                            method: $method,
                            path: $path,
                            title: "Biên hợp lệ: query '{$name}' bằng minimum ({$min}) trong {$method} {$path}",
                            description: "Kiểm tra tham số query '{$name}' tại biên minimum.",
                            technique: TestTechnique::Boundary,
                            requestData: [
                                'path_params' => $basePathParams,
                                'query' => $qValid,
                                'headers' => $baseHeaders,
                                'body' => $buildFreshBody(),
                                'has_body' => $endpointData['has_request_body'],
                            ],
                            expectedStatus: $successStatus
                        );
                    }

                    // Invalid below min (4xx)
                    if ($errorStatus !== null) {
                        $qInvalid = $baseQueryParams;
                        $qInvalid[$name] = $min - 1;
                        $cases[] = $this->buildDslDocument(
                            method: $method,
                            path: $path,
                            title: "Biên không hợp lệ: query '{$name}' nhỏ hơn minimum trong {$method} {$path}",
                            description: "Kiểm tra tham số query '{$name}' nhỏ hơn biên minimum trả về lỗi 4xx.",
                            technique: TestTechnique::Boundary,
                            requestData: [
                                'path_params' => $basePathParams,
                                'query' => $qInvalid,
                                'headers' => $baseHeaders,
                                'body' => $buildFreshBody(),
                                'has_body' => $endpointData['has_request_body'],
                            ],
                            expectedStatus: $errorStatus
                        );
                    }
                }

                if (isset($paramSchema['maximum']) && is_numeric($paramSchema['maximum'])) {
                    $max = $this->numericBoundaryValue($this->resolveSchema($paramSchema, $componentsSchemas), false);
                    // Valid at max (2xx)
                    $qValid = $baseQueryParams;
                    $qValid[$name] = $max;
                    if ($successStatus !== null) {
                        $cases[] = $this->buildDslDocument(
                            method: $method,
                            path: $path,
                            title: "Biên hợp lệ: query '{$name}' bằng maximum ({$max}) trong {$method} {$path}",
                            description: "Kiểm tra tham số query '{$name}' tại biên maximum.",
                            technique: TestTechnique::Boundary,
                            requestData: [
                                'path_params' => $basePathParams,
                                'query' => $qValid,
                                'headers' => $baseHeaders,
                                'body' => $buildFreshBody(),
                                'has_body' => $endpointData['has_request_body'],
                            ],
                            expectedStatus: $successStatus
                        );
                    }

                    // Invalid above max (4xx)
                    if ($errorStatus !== null) {
                        $qInvalid = $baseQueryParams;
                        $qInvalid[$name] = $max + 1;
                        $cases[] = $this->buildDslDocument(
                            method: $method,
                            path: $path,
                            title: "Biên không hợp lệ: query '{$name}' lớn hơn maximum trong {$method} {$path}",
                            description: "Kiểm tra tham số query '{$name}' lớn hơn biên maximum trả về lỗi 4xx.",
                            technique: TestTechnique::Boundary,
                            requestData: [
                                'path_params' => $basePathParams,
                                'query' => $qInvalid,
                                'headers' => $baseHeaders,
                                'body' => $buildFreshBody(),
                                'has_body' => $endpointData['has_request_body'],
                            ],
                            expectedStatus: $errorStatus
                        );
                    }
                }
            }
        }

        return $this->filterConformingSuccessCases($cases, $endpointData, $componentsSchemas);
    }

    /**
     * 5. EQUIVALENCE: Equivalence Partitioning (valid partition -> 2xx, invalid partition -> 4xx).
     *
     * @param  array<string, mixed>  $endpointData
     * @param  array<string, mixed>  $componentsSchemas
     * @return list<array<string, mixed>>
     */
    public function generateEquivalence(Endpoint|ParsedEndpoint|array $endpointData, array $componentsSchemas = []): array
    {
        $endpointData = $this->normalizeEndpoint($endpointData);
        $this->enterOperation($endpointData);
        $cases = [];
        $method = $endpointData['method'];
        $path = $endpointData['path'];
        $successStatus = $this->getSuccessStatusCode($endpointData);
        $errorStatus = $this->getErrorStatusCode($endpointData);

        $basePathParams = $this->buildValidPathParams($path, $endpointData['parameters'] ?? [], $componentsSchemas);
        $baseQueryParams = $this->buildValidQueryParams($endpointData['parameters'] ?? [], $componentsSchemas);
        $baseHeaders = $this->buildValidHeaders($endpointData['parameters'] ?? [], $componentsSchemas);
        $buildFreshBody = fn (): mixed => $this->buildValidBody($endpointData['request_body'] ?? null, $componentsSchemas);
        $sampleBody = $buildFreshBody();

        // A. Body properties equivalence classes
        $bodySchema = $this->extractBodySchema($endpointData['request_body'] ?? null, $componentsSchemas);
        if ($bodySchema !== null && is_array($sampleBody) && isset($bodySchema['properties']) && is_array($bodySchema['properties'])) {
            foreach ($bodySchema['properties'] as $propName => $propDef) {
                if (! is_array($propDef)) {
                    continue;
                }
                $propStr = (string) $propName;
                $resolvedProp = $this->resolveSchema($propDef, $componentsSchemas);
                $type = $this->extractSchemaType($resolvedProp);

                if ($type === 'integer' || $type === 'number') {
                    // Valid partition (middle value)
                    $validMid = $this->generateValidValueForSchema($resolvedProp, $componentsSchemas, $propStr);
                    $validBody = $buildFreshBody();
                    if (is_array($validBody)) {
                        $validBody[$propStr] = $validMid;
                    }
                    if ($successStatus !== null) {
                        $cases[] = $this->buildDslDocument(
                            method: $method,
                            path: $path,
                            title: "Phân hoạch tương đương hợp lệ: '{$propStr}' trong {$method} {$path}",
                            description: "Giá trị đại diện của lớp tương đương hợp lệ cho trường '{$propStr}'.",
                            technique: TestTechnique::Equivalence,
                            requestData: [
                                'path_params' => $basePathParams,
                                'query' => $baseQueryParams,
                                'headers' => $baseHeaders,
                                'body' => $validBody,
                                'has_body' => true,
                            ],
                            expectedStatus: $successStatus
                        );
                    }

                    // A numeric error oracle needs a value outside the declared domain.
                    $invalidVal = $this->generateInvalidNumericValue($resolvedProp, $componentsSchemas);
                    if ($errorStatus !== null && $invalidVal !== null) {
                        $invalidBody = $buildFreshBody();
                        if (is_array($invalidBody)) {
                            $invalidBody[$propStr] = $invalidVal;
                        }
                        $cases[] = $this->buildDslDocument(
                            method: $method,
                            path: $path,
                            title: "Phân hoạch tương đương không hợp lệ: '{$propStr}' nằm ngoài schema trong {$method} {$path}",
                            description: "Giá trị số đại diện vi phạm schema đã khai báo cho '{$propStr}'.",
                            technique: TestTechnique::Equivalence,
                            requestData: [
                                'path_params' => $basePathParams,
                                'query' => $baseQueryParams,
                                'headers' => $baseHeaders,
                                'body' => $invalidBody,
                                'has_body' => true,
                            ],
                            expectedStatus: $errorStatus
                        );
                    }
                } elseif ($type === 'string') {
                    if (isset($resolvedProp['enum']) && is_array($resolvedProp['enum'])) {
                        // Valid enum partition
                        $validEnum = $resolvedProp['enum'][0];
                        $validBody = $buildFreshBody();
                        if (is_array($validBody)) {
                            $validBody[$propStr] = $validEnum;
                        }
                        if ($successStatus !== null) {
                            $cases[] = $this->buildDslDocument(
                                method: $method,
                                path: $path,
                                title: "Phân hoạch tương đương hợp lệ: enum '{$propStr}' trong {$method} {$path}",
                                description: "Giá trị đại diện của phân hoạch enum hợp lệ cho '{$propStr}'.",
                                technique: TestTechnique::Equivalence,
                                requestData: [
                                    'path_params' => $basePathParams,
                                    'query' => $baseQueryParams,
                                    'headers' => $baseHeaders,
                                    'body' => $validBody,
                                    'has_body' => true,
                                ],
                                expectedStatus: $successStatus
                            );
                        }

                        // Invalid enum partition
                        if ($errorStatus !== null) {
                            $invalidEnumVal = $this->generateInvalidEnumValue($resolvedProp, $componentsSchemas);
                            if ($invalidEnumVal !== null && ! $this->isValueConformingToSchema($invalidEnumVal, $resolvedProp, $componentsSchemas)) {
                                $invalidBody = $buildFreshBody();
                                if (is_array($invalidBody)) {
                                    $invalidBody[$propStr] = $invalidEnumVal;
                                }
                                $cases[] = $this->buildDslDocument(
                                    method: $method,
                                    path: $path,
                                    title: "Phân hoạch tương đương không hợp lệ: enum '{$propStr}' trong {$method} {$path}",
                                    description: "Giá trị đại diện của phân hoạch enum không hợp lệ cho '{$propStr}'.",
                                    technique: TestTechnique::Equivalence,
                                    requestData: [
                                        'path_params' => $basePathParams,
                                        'query' => $baseQueryParams,
                                        'headers' => $baseHeaders,
                                        'body' => $invalidBody,
                                        'has_body' => true,
                                    ],
                                    expectedStatus: $errorStatus
                                );
                            }
                        }
                    }
                }
            }
        }

        // B. Path parameters: a well-formed id that does not exist must return the declared 404.
        $responses = array_map(fn ($k) => (string) $k, array_keys($endpointData['responses']));
        if (in_array('404', $responses, true)) {
            foreach ($this->pathParameterSchemas($path, $endpointData['parameters'] ?? [], $componentsSchemas) as $name => $paramSchema) {
                $missingValue = $this->nonexistentPathValue($paramSchema, $componentsSchemas);
                if ($missingValue === null) {
                    continue;
                }
                $modifiedPathParams = $basePathParams;
                $modifiedPathParams[$name] = $missingValue;

                $cases[] = $this->buildDslDocument(
                    method: $method,
                    path: $path,
                    title: "Phân hoạch tương đương không hợp lệ: tham số đường dẫn '{$name}' không tồn tại trong {$method} {$path}",
                    description: "Dùng giá trị '{$name}' đúng định dạng nhưng không khớp tài nguyên nào để kiểm tra phản hồi 404 đã khai báo.",
                    technique: TestTechnique::Equivalence,
                    requestData: [
                        'path_params' => $modifiedPathParams,
                        'query' => $baseQueryParams,
                        'headers' => $baseHeaders,
                        'body' => $sampleBody,
                        'has_body' => $endpointData['has_request_body'],
                    ],
                    expectedStatus: 404
                );
            }
        }

        return $this->filterConformingSuccessCases($cases, $endpointData, $componentsSchemas);
    }

    /**
     * A schema-valid path value that no seeded resource uses, or null when none can be built safely.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $componentsSchemas
     */
    protected function nonexistentPathValue(array $schema, array $componentsSchemas): int|string|null
    {
        $types = $this->extractAllowedTypes($schema, $componentsSchemas);
        if (isset($schema['enum']) || isset($schema['const'])) {
            return null;
        }
        $candidates = in_array('integer', $types, true) || in_array('number', $types, true)
            ? [999999999, 99999, 9999]
            : (in_array('string', $types, true) && ! isset($schema['pattern']) && ! isset($schema['format'])
                ? ['ait-nonexistent-999999', 'nonexistent']
                : []);
        foreach ($candidates as $candidate) {
            if ($this->conformsToJsonSchema($candidate, $schema, $componentsSchemas) === true) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * 6. INVALID_INPUT: Syntactically or format-wise invalid inputs (regex pattern, format, enum).
     *
     * @param  array<string, mixed>  $endpointData
     * @param  array<string, mixed>  $componentsSchemas
     * @return list<array<string, mixed>>
     */
    public function generateInvalidInput(Endpoint|ParsedEndpoint|array $endpointData, array $componentsSchemas = []): array
    {
        $endpointData = $this->normalizeEndpoint($endpointData);
        $this->enterOperation($endpointData);
        $cases = [];
        $method = $endpointData['method'];
        $path = $endpointData['path'];
        $errorStatus = $this->getErrorStatusCode($endpointData);
        if ($errorStatus === null) {
            return [];
        }

        $basePathParams = $this->buildValidPathParams($path, $endpointData['parameters'] ?? [], $componentsSchemas);
        $baseQueryParams = $this->buildValidQueryParams($endpointData['parameters'] ?? [], $componentsSchemas);
        $baseHeaders = $this->buildValidHeaders($endpointData['parameters'] ?? [], $componentsSchemas);
        $baseBody = $this->buildValidBody($endpointData['request_body'] ?? null, $componentsSchemas);

        // A. Body properties invalid inputs
        $bodySchema = $this->extractBodySchema($endpointData['request_body'] ?? null, $componentsSchemas);
        if ($bodySchema !== null && is_array($baseBody) && isset($bodySchema['properties']) && is_array($bodySchema['properties'])) {
            foreach ($bodySchema['properties'] as $propName => $propDef) {
                if (! is_array($propDef)) {
                    continue;
                }
                $propStr = (string) $propName;
                $resolvedProp = $this->resolveSchema($propDef, $componentsSchemas);

                // Pattern violation
                if (isset($resolvedProp['pattern']) && is_string($resolvedProp['pattern'])) {
                    $pattern = $resolvedProp['pattern'];
                    $violatingValue = "INVALID_PATTERN_#@! \t\n";

                    if (! $this->isValueConformingToSchema($violatingValue, $resolvedProp, $componentsSchemas)) {
                        $modifiedBody = $baseBody;
                        $modifiedBody[$propStr] = $violatingValue;

                        $cases[] = $this->buildDslDocument(
                            method: $method,
                            path: $path,
                            title: "Đầu vào không hợp lệ: vi phạm pattern của '{$propStr}' trong {$method} {$path}",
                            description: "Gửi chuỗi vi phạm pattern '{$pattern}' cho trường '{$propStr}'.",
                            technique: TestTechnique::InvalidInput,
                            requestData: [
                                'path_params' => $basePathParams,
                                'query' => $baseQueryParams,
                                'headers' => $baseHeaders,
                                'body' => $modifiedBody,
                                'has_body' => true,
                            ],
                            expectedStatus: $errorStatus
                        );
                    }
                }

                // Format violation
                if (isset($resolvedProp['format']) && is_string($resolvedProp['format'])) {
                    $format = $resolvedProp['format'];
                    $violatingValue = match ($format) {
                        'email' => 'invalid-not-an-email-value',
                        'date', 'date-time' => 'invalid-date-time-format',
                        'uuid' => 'not-a-valid-uuid-format',
                        'uri', 'url' => 'invalid-uri-scheme',
                        default => 'invalid_format_value',
                    };

                    if (! $this->isValueConformingToSchema($violatingValue, $resolvedProp, $componentsSchemas)) {
                        $modifiedBody = $baseBody;
                        $modifiedBody[$propStr] = $violatingValue;

                        $cases[] = $this->buildDslDocument(
                            method: $method,
                            path: $path,
                            title: "Đầu vào không hợp lệ: sai format của '{$propStr}' trong {$method} {$path}",
                            description: "Gửi chuỗi sai format '{$format}' cho trường '{$propStr}'.",
                            technique: TestTechnique::InvalidInput,
                            requestData: [
                                'path_params' => $basePathParams,
                                'query' => $baseQueryParams,
                                'headers' => $baseHeaders,
                                'body' => $modifiedBody,
                                'has_body' => true,
                            ],
                            expectedStatus: $errorStatus
                        );
                    }
                }

                // Enum violation
                if (isset($resolvedProp['enum']) && is_array($resolvedProp['enum'])) {
                    $disallowedEnum = $this->generateInvalidEnumValue($resolvedProp, $componentsSchemas);
                    if ($disallowedEnum !== null && ! $this->isValueConformingToSchema($disallowedEnum, $resolvedProp, $componentsSchemas)) {
                        $modifiedBody = $baseBody;
                        $modifiedBody[$propStr] = $disallowedEnum;

                        $cases[] = $this->buildDslDocument(
                            method: $method,
                            path: $path,
                            title: "Đầu vào không hợp lệ: ngoài enum của '{$propStr}' trong {$method} {$path}",
                            description: "Gửi giá trị nằm ngoài các lựa chọn enum đã khai báo cho trường '{$propStr}'.",
                            technique: TestTechnique::InvalidInput,
                            requestData: [
                                'path_params' => $basePathParams,
                                'query' => $baseQueryParams,
                                'headers' => $baseHeaders,
                                'body' => $modifiedBody,
                                'has_body' => true,
                            ],
                            expectedStatus: $errorStatus
                        );
                    }
                }

                // Numeric negative input when minimum is non-negative
                $type = $this->extractSchemaType($resolvedProp);
                if (($type === 'integer' || $type === 'number') && isset($resolvedProp['minimum']) && $resolvedProp['minimum'] >= 0 && ! isset($resolvedProp['pattern']) && ! isset($resolvedProp['enum'])) {
                    $negVal = $type === 'integer' ? -1 : -1.0;
                    if (! $this->isValueConformingToSchema($negVal, $resolvedProp, $componentsSchemas)) {
                        $modifiedBody = $baseBody;
                        $modifiedBody[$propStr] = $negVal;

                        $cases[] = $this->buildDslDocument(
                            method: $method,
                            path: $path,
                            title: "Đầu vào không hợp lệ: giá trị âm cho '{$propStr}' trong {$method} {$path}",
                            description: "Gửi số âm cho trường không âm '{$propStr}'.",
                            technique: TestTechnique::InvalidInput,
                            requestData: [
                                'path_params' => $basePathParams,
                                'query' => $baseQueryParams,
                                'headers' => $baseHeaders,
                                'body' => $modifiedBody,
                                'has_body' => true,
                            ],
                            expectedStatus: $errorStatus
                        );
                    }
                }
            }
        }

        return $cases;
    }

    /**
     * @param  list<array<string, mixed>>  $cases
     * @param  array<string, mixed>  $endpointData
     * @param  array<string, mixed>  $componentsSchemas
     * @return list<array<string, mixed>>
     */
    /**
     * Prove that the entire request conforms to endpoint parameter and body schemas.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $endpointData
     * @param  array<string, mixed>  $componentsSchemas
     */
    public function isRequestConformingToEndpoint(
        array $requestData,
        array $endpointData,
        array $componentsSchemas = []
    ): bool {
        $endpointData = $this->normalizeEndpoint($endpointData);
        $parameters = $endpointData['parameters'] ?? [];

        // Pre-check: If any endpoint parameter schema has impossible constraints, request cannot conform
        foreach ($parameters as $param) {
            if (isset($param['schema']) && is_array($param['schema'])) {
                if ($this->hasImpossibleConstraints($param['schema'], $componentsSchemas)) {
                    return false;
                }
            }
        }

        // 1. Validate path parameters
        $pathParams = $requestData['path_params'] ?? [];
        if (preg_match_all('/\{([a-zA-Z0-9_]+)\}/', (string) $endpointData['path'], $matches)) {
            foreach ($matches[1] as $pName) {
                if (! array_key_exists($pName, $pathParams)) {
                    return false;
                }
            }
        }
        foreach ($parameters as $param) {
            if (($param['in'] ?? null) !== 'path') {
                continue;
            }
            $name = (string) ($param['name'] ?? '');
            if ($name === '') {
                continue;
            }
            if (! array_key_exists($name, $pathParams)) {
                return false;
            }
            $val = $pathParams[$name];
            if (isset($param['schema']) && is_array($param['schema'])) {
                $rawParamSchema = $param['schema'];
                $schema = $this->resolveSchema($rawParamSchema, $componentsSchemas);
                $typedVal = $val;
                $schemaType = $this->extractSchemaType($schema);
                if ($schemaType === 'integer' && is_numeric($val) && (int) $val == $val) {
                    $typedVal = (int) $val;
                } elseif ($schemaType === 'number') {
                    $typedVal = $this->coerceNumberParameterValue($val);
                } elseif ($schemaType === 'boolean') {
                    if ($val === 'true' || $val === '1' || $val === 1 || $val === true) {
                        $typedVal = true;
                    } elseif ($val === 'false' || $val === '0' || $val === 0 || $val === false) {
                        $typedVal = false;
                    }
                }
                if (! $this->isValueConformingToSchema($typedVal, $schema, $componentsSchemas)) {
                    return false;
                }
                if ($this->conformsToJsonSchema($typedVal, $rawParamSchema, $componentsSchemas) !== true) {
                    return false;
                }
            }
        }

        // 2. Validate query parameters
        $queryParams = $requestData['query'] ?? [];
        foreach ($parameters as $param) {
            if (($param['in'] ?? null) !== 'query') {
                continue;
            }
            $name = (string) ($param['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $isRequired = (bool) ($param['required'] ?? false);
            if ($isRequired && ! array_key_exists($name, $queryParams)) {
                return false;
            }
            if (array_key_exists($name, $queryParams)) {
                $val = $queryParams[$name];
                if (isset($param['schema']) && is_array($param['schema'])) {
                    $rawParamSchema = $param['schema'];
                    $schema = $this->resolveSchema($rawParamSchema, $componentsSchemas);
                    $typedVal = $val;
                    $schemaType = $this->extractSchemaType($schema);
                    if ($schemaType === 'integer' && is_numeric($val) && (int) $val == $val) {
                        $typedVal = (int) $val;
                    } elseif ($schemaType === 'number') {
                        $typedVal = $this->coerceNumberParameterValue($val);
                    } elseif ($schemaType === 'boolean') {
                        if ($val === 'true' || $val === '1' || $val === 1 || $val === true) {
                            $typedVal = true;
                        } elseif ($val === 'false' || $val === '0' || $val === 0 || $val === false) {
                            $typedVal = false;
                        }
                    }
                    if (! $this->isValueConformingToSchema($typedVal, $schema, $componentsSchemas)) {
                        return false;
                    }
                    if ($this->conformsToJsonSchema($typedVal, $rawParamSchema, $componentsSchemas) !== true) {
                        return false;
                    }
                }
            }
        }

        // 3. Validate header parameters
        $headers = $requestData['headers'] ?? [];
        $normalizedHeaders = [];
        foreach ($headers as $k => $v) {
            $normalizedHeaders[strtolower((string) $k)] = $v;
        }

        foreach ($parameters as $param) {
            if (($param['in'] ?? null) !== 'header') {
                continue;
            }
            $name = (string) ($param['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $lowerName = strtolower($name);
            $isRequired = (bool) ($param['required'] ?? false);
            if ($isRequired && ! array_key_exists($lowerName, $normalizedHeaders)) {
                return false;
            }
            if (array_key_exists($lowerName, $normalizedHeaders)) {
                $headerVal = $normalizedHeaders[$lowerName];
                if (isset($param['schema']) && is_array($param['schema'])) {
                    $rawParamSchema = $param['schema'];
                    $schema = $this->resolveSchema($rawParamSchema, $componentsSchemas);
                    $typedVal = $headerVal;
                    $schemaType = $this->extractSchemaType($schema);
                    if ($schemaType === 'integer' && is_numeric($headerVal) && (int) $headerVal == $headerVal) {
                        $typedVal = (int) $headerVal;
                    } elseif ($schemaType === 'number') {
                        $typedVal = $this->coerceNumberParameterValue($headerVal);
                    } elseif ($schemaType === 'boolean') {
                        if ($headerVal === 'true' || $headerVal === '1') {
                            $typedVal = true;
                        } elseif ($headerVal === 'false' || $headerVal === '0') {
                            $typedVal = false;
                        }
                    }
                    if (! $this->isValueConformingToSchema($typedVal, $schema, $componentsSchemas)) {
                        return false;
                    }
                    if ($this->conformsToJsonSchema($typedVal, $rawParamSchema, $componentsSchemas) !== true) {
                        return false;
                    }
                }
            }
        }

        // 4. Validate request body
        $rawBodySchema = null;
        if (isset($endpointData['request_body']['schema']) && is_array($endpointData['request_body']['schema'])) {
            $rawBodySchema = $endpointData['request_body']['schema'];
        } elseif (isset($endpointData['request_body']['content']) && is_array($endpointData['request_body']['content'])) {
            $media = $endpointData['request_body']['content']['application/json'] ?? reset($endpointData['request_body']['content']);
            if (is_array($media) && isset($media['schema']) && is_array($media['schema'])) {
                $rawBodySchema = $media['schema'];
            }
        }
        $bodySchema = $this->extractBodySchema($endpointData['request_body'] ?? null, $componentsSchemas);
        if ($bodySchema !== null) {
            if ($this->hasImpossibleConstraints($bodySchema, $componentsSchemas)) {
                return false;
            }
            $isBodyRequired = (bool) ($endpointData['request_body']['required'] ?? false);
            $hasBody = array_key_exists('body', $requestData) && $requestData['body'] !== null;
            if ($isBodyRequired && ! $hasBody) {
                return false;
            }
            if ($hasBody) {
                if (! $this->isValueConformingToSchema($requestData['body'], $bodySchema, $componentsSchemas)) {
                    return false;
                }
                $oracleSchema = $rawBodySchema ?? $bodySchema;
                if ($this->conformsToJsonSchema($requestData['body'], $oracleSchema, $componentsSchemas) !== true) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $cases
     * @param  array<string, mixed>  $endpointData
     * @param  array<string, mixed>  $componentsSchemas
     * @return list<array<string, mixed>>
     */
    protected function filterConformingSuccessCases(array $cases, array $endpointData, array $componentsSchemas): array
    {
        return array_values(array_filter($cases, function (array $case) use ($endpointData, $componentsSchemas): bool {
            $step = $case['steps'][0];
            $status = $step['assertions'][0]['equals'];
            if ($status < 200 || $status >= 300) {
                return true;
            }

            return $this->isRequestConformingToEndpoint($step['request'], $endpointData, $componentsSchemas);
        }));
    }

    /**
     * Build standard Test DSL v1.0 document.
     *
     * @param  array{path_params: array<string, mixed>, query: array<string, mixed>, headers?: array<string, string>, body: mixed, has_body: bool, skip_auto_content_type?: bool}  $requestData
     * @param  list<array<string, mixed>>  $extraAssertions
     * @return array<string, mixed>
     */
    protected function buildDslDocument(
        string $method,
        string $path,
        string $title,
        string $description,
        TestTechnique $technique,
        array $requestData,
        int $expectedStatus,
        array $extraAssertions = []
    ): array {
        $assertions = [
            [
                'type' => 'status',
                'equals' => $expectedStatus,
            ],
            ...$extraAssertions,
        ];

        $stepRequest = [
            'method' => $method,
            'path' => $path,
        ];

        if (! empty($requestData['path_params'])) {
            $stepRequest['path_params'] = $requestData['path_params'];
        }

        if (! empty($requestData['query'])) {
            $stepRequest['query'] = $requestData['query'];
        }

        $headers = $requestData['headers'] ?? [];
        $skipAutoContentType = (bool) ($requestData['skip_auto_content_type'] ?? false);
        if ($requestData['has_body'] && $requestData['body'] !== null) {
            if (! $skipAutoContentType && ! in_array('content-type', array_map('strtolower', array_keys($headers)), true)) {
                $headers['Content-Type'] = 'application/json';
            }
            $stepRequest['body'] = $requestData['body'];
        }
        if ($headers !== []) {
            $stepRequest['headers'] = $headers;
        }

        $safeTitle = mb_substr($title, 0, 255);
        if (mb_strlen($safeTitle) < 3) {
            $safeTitle = str_pad($safeTitle, 3, ' ');
        }

        return [
            'dsl_version' => '1.0',
            'title' => $safeTitle,
            'description' => mb_substr($description, 0, 2000),
            'endpoint' => [
                'method' => $method,
                'path' => $path,
            ],
            'technique' => $technique->value,
            'source' => TestCaseSource::Rule->value,
            'tags' => ['rule', strtolower($technique->value)],
            'steps' => [
                [
                    'name' => mb_substr("Execute {$method} {$path}", 0, 255),
                    'request' => $stepRequest,
                    'assertions' => $assertions,
                ],
            ],
        ];
    }

    /**
     * Normalize various endpoint representations into a standard internal array format.
     *
     * @param  Endpoint|ParsedEndpoint|array<string, mixed>  $endpoint
     * @return array{method: string, path: string, operation_id: string|null, summary: string|null, parameters: list<array<string, mixed>>|null, request_body: array<string, mixed>|null, responses: array<string, mixed>, has_request_body: bool, security: mixed, requires_auth: bool}
     */
    public function normalizeEndpoint(Endpoint|ParsedEndpoint|array $endpoint): array
    {
        if ($endpoint instanceof Endpoint) {
            $method = $endpoint->method->value;
            $path = $endpoint->path;
            $operationId = $endpoint->operation_id;
            $summary = $endpoint->summary;
            $parameters = $endpoint->parameters;
            $requestBody = $endpoint->request_body;
            $responses = $endpoint->responses;
            $security = $endpoint->security;
        } elseif ($endpoint instanceof ParsedEndpoint) {
            $method = $endpoint->method->value;
            $path = $endpoint->path;
            $operationId = $endpoint->operationId;
            $summary = $endpoint->summary;
            $parameters = $endpoint->parameters;
            $requestBody = $endpoint->requestBody;
            $responses = $endpoint->responses;
            $security = $endpoint->security;
        } else {
            $method = strtoupper((string) ($endpoint['method'] ?? 'GET'));
            $path = (string) ($endpoint['path'] ?? '/');
            $operationId = isset($endpoint['operation_id']) ? (string) $endpoint['operation_id'] : ($endpoint['operationId'] ?? null);
            $summary = isset($endpoint['summary']) ? (string) $endpoint['summary'] : null;
            $parameters = $endpoint['parameters'] ?? null;
            $requestBody = $endpoint['request_body'] ?? $endpoint['requestBody'] ?? null;
            $responses = isset($endpoint['responses']) && is_array($endpoint['responses']) ? $endpoint['responses'] : [];
            $security = $endpoint['security'] ?? null;
        }

        $hasRequestBody = ! empty($requestBody);

        /** @var list<array<string, mixed>>|null $normalizedParameters */
        $normalizedParameters = is_array($parameters) ? array_values(array_filter($parameters, 'is_array')) : null;
        /** @var array<string, mixed>|null $normalizedRequestBody */
        $normalizedRequestBody = is_array($requestBody) ? $requestBody : null;
        /** @var array<string, mixed> $normalizedResponses */
        $normalizedResponses = $responses;

        return [
            'method' => strtoupper($method),
            'path' => $path,
            'operation_id' => $operationId,
            'summary' => $summary,
            'parameters' => $normalizedParameters,
            'request_body' => $normalizedRequestBody,
            'responses' => $normalizedResponses,
            'has_request_body' => $hasRequestBody,
            'security' => $security,
            'requires_auth' => $this->requiresAuth($security),
        ];
    }

    /**
     * Effective OpenAPI security (operation-level, or inherited global, as stored by the parser)
     * demands a credential when it lists at least one requirement and none of them is the empty
     * requirement `{}` (which makes authentication optional).
     */
    protected function requiresAuth(mixed $security): bool
    {
        return OpenApiSecurity::requiresAuth($security);
    }

    /**
     * Resolve components.schemas from endpoint context or parameter.
     *
     * @param  Endpoint|ParsedEndpoint|array<string, mixed>  $endpoint
     * @param  array<string, mixed>  $providedSchemas
     * @return array<string, mixed>
     */
    protected function resolveComponentsSchemas(Endpoint|ParsedEndpoint|array $endpoint, array $providedSchemas = []): array
    {
        if ($providedSchemas !== []) {
            return $providedSchemas;
        }

        if ($endpoint instanceof Endpoint && $endpoint->relationLoaded('apiSpec')) {
            $rawContent = $endpoint->apiSpec->normalized ?? [];
            if (isset($rawContent['components']['schemas']) && is_array($rawContent['components']['schemas'])) {
                return $rawContent['components']['schemas'];
            }
        }

        return [];
    }

    /**
     * Filter and resolve test techniques to generate.
     *
     * @param  list<TestTechnique|string>  $techniques
     * @return list<TestTechnique>
     */
    protected function resolveTechniques(array $techniques): array
    {
        if ($techniques === []) {
            return self::SUPPORTED_TECHNIQUES;
        }

        $resolved = [];
        foreach ($techniques as $t) {
            if ($t instanceof TestTechnique) {
                if (in_array($t, self::SUPPORTED_TECHNIQUES, true)) {
                    $resolved[$t->value] = $t;
                }
            } elseif (is_string($t)) {
                $enumVal = TestTechnique::tryFrom(strtoupper($t));
                if ($enumVal !== null && in_array($enumVal, self::SUPPORTED_TECHNIQUES, true)) {
                    $resolved[$enumVal->value] = $enumVal;
                }
            }
        }

        return $resolved !== [] ? array_values($resolved) : self::SUPPORTED_TECHNIQUES;
    }

    /**
     * Extract application/json schema from requestBody.
     *
     * @param  array<string, mixed>|null  $requestBody
     * @param  array<string, mixed>  $componentsSchemas
     * @return array<string, mixed>|null
     */
    protected function extractBodySchema(?array $requestBody, array $componentsSchemas): ?array
    {
        if ($requestBody === null) {
            return null;
        }

        if (isset($requestBody['schema']) && is_array($requestBody['schema'])) {
            return $this->resolveSchema($requestBody['schema'], $componentsSchemas);
        }

        $content = $requestBody['content'] ?? null;
        if (! is_array($content)) {
            return null;
        }

        $media = $content['application/json'] ?? reset($content);
        if (! is_array($media) || ! isset($media['schema']) || ! is_array($media['schema'])) {
            return null;
        }

        return $this->resolveSchema($media['schema'], $componentsSchemas);
    }

    /**
     * Resolve $ref and allOf composition recursively.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $componentsSchemas
     * @return array<string, mixed>
     */
    public function resolveSchema(array $schema, array $componentsSchemas, int $depth = 0): array
    {
        if ($depth > 10) {
            return $schema;
        }

        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $ref = $schema['$ref'];
            if (preg_match('~^#/components/schemas/([^/]+)$~', $ref, $matches)) {
                $schemaName = $matches[1];
                if (isset($componentsSchemas[$schemaName]) && is_array($componentsSchemas[$schemaName])) {
                    $resolved = $this->resolveSchema($componentsSchemas[$schemaName], $componentsSchemas, $depth + 1);
                    $override = array_diff_key($schema, ['$ref' => true]);
                    if (empty($override)) {
                        $schema = $resolved;
                    } else {
                        $schema = $this->intersectSchemaConstraints($resolved, $override);
                    }
                }
            }
        }

        // OpenAPI 3.0 boolean exclusivity belongs to this branch's bound,
        // rather than a different minimum/maximum introduced during merging.
        foreach (['exclusiveMinimum' => 'minimum', 'exclusiveMaximum' => 'maximum'] as $exclusive => $bound) {
            if (isset($schema[$exclusive]) && is_bool($schema[$exclusive])) {
                if ($schema[$exclusive] && isset($schema[$bound]) && is_numeric($schema[$bound])) {
                    $schema[$exclusive] = $schema[$bound];
                } else {
                    unset($schema[$exclusive]);
                }
            }
        }

        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            $branches = $schema['allOf'];
            unset($schema['allOf']);
            $resolvedBranches = [];
            foreach ($branches as $branch) {
                if (! is_array($branch)) {
                    continue;
                }
                $flattened = $this->resolveSchema($branch, $componentsSchemas, $depth + 1);
                $resolvedBranches[] = $flattened;
                $schema = $this->intersectSchemaConstraints($schema, $flattened);
            }
            // Keep every branch as the validation oracle. The merged keywords
            // guide generation; constraints that cannot be flattened still apply.
            $schema['allOf'] = $resolvedBranches;
        }

        if (isset($schema['oneOf']) && is_array($schema['oneOf'])) {
            $resolvedBranches = [];
            foreach ($schema['oneOf'] as $branch) {
                $resolvedBranches[] = is_array($branch)
                    ? $this->resolveSchema($branch, $componentsSchemas, $depth + 1)
                    : $branch;
            }
            $schema['oneOf'] = $resolvedBranches;
        }

        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            $resolvedBranches = [];
            foreach ($schema['anyOf'] as $branch) {
                $resolvedBranches[] = is_array($branch)
                    ? $this->resolveSchema($branch, $componentsSchemas, $depth + 1)
                    : $branch;
            }
            $schema['anyOf'] = $resolvedBranches;
        }

        return $schema;
    }

    /**
     * Intersect constraints used to construct a candidate for an allOf schema.
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
                $left[$keyword] = array_values(array_filter($left[$keyword], function ($item) use ($value) {
                    foreach ($value as $v) {
                        if ($this->jsonValuesEqual($item, $v)) {
                            return true;
                        }
                    }

                    return false;
                }));
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
            } elseif ($keyword === 'type') {
                $leftTypes = is_array($left[$keyword]) ? $left[$keyword] : [$left[$keyword]];
                $rightTypes = is_array($value) ? $value : [$value];
                $types = array_values(array_intersect($leftTypes, $rightTypes));
                if ((in_array('integer', $leftTypes, true) && in_array('number', $rightTypes, true))
                    || (in_array('number', $leftTypes, true) && in_array('integer', $rightTypes, true))) {
                    $types[] = 'integer';
                }
                $left[$keyword] = array_values(array_unique($types));
            } elseif ($keyword === 'uniqueItems') {
                $left[$keyword] = $left[$keyword] || $value;
            }
        }

        return $left;
    }

    /**
     * Return a same-type numeric value that actually violates a constraint,
     * or null when no invalid numeric partition can be established.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $componentsSchemas
     */
    protected function generateInvalidNumericValue(array $schema, array $componentsSchemas): int|float|null
    {
        $integer = $this->extractSchemaType($schema) === 'integer';
        $candidates = [];
        foreach (['minimum', 'exclusiveMinimum', 'maximum', 'exclusiveMaximum'] as $keyword) {
            if (! isset($schema[$keyword]) || ! is_numeric($schema[$keyword])) {
                continue;
            }
            $bound = (float) $schema[$keyword];
            $offset = $integer ? 1.0 : max(1.0, abs($bound) * 0.01);
            $candidates[] = str_contains($keyword, 'Minimum') || $keyword === 'minimum'
                ? floor($bound) - $offset
                : ceil($bound) + $offset;
        }
        $candidates = [...$candidates, 0, 1, -1];
        if (isset($schema['enum']) && is_array($schema['enum'])) {
            foreach ($schema['enum'] as $value) {
                if (is_int($value) || is_float($value)) {
                    $candidates[] = $value - 1;
                    $candidates[] = $value + 1;
                }
            }
        }
        if (isset($schema['multipleOf']) && is_numeric($schema['multipleOf'])) {
            $candidates[] = (float) $schema['multipleOf'] / 2;
        }
        foreach ($candidates as $candidate) {
            if (! is_finite((float) $candidate) || ($integer && ($candidate < PHP_INT_MIN || $candidate >= (float) PHP_INT_MAX))) {
                continue;
            }
            $candidate = $integer ? (int) $candidate : (float) $candidate;
            if (! $this->isValueConformingToSchema($candidate, $schema, $componentsSchemas)
                && $this->conformsToJsonSchema($candidate, $schema, $componentsSchemas) === false) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Return an invalid enum value that is outside the declared enum and non-conforming,
     * or null when no invalid enum partition can be established.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $componentsSchemas
     */
    public function generateInvalidEnumValue(array $schema, array $componentsSchemas = []): ?string
    {
        $resolved = $this->resolveSchema($schema, $componentsSchemas);
        $enum = isset($resolved['enum']) && is_array($resolved['enum']) ? $resolved['enum'] : [];
        if ($enum === []) {
            return null;
        }

        $candidates = [
            'INVALID_ENUM_PARTITION',
            'DISALLOWED_ENUM_OPTION_XYZ',
            '__UNKNOWN_ENUM_VALUE__',
            '!@#$%_NOT_IN_ENUM',
        ];

        foreach ($candidates as $cand) {
            if (! in_array($cand, $enum, true)
                && ! $this->isValueConformingToSchema($cand, $resolved, $componentsSchemas)
                && $this->conformsToJsonSchema($cand, $resolved, $componentsSchemas) === false) {
                return $cand;
            }
        }

        for ($i = 0; $i < 10; $i++) {
            $cand = 'INVALID_ENUM_'.bin2hex(random_bytes(6));
            if (! in_array($cand, $enum, true)
                && ! $this->isValueConformingToSchema($cand, $resolved, $componentsSchemas)
                && $this->conformsToJsonSchema($cand, $resolved, $componentsSchemas) === false) {
                return $cand;
            }
        }

        return null;
    }

    /**
     * Validate the full conjunction, not only the generation constraints.
     * Null means the schema itself could not establish an oracle.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $componentsSchemas
     */
    public function conformsToJsonSchema(mixed $value, array $schema, array $componentsSchemas = []): ?bool
    {
        $schemaToValidate = $schema;
        if (! empty($componentsSchemas)) {
            $schemaToValidate['components'] = ['schemas' => $componentsSchemas];
        }
        if (is_array($value) && $value === [] && ($this->extractSchemaType($schemaToValidate) === 'object' || isset($schemaToValidate['properties']))) {
            $value = (object) [];
        }
        try {
            return (new Validator)->validate(
                json_decode(json_encode($value, JSON_THROW_ON_ERROR)),
                json_decode(json_encode($this->jsonSchemaToObject($schemaToValidate), JSON_THROW_ON_ERROR)),
            )->isValid();
        } catch (\JsonException|SchemaException) {
            return null;
        }
    }

    /**
     * Serialize schema-valued keywords as JSON objects without changing example/enum/const data.
     * PHP represents both an empty JSON object and an empty array as [], but in a schema position
     * the empty object is the unconstrained JSON Schema `{}`.
     *
     * @param  array<string, mixed>  $schema
     */
    private function jsonSchemaToObject(array $schema): \stdClass
    {
        $object = new \stdClass;
        $singleSchemaKeywords = [
            'additionalProperties', 'additionalItems', 'items', 'not', 'if', 'then', 'else',
            'contains', 'propertyNames', 'unevaluatedProperties', 'unevaluatedItems', 'contentSchema',
        ];
        $schemaMapKeywords = ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'];
        $schemaListKeywords = ['allOf', 'anyOf', 'oneOf', 'prefixItems'];

        foreach ($schema as $keyword => $value) {
            if (in_array($keyword, $singleSchemaKeywords, true) && is_array($value)) {
                $object->{$keyword} = $this->jsonSchemaToObject($value);
            } elseif (in_array($keyword, $schemaMapKeywords, true) && is_array($value)) {
                $schemaMap = new \stdClass;
                foreach ($value as $name => $subschema) {
                    $schemaMap->{(string) $name} = is_array($subschema)
                        ? $this->jsonSchemaToObject($subschema)
                        : $subschema;
                }
                $object->{$keyword} = $schemaMap;
            } elseif (in_array($keyword, $schemaListKeywords, true) && is_array($value)) {
                $object->{$keyword} = array_map(
                    fn ($subschema) => is_array($subschema) ? $this->jsonSchemaToObject($subschema) : $subschema,
                    $value
                );
            } elseif ($keyword === 'components' && is_array($value)) {
                $components = new \stdClass;
                foreach ($value as $componentName => $componentValue) {
                    if ($componentName === 'schemas' && is_array($componentValue)) {
                        $schemas = new \stdClass;
                        foreach ($componentValue as $schemaName => $subschema) {
                            $schemas->{(string) $schemaName} = is_array($subschema)
                                ? $this->jsonSchemaToObject($subschema)
                                : $subschema;
                        }
                        $components->{$componentName} = $schemas;
                    } else {
                        $components->{$componentName} = $componentValue;
                    }
                }
                $object->{$keyword} = $components;
            } else {
                // Keep data-bearing keywords such as enum, const, example, and default intact.
                $object->{$keyword} = $value;
            }
        }

        return $object;
    }

    /**
     * Keep exact integer request parameters as PHP integers. Query/path/header values may arrive
     * as decimal strings, so only fall back to float after checking whether the integer fits int64.
     */
    private function coerceNumberParameterValue(mixed $value): mixed
    {
        if (is_int($value) || is_float($value) || ! is_string($value) || ! is_numeric($value)) {
            return $value;
        }

        $decimal = trim($value);
        if (preg_match('/^[+-]?\\d+$/D', $decimal) === 1) {
            $integer = $this->parseInt64String($decimal);
            if ($integer !== null) {
                return $integer;
            }
        }

        return (float) $value;
    }

    private function parseInt64String(string $decimal): ?int
    {
        $negative = str_starts_with($decimal, '-');
        $digits = ltrim($negative || str_starts_with($decimal, '+') ? substr($decimal, 1) : $decimal, '0');
        if ($digits === '') {
            return 0;
        }

        $limit = $negative ? substr((string) PHP_INT_MIN, 1) : (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($limit)
            || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)
        ) {
            return null;
        }

        return (int) $decimal;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected function stripMultipleOfConstraint(array $schema): array
    {
        $stripped = $schema;
        unset($stripped['multipleOf']);

        return $stripped;
    }

    /**
     * Extract primary type from schema, supporting OpenAPI 3.1 type arrays e.g. ["string", "null"].
     *
     * @param  array<string, mixed>  $schema
     */
    public function extractSchemaType(array $schema): string
    {
        $type = $schema['type'] ?? null;
        if (is_array($type)) {
            foreach ($type as $t) {
                if (is_string($t) && $t !== 'null') {
                    return $t;
                }
            }

            return 'string';
        }

        if (is_string($type) && $type !== '') {
            return $type;
        }

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            return 'object';
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            return 'array';
        }

        return 'string';
    }

    /**
     * Build valid dummy JSON body matching schema definition.
     *
     * @param  array<string, mixed>|null  $requestBody
     * @param  array<string, mixed>  $componentsSchemas
     */
    protected function buildValidBody(?array $requestBody, array $componentsSchemas): mixed
    {
        $rawSchema = null;
        if (isset($requestBody['schema']) && is_array($requestBody['schema'])) {
            $rawSchema = $requestBody['schema'];
        } elseif (isset($requestBody['content']) && is_array($requestBody['content'])) {
            $media = $requestBody['content']['application/json'] ?? reset($requestBody['content']);
            if (is_array($media) && isset($media['schema']) && is_array($media['schema'])) {
                $rawSchema = $media['schema'];
            }
        }
        if ($rawSchema === null) {
            return null;
        }

        return $this->generateValidValueForSchema($rawSchema, $componentsSchemas);
    }

    /**
     * Build valid path parameters matching template and declarations.
     *
     * @param  list<array<string, mixed>>|null  $parameters
     * @param  array<string, mixed>  $componentsSchemas
     * @return array<string, mixed>
     */
    protected function buildValidPathParams(string $path, ?array $parameters, array $componentsSchemas = []): array
    {
        $params = [];
        if (preg_match_all('/(?<!\{)\{([^{}]+)\}(?!\})/', $path, $matches)) {
            foreach ($matches[1] as $paramName) {
                $matchedParam = null;
                if ($parameters !== null) {
                    foreach ($parameters as $p) {
                        if (($p['in'] ?? null) === 'path' && ($p['name'] ?? null) === $paramName) {
                            $matchedParam = $p;
                            break;
                        }
                    }
                }

                if ($matchedParam !== null && isset($matchedParam['schema']) && is_array($matchedParam['schema'])) {
                    $params[$paramName] = $this->generateValidValueForSchema($matchedParam['schema'], $componentsSchemas, $paramName);
                } else {
                    $params[$paramName] = 1;
                }
            }
        }

        return $params;
    }

    /**
     * Build valid query parameters matching endpoint parameter declarations.
     *
     * @param  list<array<string, mixed>>|null  $parameters
     * @param  array<string, mixed>  $componentsSchemas
     * @return array<string, mixed>
     */
    protected function buildValidQueryParams(?array $parameters, array $componentsSchemas): array
    {
        $query = [];
        if ($parameters === null) {
            return $query;
        }

        foreach ($parameters as $param) {
            if (($param['in'] ?? null) !== 'query') {
                continue;
            }

            $name = (string) ($param['name'] ?? '');
            if ($name === '') {
                continue;
            }

            // Always provide required query params; provide optional if they have defaults or constraints
            $isRequired = (bool) ($param['required'] ?? false);
            if ($isRequired || isset($param['schema'])) {
                $paramSchema = is_array($param['schema'] ?? null) ? $param['schema'] : ['type' => 'string'];
                $val = $this->generateValidValueForSchema($paramSchema, $componentsSchemas, $name);
                if ($isRequired || $val !== null) {
                    $query[$name] = $val;
                }
            }
        }

        return $query;
    }

    /**
     * @param  list<array<string, mixed>>|null  $parameters
     * @param  array<string, mixed>  $componentsSchemas
     * @return array<string, string>
     */
    protected function buildValidHeaders(?array $parameters, array $componentsSchemas): array
    {
        $headers = [];
        foreach ($parameters ?? [] as $param) {
            if (($param['in'] ?? null) !== 'header' || empty($param['required'])) {
                continue;
            }
            $name = (string) ($param['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $schema = is_array($param['schema'] ?? null) ? $param['schema'] : ['type' => 'string'];
            $value = $this->generateValidValueForSchema($schema, $componentsSchemas, $name);
            $headers[$name] = is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR);
        }

        return $headers;
    }

    /**
     * Recursively generate valid dummy value conforming to a schema.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $componentsSchemas
     */
    public function generateValidValueForSchema(
        array $schema,
        array $componentsSchemas,
        string $propName = '',
        int $depth = 0
    ): mixed {
        if ($depth > 8) {
            return null;
        }

        if (array_key_exists('const', $schema)) {
            return $schema['const'];
        }

        $resolved = $this->resolveSchema($schema, $componentsSchemas, $depth);

        if (array_key_exists('const', $resolved)) {
            return $resolved['const'];
        }

        // Handle oneOf / anyOf composition: pick conforming branch
        if (isset($resolved['anyOf']) && is_array($resolved['anyOf'])) {
            $parentConstraints = array_diff_key($resolved, ['anyOf' => true, 'oneOf' => true]);
            $fallbackCandidate = null;
            $hasCandidate = false;
            foreach ($resolved['anyOf'] as $branch) {
                if (is_array($branch) && $this->extractSchemaType($branch) !== 'null') {
                    $branchMerged = $this->intersectSchemaConstraints($branch, $parentConstraints);
                    $candidate = $this->generateValidValueForSchema($branchMerged, $componentsSchemas, $propName, $depth + 1);
                    if (! $hasCandidate) {
                        $fallbackCandidate = $candidate;
                        $hasCandidate = true;
                    }
                    if ($this->isValueConformingToSchema($candidate, $resolved, $componentsSchemas)
                        && $this->conformsToJsonSchema($candidate, $schema, $componentsSchemas) !== false
                    ) {
                        return $candidate;
                    }
                }
            }

            return $fallbackCandidate;
        }
        if (isset($resolved['oneOf']) && is_array($resolved['oneOf'])) {
            $parentConstraints = array_diff_key($resolved, ['oneOf' => true, 'anyOf' => true]);
            $fallbackCandidate = null;
            $hasCandidate = false;
            foreach ($resolved['oneOf'] as $branch) {
                if (! is_array($branch) || $this->extractSchemaType($branch) === 'null') {
                    continue;
                }
                $branchMerged = $this->intersectSchemaConstraints($branch, $parentConstraints);
                $candidate = $this->generateValidValueForSchema($branchMerged, $componentsSchemas, $propName, $depth + 1);
                if (! $hasCandidate) {
                    $fallbackCandidate = $candidate;
                    $hasCandidate = true;
                }
                if ($this->isValueConformingToSchema($candidate, $resolved, $componentsSchemas)
                    && $this->conformsToJsonSchema($candidate, $schema, $componentsSchemas) === true
                ) {
                    return $candidate;
                }
                if (is_numeric($candidate) && $this->extractSchemaType($branch) === 'number') {
                    $fractionalCandidate = (float) $candidate + 0.5;
                    if ($this->isValueConformingToSchema($fractionalCandidate, $resolved, $componentsSchemas)
                        && $this->conformsToJsonSchema($fractionalCandidate, $schema, $componentsSchemas) === true
                    ) {
                        return $fractionalCandidate;
                    }
                }
            }

            return $fallbackCandidate;
        }

        if (isset($resolved['enum']) && is_array($resolved['enum']) && count($resolved['enum']) > 0) {
            foreach ($resolved['enum'] as $enumVal) {
                if (! $this->isValueConformingToSchema($enumVal, $resolved, $componentsSchemas)) {
                    continue;
                }
                if ($this->conformsToJsonSchema($enumVal, $schema, $componentsSchemas) === false) {
                    continue;
                }
                if (is_string($enumVal) && ! $this->isValidStringForSchema($enumVal, $resolved, $propName)) {
                    continue;
                }

                return $enumVal;
            }

            return $resolved['enum'][0];
        }

        $propLower = strtolower($propName);
        // Unique-looking fields get a fresh value only when the operation writes them (create /
        // update); a lookup operation (e.g. POST /coupons/apply body.code) must use the declared
        // example, since any other value names nothing that exists.
        $isUniqueField = $this->operationWritesUniqueFields && (str_contains($propLower, 'slug')
            || str_contains($propLower, 'sku')
            || str_contains($propLower, 'email')
            || str_contains($propLower, 'code'));

        if (! $isUniqueField) {
            $candidateValues = [];
            if (array_key_exists('example', $schema)) {
                $candidateValues[] = $schema['example'];
            }
            if (isset($schema['examples']) && is_array($schema['examples'])) {
                foreach ($schema['examples'] as $ex) {
                    $candidateValues[] = $ex;
                }
            }
            if (array_key_exists('default', $schema)) {
                $candidateValues[] = $schema['default'];
            }
            if (array_key_exists('example', $resolved) && ! array_key_exists('example', $schema)) {
                $candidateValues[] = $resolved['example'];
            }
            if (isset($resolved['examples']) && is_array($resolved['examples']) && ! isset($schema['examples'])) {
                foreach ($resolved['examples'] as $ex) {
                    $candidateValues[] = $ex;
                }
            }
            if (array_key_exists('default', $resolved) && ! array_key_exists('default', $schema)) {
                $candidateValues[] = $resolved['default'];
            }

            foreach ($candidateValues as $candidateValue) {
                if ($this->conformsToJsonSchema($candidateValue, $schema, $componentsSchemas) === true
                    && $this->isValueConformingToSchema($candidateValue, $resolved, $componentsSchemas)
                ) {
                    return $candidateValue;
                }
            }
        }

        $type = $this->extractSchemaType($resolved);

        $val = match ($type) {
            'integer' => $this->generateValidInteger($resolved),
            'number' => $this->generateValidNumber($resolved),
            'boolean' => true,
            'array' => $this->generateValidArray($resolved, $componentsSchemas, $propName, $depth),
            'object' => $this->generateValidObject($resolved, $componentsSchemas, $depth),
            default => $this->generateValidString($resolved, $propName),
        };

        if ($this->conformsToJsonSchema($val, $schema, $componentsSchemas) === false
            || ! $this->isValueConformingToSchema($val, $resolved, $componentsSchemas)
        ) {
            if ($type === 'integer' && is_int($val)) {
                foreach ([1, 2, 3, -1, 4, -2, 5, -3, 6, -4, 7, 8, 9, 10] as $offset) {
                    $alt = $val + $offset;
                    if ($this->isValueConformingToSchema($alt, $resolved, $componentsSchemas)
                        && $this->conformsToJsonSchema($alt, $schema, $componentsSchemas) === true
                    ) {
                        return $alt;
                    }
                }
            } elseif ($type === 'number' && is_numeric($val)) {
                $step = isset($resolved['multipleOf']) && is_numeric($resolved['multipleOf']) ? (float) $resolved['multipleOf'] : 0.5;
                foreach ([1, 2, 3, -1, 4, -2, 5] as $offset) {
                    $alt = $val + ($offset * $step);
                    if ($this->isValueConformingToSchema($alt, $resolved, $componentsSchemas)
                        && $this->conformsToJsonSchema($alt, $schema, $componentsSchemas) === true
                    ) {
                        return $alt;
                    }
                }
            }
        }

        return $val;
    }

    /**
     * Check if a schema contains intrinsically impossible constraints (e.g. minimum > maximum).
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $componentsSchemas
     */
    public function hasImpossibleConstraints(array $schema, array $componentsSchemas = []): bool
    {
        $resolved = $this->resolveSchema($schema, $componentsSchemas);

        // 1. Integer bounds
        $min = null;
        $max = null;
        if ($this->extractSchemaType($resolved) === 'integer') {
            [$min, $max] = $this->resolveIntegerBounds($resolved);
            if ($min !== null && $max !== null && $min > $max) {
                return true;
            }
        }

        // 2. Numeric bounds
        $numMin = isset($resolved['minimum']) && is_numeric($resolved['minimum']) ? (float) $resolved['minimum'] : null;
        $numMax = isset($resolved['maximum']) && is_numeric($resolved['maximum']) ? (float) $resolved['maximum'] : null;
        if (isset($resolved['exclusiveMinimum']) && is_numeric($resolved['exclusiveMinimum'])) {
            $excMin = (float) $resolved['exclusiveMinimum'];
            $numMin = $numMin === null ? $excMin : max($numMin, $excMin);
        }
        if (isset($resolved['exclusiveMaximum']) && is_numeric($resolved['exclusiveMaximum'])) {
            $excMax = (float) $resolved['exclusiveMaximum'];
            $numMax = $numMax === null ? $excMax : min($numMax, $excMax);
        }
        if ($numMin !== null && $numMax !== null) {
            if ($numMin > $numMax) {
                return true;
            }
            if ($numMin == $numMax) {
                if (isset($resolved['exclusiveMinimum']) && is_numeric($resolved['exclusiveMinimum']) && $numMin <= (float) $resolved['exclusiveMinimum']) {
                    return true;
                }
                if (isset($resolved['exclusiveMaximum']) && is_numeric($resolved['exclusiveMaximum']) && $numMax >= (float) $resolved['exclusiveMaximum']) {
                    return true;
                }
            }
        }

        // 3. multipleOf inside bounds
        if (isset($resolved['multipleOf']) && is_numeric($resolved['multipleOf'])) {
            $isInteger = $this->extractSchemaType($resolved) === 'integer';
            if ($min !== null || $max !== null || $numMin !== null || $numMax !== null) {
                $ref = (float) ($min ?? $numMin ?? $max ?? $numMax ?? 1);
                if ($this->chooseNumericMultiple($resolved, $ref, $isInteger) === null) {
                    return true;
                }
            }
        }

        // 4. String length bounds
        $minLen = isset($resolved['minLength']) && is_numeric($resolved['minLength']) ? (int) $resolved['minLength'] : null;
        $maxLen = isset($resolved['maxLength']) && is_numeric($resolved['maxLength']) ? (int) $resolved['maxLength'] : null;
        if ($minLen !== null && $maxLen !== null && $minLen > $maxLen) {
            return true;
        }

        // 5. Array item count bounds
        $minItems = isset($resolved['minItems']) && is_numeric($resolved['minItems']) ? (int) $resolved['minItems'] : null;
        $maxItems = isset($resolved['maxItems']) && is_numeric($resolved['maxItems']) ? (int) $resolved['maxItems'] : null;
        if ($minItems !== null && $maxItems !== null && $minItems > $maxItems) {
            return true;
        }

        // 6. Object properties bounds
        $minProps = isset($resolved['minProperties']) && is_numeric($resolved['minProperties']) ? (int) $resolved['minProperties'] : null;
        $maxProps = isset($resolved['maxProperties']) && is_numeric($resolved['maxProperties']) ? (int) $resolved['maxProperties'] : null;
        if ($minProps !== null && $maxProps !== null && $minProps > $maxProps) {
            return true;
        }

        // 7. Check required object properties recursively
        if (isset($resolved['properties']) && is_array($resolved['properties'])) {
            $required = isset($resolved['required']) && is_array($resolved['required']) ? $resolved['required'] : [];
            foreach ($resolved['properties'] as $pName => $pDef) {
                if (is_array($pDef) && in_array($pName, $required, true)) {
                    if ($this->hasImpossibleConstraints($pDef, $componentsSchemas)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Resolve effective integer minimum and maximum bounds by intersecting inclusive and exclusive limits.
     *
     * @param  array<string, mixed>  $schema
     * @return array{0: ?int, 1: ?int}
     */
    protected function resolveIntegerBounds(array $schema): array
    {
        $minStr = null;
        if (isset($schema['minimum']) && is_numeric($schema['minimum'])) {
            $minStr = $this->integerCeil($schema['minimum']);
        }

        if (isset($schema['exclusiveMinimum'])) {
            $rawExc = null;
            if (is_numeric($schema['exclusiveMinimum'])) {
                $rawExc = $schema['exclusiveMinimum'];
            } elseif ($schema['exclusiveMinimum'] === true && isset($schema['minimum']) && is_numeric($schema['minimum'])) {
                $rawExc = $schema['minimum'];
            }
            if ($rawExc !== null) {
                $floorStr = $this->integerFloor($rawExc);
                if ($floorStr !== null) {
                    /** @var numeric-string $excMinStr */
                    $excMinStr = bcadd($floorStr, '1', 0);
                    $minStr = $minStr === null ? $excMinStr : (bccomp($minStr, $excMinStr, 0) >= 0 ? $minStr : $excMinStr);
                }
            }
        }

        $maxStr = null;
        if (isset($schema['maximum']) && is_numeric($schema['maximum'])) {
            $maxStr = $this->integerFloor($schema['maximum']);
        }

        if (isset($schema['exclusiveMaximum'])) {
            $rawExc = null;
            if (is_numeric($schema['exclusiveMaximum'])) {
                $rawExc = $schema['exclusiveMaximum'];
            } elseif ($schema['exclusiveMaximum'] === true && isset($schema['maximum']) && is_numeric($schema['maximum'])) {
                $rawExc = $schema['maximum'];
            }
            if ($rawExc !== null) {
                $ceilStr = $this->integerCeil($rawExc);
                if ($ceilStr !== null) {
                    /** @var numeric-string $excMaxStr */
                    $excMaxStr = bcsub($ceilStr, '1', 0);
                    $maxStr = $maxStr === null ? $excMaxStr : (bccomp($maxStr, $excMaxStr, 0) <= 0 ? $maxStr : $excMaxStr);
                }
            }
        }

        /** @var numeric-string $intMinStr */
        $intMinStr = (string) PHP_INT_MIN;
        /** @var numeric-string $intMaxStr */
        $intMaxStr = (string) PHP_INT_MAX;

        $min = null;
        if ($minStr !== null) {
            if (bccomp($minStr, $intMaxStr, 0) > 0) {
                $min = PHP_INT_MAX;
                $maxStr = $intMinStr;
            } elseif (bccomp($minStr, $intMinStr, 0) < 0) {
                $min = PHP_INT_MIN;
            } else {
                $min = (int) $minStr;
            }
        }

        $max = null;
        if ($maxStr !== null) {
            if (bccomp($maxStr, $intMinStr, 0) < 0) {
                $max = PHP_INT_MIN;
                $min = PHP_INT_MAX;
            } elseif (bccomp($maxStr, $intMaxStr, 0) > 0) {
                $max = PHP_INT_MAX;
            } else {
                $max = (int) $maxStr;
            }
        }

        return [$min, $max];
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    protected function generateValidInteger(array $schema): int
    {
        [$min, $max] = $this->resolveIntegerBounds($schema);

        if ($min !== null && $max !== null) {
            if ($min <= $max) {
                // The distance between two valid int64 bounds can exceed PHP_INT_MAX.
                $distance = bcsub((string) $max, (string) $min, 0);
                $preferred = (int) bcadd((string) $min, bcdiv($distance, '2', 0), 0);
            } else {
                $preferred = $min;
            }
        } elseif ($min !== null) {
            $preferred = $min > 1 ? $min : ($min <= 0 ? 1 : $min);
        } elseif ($max !== null) {
            $preferred = $max < 1 ? $max : 1;
        } else {
            $preferred = 1;
        }

        return (int) ($this->chooseNumericMultiple($schema, $preferred, true) ?? $preferred);
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    protected function generateValidNumber(array $schema): float
    {
        $min = isset($schema['minimum']) && is_numeric($schema['minimum']) ? (float) $schema['minimum'] : null;
        $max = isset($schema['maximum']) && is_numeric($schema['maximum']) ? (float) $schema['maximum'] : null;

        $excMin = null;
        if (isset($schema['exclusiveMinimum'])) {
            if (is_numeric($schema['exclusiveMinimum'])) {
                $excMin = (float) $schema['exclusiveMinimum'];
            } elseif ($schema['exclusiveMinimum'] === true && $min !== null) {
                $excMin = $min;
            }
        }

        $excMax = null;
        if (isset($schema['exclusiveMaximum'])) {
            if (is_numeric($schema['exclusiveMaximum'])) {
                $excMax = (float) $schema['exclusiveMaximum'];
            } elseif ($schema['exclusiveMaximum'] === true && $max !== null) {
                $excMax = $max;
            }
        }

        $effectiveLower = $excMin !== null ? ($min !== null ? max($min, $excMin) : $excMin) : $min;
        $effectiveUpper = $excMax !== null ? ($max !== null ? min($max, $excMax) : $excMax) : $max;

        if ($effectiveLower !== null && $effectiveUpper !== null) {
            $preferred = ($effectiveLower + $effectiveUpper) / 2;
        } elseif ($effectiveLower !== null) {
            $step = isset($schema['multipleOf']) && is_numeric($schema['multipleOf']) ? (float) $schema['multipleOf'] : 0.5;
            $preferred = (float) ($effectiveLower + $step);
        } elseif ($effectiveUpper !== null) {
            $step = isset($schema['multipleOf']) && is_numeric($schema['multipleOf']) ? (float) $schema['multipleOf'] : 0.5;
            $preferred = (float) ($effectiveUpper - $step);
        } else {
            $preferred = 9.99;
        }

        return $this->chooseNumericMultiple($schema, $preferred, false) ?? $preferred;
    }

    /**
     * Pick a multiple inside the intersection of all numeric bounds.
     * Integer schemas use the numerator of the reduced decimal multiple as their step.
     *
     * @param  array<string, mixed>  $schema
     */
    protected function chooseNumericMultiple(array $schema, float|int $preferred, bool $integer): float|int|null
    {
        if (! isset($schema['multipleOf']) || ! is_numeric($schema['multipleOf'])) {
            return $preferred;
        }
        $step = $schema['multipleOf'];
        if ((float) $step <= 0 || ! is_finite((float) $step)) {
            return null;
        }
        if ($integer) {
            return $this->chooseIntegerMultiple($schema, (int) $preferred, $step);
        }

        $lower = -INF;
        $upper = INF;
        if (isset($schema['minimum']) && is_numeric($schema['minimum'])) {
            $lower = ceil((float) $schema['minimum'] / (float) $step - 1e-10);
        }
        if (isset($schema['maximum']) && is_numeric($schema['maximum'])) {
            $upper = floor((float) $schema['maximum'] / (float) $step + 1e-10);
        }
        foreach (['exclusiveMinimum', 'exclusiveMaximum'] as $key) {
            $bound = $schema[$key] ?? null;
            if ($bound === true) {
                $bound = $schema[$key === 'exclusiveMinimum' ? 'minimum' : 'maximum'] ?? null;
            }
            if (! is_numeric($bound)) {
                continue;
            }
            if ($key === 'exclusiveMinimum') {
                $lower = max($lower, floor((float) $bound / (float) $step + 1e-10) + 1);
            } else {
                $upper = min($upper, ceil((float) $bound / (float) $step - 1e-10) - 1);
            }
        }
        if ($lower > $upper) {
            return null;
        }
        $index = min($upper, max($lower, round((float) $preferred / (float) $step)));
        $value = $index * (float) $step;

        return is_finite($value) ? $value : null;
    }

    /**
     * Pick an integer multiple inside the intersection of all integer bounds using exact arithmetic.
     *
     * @param  array<string, mixed>  $schema
     */
    protected function chooseIntegerMultiple(array $schema, int $preferred, float|int|string $rawStep): ?int
    {
        $intStep = $this->getIntegerLatticeStep($rawStep);
        if ($intStep <= 0) {
            return null;
        }
        $stepStr = (string) $intStep;

        $minStr = null;
        if (isset($schema['minimum']) && is_numeric($schema['minimum'])) {
            $minStr = $this->integerCeil($schema['minimum']);
        }
        if (isset($schema['exclusiveMinimum'])) {
            $rawExc = null;
            if (is_numeric($schema['exclusiveMinimum'])) {
                $rawExc = $schema['exclusiveMinimum'];
            } elseif ($schema['exclusiveMinimum'] === true && isset($schema['minimum']) && is_numeric($schema['minimum'])) {
                $rawExc = $schema['minimum'];
            }
            if ($rawExc !== null) {
                $floorStr = $this->integerFloor($rawExc);
                if ($floorStr !== null) {
                    /** @var numeric-string $excMinStr */
                    $excMinStr = bcadd($floorStr, '1', 0);
                    $minStr = $minStr === null ? $excMinStr : (bccomp($minStr, $excMinStr, 0) >= 0 ? $minStr : $excMinStr);
                }
            }
        }

        $maxStr = null;
        if (isset($schema['maximum']) && is_numeric($schema['maximum'])) {
            $maxStr = $this->integerFloor($schema['maximum']);
        }
        if (isset($schema['exclusiveMaximum'])) {
            $rawExc = null;
            if (is_numeric($schema['exclusiveMaximum'])) {
                $rawExc = $schema['exclusiveMaximum'];
            } elseif ($schema['exclusiveMaximum'] === true && isset($schema['maximum']) && is_numeric($schema['maximum'])) {
                $rawExc = $schema['maximum'];
            }
            if ($rawExc !== null) {
                $ceilStr = $this->integerCeil($rawExc);
                if ($ceilStr !== null) {
                    /** @var numeric-string $excMaxStr */
                    $excMaxStr = bcsub($ceilStr, '1', 0);
                    $maxStr = $maxStr === null ? $excMaxStr : (bccomp($maxStr, $excMaxStr, 0) <= 0 ? $maxStr : $excMaxStr);
                }
            }
        }

        /** @var numeric-string $intMinStr */
        $intMinStr = (string) PHP_INT_MIN;
        /** @var numeric-string $intMaxStr */
        $intMaxStr = (string) PHP_INT_MAX;

        $effectiveMin = $minStr !== null ? (bccomp($minStr, $intMinStr, 0) >= 0 ? $minStr : $intMinStr) : $intMinStr;
        $effectiveMax = $maxStr !== null ? (bccomp($maxStr, $intMaxStr, 0) <= 0 ? $maxStr : $intMaxStr) : $intMaxStr;

        if ($minStr !== null && bccomp($minStr, $intMaxStr, 0) > 0) {
            return null;
        }
        if ($maxStr !== null && bccomp($maxStr, $intMinStr, 0) < 0) {
            return null;
        }
        if (bccomp($effectiveMin, $effectiveMax, 0) > 0) {
            return null;
        }

        $kMin = $this->quotientCeil($effectiveMin, $stepStr);
        $kMax = $this->quotientFloor($effectiveMax, $stepStr);
        if (bccomp($kMin, $kMax, 0) > 0) {
            return null;
        }

        $prefStr = (string) $preferred;
        /** @var numeric-string $q */
        $q = bcdiv($prefStr, $stepStr, 0);
        $rem = bcmod($prefStr, $stepStr);
        if ($rem === '0') {
            $kPref = $q;
        } else {
            /** @var numeric-string $absRem */
            $absRem = bccomp($rem, '0', 0) < 0 ? bcsub('0', $rem, 0) : $rem;
            /** @var numeric-string $twiceAbsRem */
            $twiceAbsRem = bcmul('2', $absRem, 0);
            if (bccomp($twiceAbsRem, $stepStr, 0) < 0) {
                $kPref = $q;
            } else {
                /** @var numeric-string $kPref */
                $kPref = bccomp($prefStr, '0', 0) > 0 ? bcadd($q, '1', 0) : bcsub($q, '1', 0);
            }
        }

        if (bccomp($kPref, $kMin, 0) < 0) {
            $kPref = $kMin;
        } elseif (bccomp($kPref, $kMax, 0) > 0) {
            $kPref = $kMax;
        }

        /** @var numeric-string $exactVal */
        $exactVal = bcmul($kPref, $stepStr, 0);

        if (bccomp($exactVal, $intMinStr, 0) < 0 || bccomp($exactVal, $intMaxStr, 0) > 0) {
            return null;
        }

        if (($minStr !== null && bccomp($exactVal, $minStr, 0) < 0)
            || ($maxStr !== null && bccomp($exactVal, $maxStr, 0) > 0)) {
            return null;
        }

        return (int) $exactVal;
    }

    /** @param array<string, mixed> $schema */
    protected function numericBoundaryValue(array $schema, bool $minimum): int|float
    {
        $integer = $this->extractSchemaType($schema) === 'integer';
        if ($integer) {
            [$min, $max] = $this->resolveIntegerBounds($schema);
            $preferred = $minimum ? ($min ?? 0) : ($max ?? 100);
        } else {
            $preferred = (float) $schema[$minimum ? 'minimum' : 'maximum'];
        }
        $value = $this->chooseNumericMultiple($schema, $preferred, $integer) ?? $preferred;

        return $integer ? (int) $value : $value;
    }

    /** @param array<string, mixed> $schema */
    protected function conformsToMultipleOf(int|float $value, array $schema): bool
    {
        if (! isset($schema['multipleOf'])) {
            return true;
        }
        if (! is_numeric($schema['multipleOf']) || (float) $schema['multipleOf'] <= 0) {
            return false;
        }

        return $this->isConformingMultiple($value, $schema['multipleOf']);
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    protected function generateValidString(array $schema, string $propName): string
    {
        $cnt = ++$this->uniqueCounter;

        $minLen = isset($schema['minLength']) && is_numeric($schema['minLength']) ? (int) $schema['minLength'] : 1;
        $maxLen = isset($schema['maxLength']) && is_numeric($schema['maxLength']) ? (int) $schema['maxLength'] : null;
        $pattern = isset($schema['pattern']) && is_string($schema['pattern']) ? $schema['pattern'] : null;

        if ($pattern !== null) {
            $matched = $this->generateStringMatchingPattern($pattern, $cnt, $minLen, $maxLen);
            if ($matched !== null) {
                return $matched;
            }
        }

        $format = $schema['format'] ?? null;
        $propLower = strtolower($propName);

        if ($format === 'email' || str_contains($propLower, 'email')) {
            return $this->generateValidEmail($cnt, $minLen, $maxLen);
        }

        if ($format === 'date') {
            return '2026-01-01';
        }
        if ($format === 'date-time') {
            return '2026-01-01T12:00:00Z';
        }
        if ($format === 'uuid') {
            return sprintf('123e4567-e89b-12d3-a456-%012d', $cnt);
        }
        if ($format === 'uri' || $format === 'url') {
            $uri = "https://example.com/api/{$cnt}";
            if ($maxLen !== null && strlen($uri) > $maxLen) {
                $uri = "http://a.co/{$cnt}";
                if (strlen($uri) > $maxLen) {
                    $uri = 'http://a.co';
                }
            }

            return $uri;
        }

        if (str_contains($propLower, 'slug')) {
            return $this->generateValidSlug($cnt, $minLen, $maxLen);
        }
        if (str_contains($propLower, 'sku')) {
            return $this->generateValidSku($cnt, $minLen, $maxLen);
        }

        $base = $propName !== '' ? 'valid_'.$propName : 'valid_string';
        if ($maxLen !== null && strlen($base) > $maxLen) {
            $base = 'v'.($cnt % 1000);
            if (strlen($base) > $maxLen) {
                $base = substr($base, 0, $maxLen);
            }
        }
        if (strlen($base) < $minLen) {
            $base = str_pad($base, $minLen, 'a');
        }

        return $base;
    }

    protected function generateStringMatchingPattern(string $pattern, int $cnt, int $minLen = 1, ?int $maxLen = null): ?string
    {
        $candidate = null;

        // 1. Prefix + digits with exact count: e.g. ^PROD-[0-9]{3}$ or ^ITEM-[0-9]{4}$
        if (preg_match('/^\^?([a-zA-Z0-9_-]+)\[0-9\]\{(\d+)\}\$?$/', $pattern, $pm)) {
            $prefix = $pm[1];
            $digitsCount = (int) $pm[2];
            $maxNum = (10 ** $digitsCount) - 1;
            // Pick a high number avoiding 001, 002 seeded records
            $num = min($maxNum, max(1, (int) floor($maxNum * 0.8) + ($cnt % max(1, (int) floor($maxNum * 0.19)))));

            $candidate = $prefix.str_pad((string) $num, $digitsCount, '0', STR_PAD_LEFT);
        }
        // 2. Exact length pattern for digits: ^[0-9]{N}$ or ^\d{N}$
        elseif (preg_match('/^\^?\[0-9\]\{(\d+)\}\$?$/', $pattern, $pm) || preg_match('/^\^?\\\\d\{(\d+)\}\$?$/', $pattern, $pm)) {
            $digitsCount = (int) $pm[1];
            $mod = 10 ** min($digitsCount, 9);
            $candidate = str_pad((string) ($cnt % $mod), $digitsCount, '0', STR_PAD_LEFT);
        }
        // 3. Exact length uppercase letters: ^[A-Z]{N}$
        elseif (preg_match('/^\^?\[A-Z\]\{(\d+)\}\$?$/', $pattern, $pm)) {
            $charCount = (int) $pm[1];
            $candidate = '';
            $num = $cnt;
            for ($ci = 0; $ci < $charCount; $ci++) {
                $candidate = chr(65 + ($num % 26)).$candidate;
                $num = intdiv($num, 26);
            }
        }
        // 4. Exact length lowercase letters: ^[a-z]{N}$
        elseif (preg_match('/^\^?\[a-z\]\{(\d+)\}\$?$/', $pattern, $pm)) {
            $charCount = (int) $pm[1];
            $candidate = '';
            $num = $cnt;
            for ($ci = 0; $ci < $charCount; $ci++) {
                $candidate = chr(97 + ($num % 26)).$candidate;
                $num = intdiv($num, 26);
            }
        }
        // 5. SHA-256 / hex checksum: e.g. ^[a-f0-9]{64}$ or hex 64 chars
        elseif (preg_match('/\[[a-f0-9A-F_-]+\]\{64\}/', $pattern) || str_contains($pattern, '^[a-f0-9]{64}$') || str_contains($pattern, '^[a-fA-F0-9]{64}$') || str_contains(strtolower($pattern), 'sha256')) {
            $candidate = hash('sha256', "ait_seed_{$cnt}");
        }
        // 6. UUID pattern: 8-4-4-4-12
        elseif (str_contains($pattern, '[0-9a-fA-F]{8}-') || str_contains($pattern, '[a-f0-9]{8}-') || str_contains(strtolower($pattern), 'uuid')) {
            $candidate = sprintf('123e4567-e89b-12d3-a456-%012d', $cnt);
        }
        // 7. Slug pattern: ^[a-z0-9]+(?:-[a-z0-9]+)*$ or ^[a-z0-9_-]+$
        elseif (str_contains($pattern, '^[a-z0-9]+(?:-[a-z0-9]+)*$') || str_contains($pattern, '^[a-z0-9_-]+$')) {
            $candidate = $this->generateValidSlug($cnt, $minLen, $maxLen);
        }
        // 8. Uppercase SKU / code pattern: ^[A-Z0-9_-]+$ or ^[A-Z0-9_]+$
        elseif (str_contains($pattern, '^[A-Z0-9_-]+$') || str_contains($pattern, '^[A-Z0-9_]+$')) {
            $candidate = $this->generateValidSku($cnt, $minLen, $maxLen);
        }
        // 9. Path starting with slash: ^/
        elseif (str_starts_with($pattern, '^/')) {
            $candidate = "/path_{$cnt}";
        }
        // 10. General digits: ^[0-9]+$ or ^\d+$
        elseif (str_contains($pattern, '^[0-9]+$') || str_contains($pattern, '^\d+$')) {
            $targetLen = $maxLen !== null ? min($maxLen, max($minLen, 1)) : max($minLen, 1);
            $mod = 10 ** min($targetLen, 9);
            $candidate = str_pad((string) ($cnt % $mod), $targetLen, '0', STR_PAD_LEFT);
        }
        // 11. General uppercase: ^[A-Z]+$
        elseif (str_contains($pattern, '^[A-Z]+$')) {
            $targetLen = $maxLen !== null ? min($maxLen, max($minLen, 1)) : max($minLen, 1);
            $candidate = '';
            $num = $cnt;
            for ($ci = 0; $ci < $targetLen; $ci++) {
                $candidate = chr(65 + ($num % 26)).$candidate;
                $num = intdiv($num, 26);
            }
        }
        // 12. General lowercase: ^[a-z]+$
        elseif (str_contains($pattern, '^[a-z]+$')) {
            $targetLen = $maxLen !== null ? min($maxLen, max($minLen, 1)) : max($minLen, 1);
            $candidate = '';
            $num = $cnt;
            for ($ci = 0; $ci < $targetLen; $ci++) {
                $candidate = chr(97 + ($num % 26)).$candidate;
                $num = intdiv($num, 26);
            }
        }

        if ($candidate !== null) {
            $delimiter = '#';
            $patternForRegex = str_replace('#', '\#', $pattern);
            if (! str_starts_with($patternForRegex, '^')) {
                $patternForRegex = '^'.$patternForRegex;
            }
            if (! str_ends_with($patternForRegex, '$')) {
                $patternForRegex = $patternForRegex.'$';
            }
            if (@preg_match($delimiter.$patternForRegex.$delimiter, $candidate) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    protected function generateValidEmail(int $cnt, int $minLen = 1, ?int $maxLen = null): string
    {
        $standard = "tester{$cnt}@example.com";
        if ($maxLen === null || strlen($standard) <= $maxLen) {
            $email = $standard;
        } elseif ($maxLen >= 15) {
            // Tight constraint: e.g. maxLength: 20 -> 'u119@ex.co' (10 chars <= 20)
            $digitsAllowed = max(1, min(4, $maxLen - 7));
            $mod = 10 ** $digitsAllowed;
            $email = 'u'.($cnt % $mod).'@ex.co';
            if (strlen($email) > $maxLen) {
                $email = 'u@ex.co';
            }
        } else {
            // Extremely tight constraint: e.g. maxLength: 10 -> 'u1@a.io' (7 chars)
            $digitsAllowed = max(1, min(2, max(1, $maxLen - 6)));
            $mod = 10 ** $digitsAllowed;
            $email = 'u'.($cnt % $mod).'@a.io';
            if (strlen($email) > $maxLen) {
                $email = substr('u', 0, max(1, $maxLen - 5)).'@a.io';
            }
        }

        if (strlen($email) < $minLen) {
            $parts = explode('@', $email, 2);
            $needed = $minLen - strlen($email);
            $email = $parts[0].str_repeat('x', $needed).'@'.$parts[1];
        }

        return $email;
    }

    protected function generateValidSlug(int $cnt, int $minLen = 1, ?int $maxLen = null): string
    {
        if ($maxLen !== null && $maxLen <= 8) {
            // Short slug for tight maxLength constraints (e.g. maxLength: 5 -> 's101')
            $digitsAllowed = max(1, min($maxLen - 1, 4));
            $mod = 10 ** $digitsAllowed;
            $slug = 's'.($cnt % $mod);
            if (strlen($slug) > $maxLen) {
                $slug = substr($slug, 0, $maxLen);
            }
        } else {
            $slug = 'cat-'.($cnt % 900 + 100);
            if ($maxLen !== null && strlen($slug) > $maxLen) {
                $slug = substr($slug, 0, $maxLen);
            }
        }

        if (strlen($slug) < $minLen) {
            $slug = str_pad($slug, $minLen, 'a');
        }

        return $slug;
    }

    protected function generateValidSku(int $cnt, int $minLen = 1, ?int $maxLen = null): string
    {
        if ($maxLen !== null && $maxLen <= 8) {
            $digitsAllowed = max(1, min($maxLen - 1, 4));
            $mod = 10 ** $digitsAllowed;
            $sku = 'K'.($cnt % $mod);
            if (strlen($sku) > $maxLen) {
                $sku = substr($sku, 0, $maxLen);
            }
        } else {
            $sku = 'SKU_'.($cnt % 900 + 100);
            if ($maxLen !== null && strlen($sku) > $maxLen) {
                $sku = substr($sku, 0, $maxLen);
            }
        }

        if (strlen($sku) < $minLen) {
            $sku = str_pad($sku, $minLen, '0');
        }

        return $sku;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $componentsSchemas
     * @return list<mixed>
     */
    protected function generateValidArray(array $schema, array $componentsSchemas, string $propName, int $depth): array
    {
        $itemsSchema = is_array($schema['items'] ?? null) ? $schema['items'] : ['type' => 'string'];

        $minItems = isset($schema['minItems']) && is_numeric($schema['minItems']) ? (int) $schema['minItems'] : null;
        $maxItems = isset($schema['maxItems']) && is_numeric($schema['maxItems']) ? (int) $schema['maxItems'] : null;
        $uniqueItems = ! empty($schema['uniqueItems']);

        $count = 1;
        if ($minItems !== null) {
            $count = max($count, $minItems);
        }
        if ($maxItems !== null) {
            $count = min($count, $maxItems);
            if ($minItems === null && $maxItems === 0) {
                $count = 0;
            }
        }

        $items = [];
        $seen = [];

        $resolvedItems = $this->resolveSchema($itemsSchema, $componentsSchemas);
        $itemType = $this->extractSchemaType($resolvedItems);
        $enumVals = isset($resolvedItems['enum']) && is_array($resolvedItems['enum'])
            ? array_values(array_unique($resolvedItems['enum']))
            : null;

        for ($i = 0; $i < $count; $i++) {
            if ($uniqueItems) {
                if ($enumVals !== null && count($enumVals) > 0) {
                    if ($i < count($enumVals)) {
                        $item = $enumVals[$i];
                    } else {
                        break;
                    }
                } elseif ($itemType === 'boolean') {
                    if ($i === 0) {
                        $item = true;
                    } elseif ($i === 1) {
                        $item = false;
                    } else {
                        break;
                    }
                } elseif ($itemType === 'string' && (($resolvedItems['format'] ?? null) === 'uuid')) {
                    $item = sprintf('123e4567-e89b-12d3-a456-%012d', ++$this->uniqueCounter);
                } elseif ($itemType === 'integer') {
                    $baseInt = $this->generateValidInteger($resolvedItems);
                    $item = $this->generateUniqueInteger($resolvedItems, $baseInt, $i, $items, $componentsSchemas);
                } elseif ($itemType === 'number') {
                    $baseNum = $this->generateValidNumber($resolvedItems);
                    $item = $this->generateUniqueNumber($resolvedItems, $baseNum, $i, $items, $componentsSchemas);
                } else {
                    $item = $this->generateUniqueItem($itemsSchema, $resolvedItems, $componentsSchemas, $propName, $depth, $i, $seen);
                }
            } else {
                $item = $this->generateValidValueForSchema(
                    $itemsSchema,
                    $componentsSchemas,
                    $propName.'_item_'.($i + 1),
                    $depth + 1
                );
            }

            $serialized = json_encode($item);
            if ($uniqueItems && in_array($serialized, $seen, true)) {
                continue;
            }

            $seen[] = $serialized;
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<mixed>  $existing
     * @param  array<string, mixed>  $componentsSchemas
     */
    protected function generateUniqueInteger(array $schema, int $base, int $index, array $existing, array $componentsSchemas = []): int
    {
        [$min, $max] = $this->resolveIntegerBounds($schema);

        if (! $this->isValueConformingToSchema($base, $schema, $componentsSchemas)) {
            $base = $this->generateValidInteger($schema);
        }

        if (! in_array($base, $existing, true) && $this->isValueConformingToSchema($base, $schema, $componentsSchemas)) {
            return $base;
        }

        $step = 1;
        if (isset($schema['multipleOf']) && is_numeric($schema['multipleOf'])) {
            $step = $this->getIntegerLatticeStep($schema['multipleOf']);
        }

        for ($offset = 1; $offset <= 100; $offset++) {
            $delta = bcmul((string) $offset, (string) $step, 0);
            foreach ([bcadd((string) $base, $delta, 0), bcsub((string) $base, $delta, 0)] as $exactCandidate) {
                // Check each direction independently, including distances larger than int64.
                if (bccomp($exactCandidate, (string) PHP_INT_MIN, 0) < 0
                    || bccomp($exactCandidate, (string) PHP_INT_MAX, 0) > 0) {
                    continue;
                }
                $candidate = (int) $exactCandidate;
                if (($min === null || $candidate >= $min)
                    && ($max === null || $candidate <= $max)
                    && ! in_array($candidate, $existing, true)
                    && $this->isValueConformingToSchema($candidate, $schema, $componentsSchemas)
                ) {
                    return $candidate;
                }
            }
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<mixed>  $existing
     * @param  array<string, mixed>  $componentsSchemas
     */
    protected function generateUniqueNumber(array $schema, float $base, int $index, array $existing, array $componentsSchemas = []): float
    {
        $min = isset($schema['minimum']) && is_numeric($schema['minimum']) ? (float) $schema['minimum'] : null;
        $max = isset($schema['maximum']) && is_numeric($schema['maximum']) ? (float) $schema['maximum'] : null;

        $excMin = null;
        if (isset($schema['exclusiveMinimum'])) {
            if (is_numeric($schema['exclusiveMinimum'])) {
                $excMin = (float) $schema['exclusiveMinimum'];
            } elseif ($schema['exclusiveMinimum'] === true && $min !== null) {
                $excMin = $min;
            }
        }

        $excMax = null;
        if (isset($schema['exclusiveMaximum'])) {
            if (is_numeric($schema['exclusiveMaximum'])) {
                $excMax = (float) $schema['exclusiveMaximum'];
            } elseif ($schema['exclusiveMaximum'] === true && $max !== null) {
                $excMax = $max;
            }
        }

        if (! $this->isValueConformingToSchema($base, $schema, $componentsSchemas)) {
            $base = $this->generateValidNumber($schema);
        }

        if (! in_array($base, $existing, true) && $this->isValueConformingToSchema($base, $schema, $componentsSchemas)) {
            return $base;
        }

        $step = 0.5;
        if (isset($schema['multipleOf']) && is_numeric($schema['multipleOf']) && (float) $schema['multipleOf'] > 0) {
            $step = (float) $schema['multipleOf'];
        } elseif ($excMin !== null || $excMax !== null || $min !== null || $max !== null) {
            $lower = $excMin ?? $min ?? -10.0;
            $upper = $excMax ?? $max ?? 10.0;
            $range = $upper - $lower;
            if ($range > 0 && $range < 5.0) {
                $step = min(0.5, $range / 10);
            }
        }

        for ($offset = 1; $offset <= 100; $offset++) {
            $delta = $offset * $step;
            $candidate = $base + $delta;
            if (($max === null || $candidate <= $max)
                && ($min === null || $candidate >= $min)
                && ($excMin === null || $candidate > $excMin)
                && ($excMax === null || $candidate < $excMax)
                && ! in_array($candidate, $existing, true)
                && $this->isValueConformingToSchema($candidate, $schema, $componentsSchemas)
            ) {
                return $candidate;
            }

            $candidate = $base - $delta;
            if (($min === null || $candidate >= $min)
                && ($max === null || $candidate <= $max)
                && ($excMin === null || $candidate > $excMin)
                && ($excMax === null || $candidate < $excMax)
                && ! in_array($candidate, $existing, true)
                && $this->isValueConformingToSchema($candidate, $schema, $componentsSchemas)
            ) {
                return $candidate;
            }
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $itemsSchema
     * @param  array<string, mixed>  $resolvedItems
     * @param  array<string, mixed>  $componentsSchemas
     * @param  list<mixed>  $seen
     */
    protected function generateUniqueItem(
        array $itemsSchema,
        array $resolvedItems,
        array $componentsSchemas,
        string $propName,
        int $depth,
        int $index,
        array $seen
    ): mixed {
        $attempts = 0;
        $item = null;
        $serialized = '';
        do {
            $attempts++;
            $item = $this->generateValidValueForSchema(
                $itemsSchema,
                $componentsSchemas,
                $propName.'_item_'.($index + $attempts),
                $depth + 1
            );
            $serialized = json_encode($item);
        } while (in_array($serialized, $seen, true) && $attempts < 10);

        if (in_array($serialized, $seen, true) && is_array($item)) {
            $props = $resolvedItems['properties'] ?? [];
            if (is_array($props)) {
                foreach ($props as $pName => $pDef) {
                    if (is_array($pDef) && array_key_exists((string) $pName, $item)) {
                        $existingVals = [];
                        foreach ($seen as $sJson) {
                            if (is_string($sJson)) {
                                $dec = json_decode($sJson, true);
                                if (is_array($dec) && array_key_exists((string) $pName, $dec)) {
                                    $existingVals[] = $dec[(string) $pName];
                                }
                            }
                        }

                        $pType = $this->extractSchemaType($pDef);
                        if ($pType === 'integer') {
                            $candVal = $this->generateUniqueInteger($pDef, (int) $item[(string) $pName], $index, $existingVals);
                            $clone = $item;
                            $clone[(string) $pName] = $candVal;
                            if (! in_array(json_encode($clone), $seen, true)) {
                                return $clone;
                            }
                        } elseif ($pType === 'number') {
                            $candVal = $this->generateUniqueNumber($pDef, (float) $item[(string) $pName], $index, $existingVals);
                            $clone = $item;
                            $clone[(string) $pName] = $candVal;
                            if (! in_array(json_encode($clone), $seen, true)) {
                                return $clone;
                            }
                        } elseif ($pType === 'string' && (($pDef['format'] ?? null) === 'uuid')) {
                            $clone = $item;
                            $clone[(string) $pName] = sprintf('123e4567-e89b-12d3-a456-%012d', ++$this->uniqueCounter);
                            if (! in_array(json_encode($clone), $seen, true)) {
                                return $clone;
                            }
                        } elseif ($pType === 'string') {
                            for ($att = 1; $att <= 20; $att++) {
                                $candVal = $this->generateValidValueForSchema($pDef, $componentsSchemas, ((string) $pName).'_uniq_'.$att.'_'.(++$this->uniqueCounter), $depth + 2);
                                $clone = $item;
                                $clone[(string) $pName] = $candVal;
                                if (! in_array(json_encode($clone), $seen, true)) {
                                    return $clone;
                                }
                            }
                        }
                    }
                }
            }
        }

        if (in_array($serialized, $seen, true) && is_string($item)) {
            for ($att = 1; $att <= 50; $att++) {
                $candStr = $this->generateValidValueForSchema(
                    $itemsSchema,
                    $componentsSchemas,
                    $propName.'_uniq_'.$att.'_'.(++$this->uniqueCounter),
                    $depth + 1
                );
                if (is_string($candStr) && ! in_array(json_encode($candStr), $seen, true)) {
                    return $candStr;
                }
            }
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $componentsSchemas
     * @return array<string, mixed>|\stdClass
     */
    protected function generateValidObject(array $schema, array $componentsSchemas, int $depth): array|\stdClass
    {
        $props = isset($schema['properties']) && is_array($schema['properties']) ? $schema['properties'] : [];
        $result = [];

        foreach ($props as $k => $subSchema) {
            if (is_array($subSchema)) {
                $result[(string) $k] = $this->generateValidValueForSchema($subSchema, $componentsSchemas, (string) $k, $depth + 1);
            }
        }

        return $result === [] ? new \stdClass : $result;
    }

    /**
     * Pick a character safe for regex pattern and length boundaries.
     *
     * @param  array<string, mixed>  $schema
     */
    protected function getSafeCharForSchema(array $schema): string
    {
        $pattern = $schema['pattern'] ?? null;
        if (is_string($pattern) && (str_contains($pattern, '^[A-Z0-9_-]+$') || str_contains($pattern, '^[A-Z0-9_]+$'))) {
            return 'A';
        }
        if (is_string($pattern) && (str_contains($pattern, '^[a-z0-9_-]+$') || str_contains($pattern, '^[a-z0-9]+(?:-[a-z0-9]+)*$'))) {
            return 'a';
        }

        return 'x';
    }

    /**
     * Generate a valid string of exact length satisfying all schema constraints (format, pattern, enum, minLength, maxLength).
     * Returns null if no valid string of this exact length can satisfy all constraints.
     *
     * @param  array<string, mixed>  $schema
     */
    public function generateValidStringOfExactLength(array $schema, string $propName, int $targetLen): ?string
    {
        if ($targetLen < 0) {
            return null;
        }

        // 1. Enum constraint
        if (isset($schema['enum']) && is_array($schema['enum'])) {
            foreach ($schema['enum'] as $enumVal) {
                if (is_scalar($enumVal)) {
                    $cand = (string) $enumVal;
                    if (strlen($cand) === $targetLen && $this->isValidStringForSchema($cand, $schema, $propName)) {
                        return $cand;
                    }
                }
            }

            return null;
        }

        $format = $schema['format'] ?? null;
        $pattern = isset($schema['pattern']) && is_string($schema['pattern']) ? $schema['pattern'] : null;
        $propLower = strtolower($propName);
        $candidate = null;

        // 2. Format / Semantic constraints
        if ($format === 'email' || str_contains($propLower, 'email')) {
            // Minimum valid email length is 6 characters (e.g. 'u@a.co')
            if ($targetLen < 6) {
                return null;
            }
            // Length of '@a.co' is 5. User part length is $targetLen - 5.
            $userLen = $targetLen - 5;
            $userPart = 'u'.str_repeat('x', $userLen - 1);
            $candidate = $userPart.'@a.co';
        } elseif ($format === 'date') {
            // 'YYYY-MM-DD' is strictly 10 characters
            if ($targetLen !== 10) {
                return null;
            }
            $candidate = '2026-01-01';
        } elseif ($format === 'date-time') {
            // 'YYYY-MM-DDTHH:MM:SSZ' is strictly 20 characters
            if ($targetLen !== 20) {
                return null;
            }
            $candidate = '2026-01-01T12:00:00Z';
        } elseif ($format === 'uuid' || ($pattern !== null && (str_contains($pattern, '[0-9a-fA-F]{8}-') || str_contains($pattern, '[a-f0-9]{8}-') || str_contains(strtolower($pattern), 'uuid')))) {
            // UUID 8-4-4-4-12 is strictly 36 characters
            if ($targetLen !== 36) {
                return null;
            }
            $candidate = sprintf('123e4567-e89b-12d3-a456-%012d', ++$this->uniqueCounter);
        } elseif ($format === 'uri' || $format === 'url') {
            // Shortest URI is 'http://a.co' (11 characters)
            if ($targetLen < 11) {
                return null;
            }
            if ($targetLen === 11) {
                $candidate = 'http://a.co';
            } else {
                $candidate = 'http://a.co/'.str_repeat('x', $targetLen - 12);
            }
        } elseif (str_contains($propLower, 'slug')) {
            if ($targetLen === 0) {
                return null;
            }
            $candidate = $targetLen === 1 ? 's' : 's'.str_repeat('a', $targetLen - 1);
        } elseif (str_contains($propLower, 'sku')) {
            if ($targetLen === 0) {
                return null;
            }
            $candidate = $targetLen === 1 ? 'S' : 'S'.str_repeat('A', $targetLen - 1);
        } elseif ($pattern !== null) {
            // Prefix + exact digits (e.g. ^PROD-[0-9]{3}$)
            if (preg_match('/^\^?([a-zA-Z0-9_-]+)\[0-9\]\{(\d+)\}\$?$/', $pattern, $pm)) {
                $prefix = $pm[1];
                $digitsCount = (int) $pm[2];
                $expectedTotalLen = strlen($prefix) + $digitsCount;
                if ($targetLen !== $expectedTotalLen) {
                    return null;
                }
                $maxNum = (10 ** $digitsCount) - 1;
                $num = min($maxNum, max(1, (int) floor($maxNum * 0.8) + (++$this->uniqueCounter % max(1, (int) floor($maxNum * 0.19)))));
                $candidate = $prefix.str_pad((string) $num, $digitsCount, '0', STR_PAD_LEFT);
            } elseif (preg_match('/^\^?\[0-9\]\{(\d+)\}\$?$/', $pattern, $pm) || preg_match('/^\^?\\\\d\{(\d+)\}\$?$/', $pattern, $pm)) {
                $expectedLen = (int) $pm[1];
                if ($targetLen !== $expectedLen) {
                    return null;
                }
                $candidate = str_repeat('1', $targetLen);
            } elseif (preg_match('/^\^?\[A-Z\]\{(\d+)\}\$?$/', $pattern, $pm)) {
                $expectedLen = (int) $pm[1];
                if ($targetLen !== $expectedLen) {
                    return null;
                }
                $candidate = str_repeat('A', $targetLen);
            } elseif (str_contains($pattern, '^[0-9]+$') || str_contains($pattern, '^\d+$')) {
                $candidate = str_repeat('1', $targetLen);
            } elseif (str_contains($pattern, '^[A-Z0-9_-]+$') || str_contains($pattern, '^[A-Z0-9_]+$') || str_contains($pattern, '^[A-Z]+$')) {
                $candidate = str_repeat('A', $targetLen);
            } elseif (str_contains($pattern, '^[a-z0-9_-]+$') || str_contains($pattern, '^[a-z0-9]+(?:-[a-z0-9]+)*$') || str_contains($pattern, '^[a-z]+$')) {
                $candidate = $targetLen === 1 ? 'a' : 'a'.str_repeat('b', $targetLen - 1);
            } else {
                $patternChar = $this->getSafeCharForSchema($schema);
                $candidate = str_repeat($patternChar, $targetLen);
            }
        } else {
            $patternChar = $this->getSafeCharForSchema($schema);
            $candidate = str_repeat($patternChar, $targetLen);
        }

        if (strlen($candidate) !== $targetLen) {
            return null;
        }

        // Validate candidate against all schema constraints
        if (! $this->isValidStringForSchema($candidate, $schema, $propName)) {
            return null;
        }

        return $candidate;
    }

    /**
     * Check if a candidate string satisfies all constraints (format, pattern, minLength, maxLength) of a schema.
     *
     * @param  array<string, mixed>  $schema
     */
    public function isValidStringForSchema(string $candidate, array $schema, string $propName = ''): bool
    {
        if (isset($schema['minLength']) && is_numeric($schema['minLength']) && strlen($candidate) < (int) $schema['minLength']) {
            return false;
        }
        if (isset($schema['maxLength']) && is_numeric($schema['maxLength']) && strlen($candidate) > (int) $schema['maxLength']) {
            return false;
        }

        $format = $schema['format'] ?? null;
        $propLower = strtolower($propName);

        if ($format === 'email' || str_contains($propLower, 'email')) {
            if (filter_var($candidate, FILTER_VALIDATE_EMAIL) === false) {
                return false;
            }
        } elseif ($format === 'uuid') {
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $candidate) !== 1) {
                return false;
            }
        } elseif ($format === 'date') {
            if (! $this->isValidDate($candidate)) {
                return false;
            }
        } elseif ($format === 'date-time') {
            if (! $this->isValidDateTime($candidate)) {
                return false;
            }
        }

        $pattern = isset($schema['pattern']) && is_string($schema['pattern']) ? $schema['pattern'] : null;
        if ($pattern !== null) {
            $delimiter = '#';
            $patternForRegex = str_replace('#', '\#', $pattern);
            if (! str_starts_with($patternForRegex, '^')) {
                $patternForRegex = '^'.$patternForRegex;
            }
            if (! str_ends_with($patternForRegex, '$')) {
                $patternForRegex = $patternForRegex.'$';
            }
            if (@preg_match($delimiter.$patternForRegex.$delimiter, $candidate) !== 1) {
                return false;
            }
        }

        return true;
    }

    protected function isValidDate(string $candidate): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $candidate, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    protected function isValidDateTime(string $candidate): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/i', $candidate, $m) !== 1) {
            return false;
        }

        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];
        $hour = (int) $m[4];
        $minute = (int) $m[5];
        $second = (int) $m[6];

        if (! checkdate($month, $day, $year)) {
            return false;
        }

        if ($hour > 23 || $minute > 59 || $second > 59) {
            return false;
        }

        try {
            new \DateTimeImmutable($candidate);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check if a generated value conforms to the expected schema constraints.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $componentsSchemas
     */
    public function isValueConformingToSchema(mixed $value, array $schema, array $componentsSchemas = []): bool
    {
        $resolved = $this->resolveSchema($schema, $componentsSchemas);
        if ($this->hasImpossibleConstraints($resolved, $componentsSchemas)) {
            return false;
        }
        if (array_key_exists('const', $resolved) && ! $this->jsonValuesEqual($value, $resolved['const'])) {
            return false;
        }
        if (isset($resolved['not']) && is_array($resolved['not']) && $this->conformsToJsonSchema($value, $resolved['not'], $componentsSchemas) === true) {
            return false;
        }
        if ($this->conformsToJsonSchema($value, $schema, $componentsSchemas) === false) {
            // Opis JsonSchema Validator uses IEEE-754 64-bit float internally for multipleOf checks,
            // which causes false negative validation errors for 64-bit integers/divisors > 2^53.
            // When failure is solely due to multipleOf precision limitation, verify that:
            // 1. The value conforms to all other schema keywords when multipleOf is stripped.
            // 2. The value conforms to multipleOf under exact rational/BCMath arithmetic.
            $hasMultipleOf = (isset($schema['multipleOf']) && is_numeric($schema['multipleOf']))
                || (isset($resolved['multipleOf']) && is_numeric($resolved['multipleOf']));

            $conformsViaExactMultiple = false;
            if ($hasMultipleOf && (is_int($value) || is_float($value))) {
                // Strip multipleOf only from the top-level schema. componentsSchemas is kept
                // intact so that any $ref used inside if/then/else conditions retains its
                // original semantics (e.g. Even={multipleOf:2} must still act as a condition).
                $strippedSchema = $this->stripMultipleOfConstraint($schema);
                if ($this->conformsToJsonSchema($value, $strippedSchema, $componentsSchemas) === true
                    && $this->conformsToMultipleOf($value, $resolved)
                ) {
                    $conformsViaExactMultiple = true;
                }
            }

            if (! $conformsViaExactMultiple) {
                return false;
            }
        }
        if (isset($resolved['allOf']) || isset($resolved['oneOf']) || isset($resolved['anyOf'])) {
            if ($this->conformsToJsonSchema($value, $resolved, $componentsSchemas) !== true) {
                $hasMultipleOf = (isset($schema['multipleOf']) && is_numeric($schema['multipleOf']))
                    || (isset($resolved['multipleOf']) && is_numeric($resolved['multipleOf']));
                $conformsViaExactMultiple = false;
                if ($hasMultipleOf && (is_int($value) || is_float($value))) {
                    // Strip only the top-level resolved multipleOf; keep componentsSchemas untouched.
                    $strippedResolved = $this->stripMultipleOfConstraint($resolved);
                    if ($this->conformsToJsonSchema($value, $strippedResolved, $componentsSchemas) === true
                        && $this->conformsToMultipleOf($value, $resolved)
                    ) {
                        $conformsViaExactMultiple = true;
                    }
                }

                if (! $conformsViaExactMultiple) {
                    return false;
                }
            }
            if (! isset($resolved['type']) && ! isset($resolved['properties']) && ! isset($resolved['items'])) {
                return true;
            }
        }
        // Bounds alone do not establish membership in a declared enum.
        // Apply this to numeric values too before accepting a success oracle.
        if (isset($resolved['enum']) && is_array($resolved['enum'])) {
            $matchesEnum = false;
            foreach ($resolved['enum'] as $enumValue) {
                if ($this->jsonValuesEqual($value, $enumValue)) {
                    $matchesEnum = true;
                    break;
                }
            }
            if (! $matchesEnum) {
                return false;
            }
        }

        // JSON Schema permits null whenever the schema omits `type` or explicitly includes
        // null in its type union. The full-schema oracle above has already checked enum/const,
        // composition, and all other applicable constraints; do not mistake the primary
        // non-null generation type for the complete set of allowed types.
        $declaredType = $resolved['type'] ?? null;
        $allowsNull = ! is_string($declaredType) && ! is_array($declaredType);
        if (is_string($declaredType)) {
            $allowsNull = $declaredType === 'null';
        } elseif (is_array($declaredType)) {
            $allowsNull = in_array('null', $declaredType, true);
        }
        if ($value === null && $allowsNull) {
            return true;
        }
        $type = $this->extractSchemaType($resolved);

        if ($type === 'array') {
            if (! is_array($value)) {
                return false;
            }
            $count = count($value);
            if (isset($resolved['minItems']) && is_numeric($resolved['minItems']) && $count < (int) $resolved['minItems']) {
                return false;
            }
            if (isset($resolved['maxItems']) && is_numeric($resolved['maxItems']) && $count > (int) $resolved['maxItems']) {
                return false;
            }
            if (! empty($resolved['uniqueItems'])) {
                $serialized = array_map(fn ($item) => json_encode($item), $value);
                if (count($serialized) !== count(array_unique($serialized))) {
                    return false;
                }
            }
            $itemsSchema = is_array($resolved['items'] ?? null) ? $resolved['items'] : [];
            if ($itemsSchema !== []) {
                foreach ($value as $item) {
                    if (! $this->isValueConformingToSchema($item, $itemsSchema, $componentsSchemas)) {
                        return false;
                    }
                }
            }

            return true;
        }

        if ($type === 'object') {
            if (is_object($value)) {
                $value = (array) $value;
            } elseif (! is_array($value) || array_is_list($value)) {
                return false;
            }
            $props = isset($resolved['properties']) && is_array($resolved['properties']) ? $resolved['properties'] : [];

            if (isset($resolved['required']) && is_array($resolved['required'])) {
                foreach ($resolved['required'] as $reqProp) {
                    if (! array_key_exists((string) $reqProp, $value)) {
                        return false;
                    }
                }
            }

            foreach ($props as $pName => $pDef) {
                if (is_array($pDef) && array_key_exists((string) $pName, $value)) {
                    if (! $this->isValueConformingToSchema($value[(string) $pName], $pDef, $componentsSchemas)) {
                        return false;
                    }
                }
            }

            if (array_key_exists('additionalProperties', $resolved)) {
                $addProps = $resolved['additionalProperties'];
                if ($addProps === false) {
                    foreach ($value as $k => $v) {
                        if (! array_key_exists((string) $k, $props)) {
                            return false;
                        }
                    }
                } elseif (is_array($addProps)) {
                    foreach ($value as $k => $v) {
                        if (! array_key_exists((string) $k, $props)) {
                            if (! $this->isValueConformingToSchema($v, $addProps, $componentsSchemas)) {
                                return false;
                            }
                        }
                    }
                }
            }

            $propCount = count($value);
            if (isset($resolved['minProperties']) && is_numeric($resolved['minProperties']) && $propCount < (int) $resolved['minProperties']) {
                return false;
            }
            if (isset($resolved['maxProperties']) && is_numeric($resolved['maxProperties']) && $propCount > (int) $resolved['maxProperties']) {
                return false;
            }

            return true;
        }

        if ($type === 'string') {
            return is_string($value) && $this->isValidStringForSchema($value, $resolved);
        }

        if ($type === 'integer') {
            $isIntegerValue = is_int($value)
                || (is_float($value) && is_finite($value) && floor($value) === $value);
            if (! $isIntegerValue) {
                return false;
            }
            [$min, $max] = $this->resolveIntegerBounds($resolved);
            if ($min !== null && $value < $min) {
                return false;
            }
            if ($max !== null && $value > $max) {
                return false;
            }

            return $this->conformsToMultipleOf($value, $resolved);
        }

        if ($type === 'boolean') {
            return is_bool($value);
        }

        if ($type === 'number') {
            if (! is_numeric($value)) {
                return false;
            }
            $numVal = (float) $value;
            $min = isset($resolved['minimum']) && is_numeric($resolved['minimum']) ? (float) $resolved['minimum'] : null;
            $max = isset($resolved['maximum']) && is_numeric($resolved['maximum']) ? (float) $resolved['maximum'] : null;
            if (isset($resolved['exclusiveMinimum']) && is_numeric($resolved['exclusiveMinimum'])) {
                if ($numVal <= (float) $resolved['exclusiveMinimum']) {
                    return false;
                }
            }
            if (isset($resolved['exclusiveMaximum']) && is_numeric($resolved['exclusiveMaximum'])) {
                if ($numVal >= (float) $resolved['exclusiveMaximum']) {
                    return false;
                }
            }
            if ($min !== null && $numVal < $min) {
                return false;
            }
            if ($max !== null && $numVal > $max) {
                return false;
            }

            return $this->conformsToMultipleOf($numVal, $resolved);
        }

        return true;
    }

    /** Compare values using JSON Schema equality semantics. */
    private function jsonValuesEqual(mixed $left, mixed $right): bool
    {
        $leftIsNumber = is_int($left) || is_float($left);
        $rightIsNumber = is_int($right) || is_float($right);
        if ($leftIsNumber || $rightIsNumber) {
            if (! $leftIsNumber || ! $rightIsNumber) {
                return false;
            }

            return $this->jsonNumbersEqual($left, $right);
        }

        $leftIsObject = is_object($left) || (is_array($left) && ! array_is_list($left));
        $rightIsObject = is_object($right) || (is_array($right) && ! array_is_list($right));
        if ($leftIsObject || $rightIsObject) {
            if (! $leftIsObject || ! $rightIsObject) {
                return false;
            }

            $leftProperties = is_object($left) ? get_object_vars($left) : $left;
            $rightProperties = is_object($right) ? get_object_vars($right) : $right;
            if (count($leftProperties) !== count($rightProperties)) {
                return false;
            }

            foreach ($leftProperties as $key => $value) {
                if (! array_key_exists($key, $rightProperties)
                    || ! $this->jsonValuesEqual($value, $rightProperties[$key])) {
                    return false;
                }
            }

            return true;
        }

        if (is_array($left) || is_array($right)) {
            if (! is_array($left) || ! is_array($right) || count($left) !== count($right)) {
                return false;
            }

            foreach ($left as $index => $value) {
                if (! array_key_exists($index, $right)
                    || ! $this->jsonValuesEqual($value, $right[$index])) {
                    return false;
                }
            }

            return true;
        }

        return $left === $right;
    }

    /**
     * Compare JSON numbers without coercing large PHP integers to imprecise IEEE-754 floats.
     */
    private function jsonNumbersEqual(int|float $left, int|float $right): bool
    {
        if (is_int($left) && is_int($right)) {
            return $left === $right;
        }
        if (is_float($left) && is_float($right)) {
            return is_finite($left) && is_finite($right) && $left == $right;
        }

        $integer = is_int($left) ? $left : $right;
        $float = is_float($left) ? $left : $right;
        if (! is_finite($float) || floor($float) !== $float) {
            return false;
        }

        $floatInteger = sprintf('%.0f', $float);
        $integerString = (string) $integer;
        $floatInteger = $this->normalizeIntegerString($floatInteger);
        $integerString = $this->normalizeIntegerString($integerString);

        return $floatInteger === $integerString;
    }

    private function normalizeIntegerString(string $integer): string
    {
        $negative = str_starts_with($integer, '-');
        $digits = ltrim($negative || str_starts_with($integer, '+') ? substr($integer, 1) : $integer, '0');
        if ($digits === '') {
            return '0';
        }

        return $negative ? '-'.$digits : $digits;
    }

    /**
     * Extract all declared/allowed JSON schema types from schema.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $componentsSchemas
     * @return list<string>
     */
    public function extractAllowedTypes(array $schema, array $componentsSchemas = []): array
    {
        $schema = $this->resolveSchema($schema, $componentsSchemas);
        $types = [];
        $rawType = $schema['type'] ?? null;
        if (is_array($rawType)) {
            foreach ($rawType as $t) {
                if (is_string($t)) {
                    $types[] = $t;
                }
            }
        } elseif (is_string($rawType) && $rawType !== '') {
            $types[] = $rawType;
        }

        if (isset($schema['properties']) && ! in_array('object', $types, true)) {
            $types[] = 'object';
        }
        if (isset($schema['items']) && ! in_array('array', $types, true)) {
            $types[] = 'array';
        }

        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $branch) {
                if (is_array($branch)) {
                    $types = array_merge($types, $this->extractAllowedTypes($branch, $componentsSchemas));
                }
            }
        }
        if (isset($schema['oneOf']) && is_array($schema['oneOf'])) {
            foreach ($schema['oneOf'] as $branch) {
                if (is_array($branch)) {
                    $types = array_merge($types, $this->extractAllowedTypes($branch, $componentsSchemas));
                }
            }
        }

        if (isset($schema['enum']) && is_array($schema['enum'])) {
            foreach ($schema['enum'] as $val) {
                if (is_int($val)) {
                    $types[] = 'integer';
                    $types[] = 'number';
                } elseif (is_float($val)) {
                    $types[] = 'number';
                } elseif (is_string($val)) {
                    $types[] = 'string';
                } elseif (is_bool($val)) {
                    $types[] = 'boolean';
                } elseif (is_array($val)) {
                    $types[] = array_is_list($val) ? 'array' : 'object';
                } elseif ($val === null) {
                    $types[] = 'null';
                }
            }
        }

        $types = array_values(array_unique($types));

        if (empty($types)) {
            $types[] = 'string';
        }

        return $types;
    }

    /**
     * Generate an invalid value for type mismatch testing that is NOT in any of the allowed types.
     * Returns null if no mismatched value can be found (e.g. union covers all JSON types).
     *
     * @param  array<string, mixed>|string  $schemaOrType
     * @param  array<string, mixed>  $componentsSchemas
     */
    public function generateMismatchedValue(array|string $schemaOrType, array $componentsSchemas = []): mixed
    {
        $allowedTypes = is_string($schemaOrType)
            ? [$schemaOrType]
            : $this->extractAllowedTypes($schemaOrType, $componentsSchemas);

        // 1. Single integer/number -> return string
        if (! in_array('string', $allowedTypes, true) && (in_array('integer', $allowedTypes, true) || in_array('number', $allowedTypes, true))) {
            return 'this_is_an_invalid_string_not_number';
        }

        // 2. Single string -> return integer
        if (! in_array('integer', $allowedTypes, true) && ! in_array('number', $allowedTypes, true) && in_array('string', $allowedTypes, true) && count($allowedTypes) === 1) {
            return 999999;
        }

        // 3. For union types or other types: pick first candidate whose type is not in $allowedTypes
        $candidates = [
            'boolean' => true,
            'integer' => 999999,
            'string' => 'this_is_an_invalid_string_not_allowed',
            'array' => ['__invalid_type_mismatch__'],
            'object' => ['__invalid_type_mismatch__' => true],
        ];

        foreach ($candidates as $candType => $candVal) {
            if ($candType === 'integer' && (in_array('integer', $allowedTypes, true) || in_array('number', $allowedTypes, true))) {
                continue;
            }
            if ($candType === 'string' && in_array('string', $allowedTypes, true)) {
                continue;
            }
            if ($candType === 'boolean' && in_array('boolean', $allowedTypes, true)) {
                continue;
            }
            if ($candType === 'array' && in_array('array', $allowedTypes, true)) {
                continue;
            }
            if ($candType === 'object' && in_array('object', $allowedTypes, true)) {
                continue;
            }

            return $candVal;
        }

        return null;
    }

    /**
     * Determine success status code declared in responses (preferred 200, 201, 204 or lowest 2xx).
     *
     * @param  array<string, mixed>  $endpointData
     */
    public function getSuccessStatusCode(array $endpointData): ?int
    {
        $responses = $endpointData['responses'] ?? [];
        $declared = array_map(fn ($k) => (string) $k, array_keys($responses));
        $method = $endpointData['method'];

        if ($method === 'POST' && in_array('201', $declared, true)) {
            return 201;
        }
        if ($method === 'DELETE' && in_array('204', $declared, true)) {
            return 204;
        }
        if (in_array('200', $declared, true)) {
            return 200;
        }

        // Choose the lowest remaining explicit 2xx code.
        $successCodes = [];
        foreach ($declared as $code) {
            if (preg_match('/^2[0-9]{2}$/', $code)) {
                $successCodes[] = (int) $code;
            }
        }
        if ($successCodes !== []) {
            return min($successCodes);
        }

        // Only an explicit success response can establish a success oracle.
        if (in_array('2XX', $declared, true)) {
            return $method === 'POST' ? 201 : 200;
        }

        return null;
    }

    /**
     * Determine error status code declared in responses (preferred 422, 400, wildcard 4XX, or lowest 4xx).
     * Returns null if the endpoint does not declare any 4xx response and no default response.
     *
     * @param  array<string, mixed>  $endpointData
     */
    public function getErrorStatusCode(array $endpointData): ?int
    {
        $responses = $endpointData['responses'] ?? [];
        $declared = array_map(fn ($k) => (string) $k, array_keys($responses));

        // Priority 1: Validation error 422
        if (in_array('422', $declared, true)) {
            return 422;
        }

        // Priority 2: Bad request 400
        if (in_array('400', $declared, true)) {
            return 400;
        }

        // Priority 3: Wildcard 4XX or default
        if (in_array('4XX', $declared, true) || in_array('default', $declared, true)) {
            return 422;
        }

        // Priority 4: Any other declared 4xx code (e.g. 404, 409)
        foreach ($declared as $code) {
            if (is_numeric($code) && (int) $code >= 400 && (int) $code < 500) {
                return (int) $code;
            }
        }

        // If endpoint declares NO 4xx and no default, error oracle cannot be determined
        return null;
    }
}
