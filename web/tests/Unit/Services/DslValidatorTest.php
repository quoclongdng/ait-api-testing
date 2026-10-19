<?php

namespace Tests\Unit\Services;

use App\Data\Dsl\DslValidationResult;
use App\Data\OpenApi\ParsedOpenApi;
use App\Enums\ApiSpecFormat;
use App\Enums\ErrorCode;
use App\Enums\HttpMethod;
use App\Exceptions\ApiException;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Services\Dsl\DslValidator;
use App\Services\OpenApi\OpenApiParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DslValidatorTest extends TestCase
{
    private DslValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new DslValidator;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validFixturesProvider(): array
    {
        $examplesDir = dirname(__DIR__, 4).'/docs/contracts/examples';
        $files = glob($examplesDir.'/valid-*.json') ?: [];

        $dataset = [];
        foreach ($files as $filePath) {
            $name = basename($filePath);
            $dataset[$name] = [$filePath];
        }

        return $dataset;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidFixturesProvider(): array
    {
        $examplesDir = dirname(__DIR__, 4).'/docs/contracts/examples';
        $files = glob($examplesDir.'/invalid-*.json') ?: [];

        $dataset = [];
        foreach ($files as $filePath) {
            $name = basename($filePath);
            $dataset[$name] = [$filePath];
        }

        return $dataset;
    }

    /**
     * Fixtures that pass the JSON Schema but fail the semantic check. The ai-service
     * DslValidator tests read the same files (S10-01 r3: PHP/Python parity, D04).
     *
     * @return array<string, array{0: string}>
     */
    public static function semanticInvalidFixturesProvider(): array
    {
        $examplesDir = dirname(__DIR__, 4).'/docs/contracts/examples';
        $files = glob($examplesDir.'/semantic-invalid-*.json') ?: [];

        $dataset = [];
        foreach ($files as $filePath) {
            $dataset[basename($filePath)] = [$filePath];
        }

        return $dataset;
    }

    #[DataProvider('semanticInvalidFixturesProvider')]
    public function test_shared_semantic_invalid_fixtures_fail_semantic_validation(string $filePath): void
    {
        $content = file_get_contents($filePath);
        $this->assertIsString($content);

        $result = $this->validator->validate($content);

        $this->assertFalse($result->isValid, basename($filePath).' unexpectedly passed validation.');
        $this->assertEmpty($result->schemaErrors);
        $this->assertNotEmpty($result->semanticErrors);
    }

    public function test_shared_semantic_invalid_fixtures_exist(): void
    {
        $this->assertGreaterThanOrEqual(3, count(self::semanticInvalidFixturesProvider()));
    }

    #[DataProvider('validFixturesProvider')]
    public function test_all_contract_valid_fixtures_pass_validation(string $filePath): void
    {
        $content = file_get_contents($filePath);
        $this->assertIsString($content);

        $result = $this->validator->validate($content);

        $this->assertTrue(
            $result->isValid,
            'Failed on fixture: '.basename($filePath).' with errors: '.implode('; ', $result->errors)
        );
        $this->assertEmpty($result->schemaErrors);
        $this->assertEmpty($result->semanticErrors);
        $this->assertEmpty($result->errors);
        $this->assertIsArray($result->dsl);
    }

    #[DataProvider('invalidFixturesProvider')]
    public function test_all_contract_invalid_fixtures_fail_schema_validation(string $filePath): void
    {
        $content = file_get_contents($filePath);
        $this->assertIsString($content);

        $result = $this->validator->validate($content);

        $this->assertFalse(
            $result->isValid,
            'Fixture '.basename($filePath).' unexpectedly passed validation.'
        );
        $this->assertNotEmpty($result->schemaErrors);
        $this->assertNotEmpty($result->errors);
    }

    public function test_accepts_array_and_object_inputs(): void
    {
        $dslArray = [
            'dsl_version' => '1.0',
            'title' => 'Simple ping test',
            'endpoint' => ['method' => 'GET', 'path' => '/ping'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'ping',
                    'request' => ['method' => 'GET', 'path' => '/ping'],
                    'assertions' => [
                        ['type' => 'status', 'equals' => 200],
                    ],
                ],
            ],
        ];

        // 1. Array input
        $resArray = $this->validator->validate($dslArray);
        $this->assertTrue($resArray->isValid);
        $this->assertSame($dslArray, $resArray->dsl);

        // 2. stdClass object input
        $resObject = $this->validator->validate((object) json_decode((string) json_encode($dslArray)));
        $this->assertTrue($resObject->isValid);

        // 3. Invalid JSON string returns syntax error
        $resSyntaxError = $this->validator->validate('{ invalid json');
        $this->assertFalse($resSyntaxError->isValid);
        $this->assertStringContainsString('Lỗi cú pháp JSON', $resSyntaxError->schemaErrors[0] ?? '');

        // 4. Unsupported data type
        $resType = $this->validator->validate(12345);
        $this->assertFalse($resType->isValid);
        $this->assertStringContainsString('chuỗi JSON, mảng hoặc đối tượng', $resType->schemaErrors[0] ?? '');
    }

    public function test_validates_variable_lifecycle_success_from_root_and_extraction(): void
    {
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Chained item workflow',
            'endpoint' => ['method' => 'POST', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'variables' => [
                'prefix' => 'item_',
            ],
            'steps' => [
                [
                    'name' => 'create item',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/items',
                        'body' => ['name' => '{{prefix}}001'],
                    ],
                    'extract' => [
                        ['var' => 'createdId', 'from' => 'body', 'path' => '$.id'],
                    ],
                    'assertions' => [
                        ['type' => 'status', 'equals' => 201],
                    ],
                ],
                [
                    'name' => 'get item',
                    'request' => [
                        'method' => 'GET',
                        'path' => '/items/{id}',
                        'path_params' => ['id' => '{{createdId}}'],
                    ],
                    'assertions' => [
                        ['type' => 'status', 'equals' => 200],
                        ['type' => 'json_path', 'path' => '$.id', 'op' => 'eq', 'value' => '{{createdId}}'],
                    ],
                ],
            ],
        ];

        $result = $this->validator->validate($dsl);

        $this->assertTrue($result->isValid);
        $this->assertEmpty($result->semanticErrors);
    }

    public function test_detects_undefined_variable_referenced_before_extraction(): void
    {
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Referencing unextracted variable',
            'endpoint' => ['method' => 'GET', 'path' => '/items/{id}'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'attempt read item',
                    'request' => [
                        'method' => 'GET',
                        'path' => '/items/{id}',
                        'path_params' => ['id' => '{{itemId}}'], // itemId not declared in root or extracted yet!
                    ],
                    'extract' => [
                        ['var' => 'itemId', 'from' => 'body', 'path' => '$.id'],
                    ],
                    'assertions' => [
                        ['type' => 'status', 'equals' => 200],
                    ],
                ],
            ],
        ];

        $result = $this->validator->validate($dsl);

        $this->assertFalse($result->isValid);
        $this->assertEmpty($result->schemaErrors);
        $this->assertNotEmpty($result->semanticErrors);
        $this->assertStringContainsString("Biến '{{itemId}}' được sử dụng nhưng chưa được định nghĩa", $result->semanticErrors[0]);
    }

    public function test_detects_non_existent_root_endpoint_in_openapi_spec(): void
    {
        $spec = [
            'paths' => [
                '/items' => [
                    'post' => [
                        'responses' => ['201' => ['description' => 'Created']],
                    ],
                ],
            ],
        ];

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Call non-existent root endpoint',
            'endpoint' => ['method' => 'DELETE', 'path' => '/non-existent'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'step 1',
                    'request' => ['method' => 'POST', 'path' => '/items'],
                    'assertions' => [['type' => 'status', 'equals' => 201]],
                ],
            ],
        ];

        $result = $this->validator->validate($dsl, $spec);

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString("Endpoint 'DELETE /non-existent' không tồn tại trong đặc tả OpenAPI", $result->semanticErrors[0]);
    }

    public function test_detects_non_existent_step_endpoint(): void
    {
        $spec = [
            'paths' => [
                '/items' => [
                    'post' => [
                        'responses' => ['201' => ['description' => 'Created']],
                    ],
                ],
            ],
        ];

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Call non-existent step endpoint',
            'endpoint' => ['method' => 'POST', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'step with wrong method',
                    'request' => ['method' => 'GET', 'path' => '/items'], // Spec only has POST /items
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $result = $this->validator->validate($dsl, $spec);

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString("Endpoint 'GET /items' không tồn tại trong đặc tả OpenAPI", $result->semanticErrors[0]);
    }

    public function test_detects_undeclared_path_parameter(): void
    {
        $spec = [
            'paths' => [
                '/items/{id}' => [
                    'get' => [
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true],
                        ],
                        'responses' => ['200' => ['description' => 'OK']],
                    ],
                ],
            ],
        ];

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Call with undeclared path param',
            'endpoint' => ['method' => 'GET', 'path' => '/items/{id}'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'read item',
                    'request' => [
                        'method' => 'GET',
                        'path' => '/items/{id}',
                        'path_params' => [
                            'id' => '100',
                            'extra_unknown_param' => 'invalid',
                        ],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $result = $this->validator->validate($dsl, $spec);

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString("Tham số đường dẫn 'extra_unknown_param' không tồn tại", $result->semanticErrors[0]);
    }

    public function test_detects_undeclared_query_parameter(): void
    {
        $spec = [
            'paths' => [
                '/items' => [
                    'get' => [
                        'parameters' => [
                            ['name' => 'limit', 'in' => 'query', 'required' => false],
                        ],
                        'responses' => ['200' => ['description' => 'OK']],
                    ],
                ],
            ],
        ];

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Call with undeclared query param',
            'endpoint' => ['method' => 'GET', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'list items',
                    'request' => [
                        'method' => 'GET',
                        'path' => '/items',
                        'query' => [
                            'limit' => 20,
                            'malicious_filter' => 'hack',
                        ],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $result = $this->validator->validate($dsl, $spec);

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString("Tham số query 'malicious_filter' không được khai báo", $result->semanticErrors[0]);
    }

    public function test_detects_undeclared_request_body_field(): void
    {
        $spec = [
            'paths' => [
                '/items' => [
                    'post' => [
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'name' => ['type' => 'string'],
                                            'price' => ['type' => 'number'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => ['201' => ['description' => 'Created']],
                    ],
                ],
            ],
        ];

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Post unknown body field',
            'endpoint' => ['method' => 'POST', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'create item',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/items',
                        'body' => [
                            'name' => 'Pen',
                            'price' => 10,
                            'unrecognized_field' => 'should_fail',
                        ],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 201]],
                ],
            ],
        ];

        $result = $this->validator->validate($dsl, $spec);

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString("Trường 'unrecognized_field' trong request body không tồn tại", $result->semanticErrors[0]);
    }

    public function test_detects_body_sent_to_endpoint_without_request_body_definition(): void
    {
        $spec = [
            'paths' => [
                '/items' => [
                    'get' => [
                        'responses' => ['200' => ['description' => 'OK']],
                    ],
                ],
            ],
        ];

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Send body to GET endpoint',
            'endpoint' => ['method' => 'GET', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'get with body',
                    'request' => [
                        'method' => 'GET',
                        'path' => '/items',
                        'body' => ['key' => 'val'],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $result = $this->validator->validate($dsl, $spec);

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('không khai báo request body nhưng ca kiểm thử lại truyền body', $result->semanticErrors[0]);
    }

    public function test_detects_undeclared_status_code_in_assertion(): void
    {
        $spec = [
            'paths' => [
                '/items' => [
                    'post' => [
                        'responses' => [
                            '201' => ['description' => 'Created'],
                            '400' => ['description' => 'Bad Request'],
                        ],
                    ],
                ],
            ],
        ];

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Assert non-existent status 503',
            'endpoint' => ['method' => 'POST', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'create item',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/items',
                    ],
                    'assertions' => [
                        ['type' => 'status', 'equals' => 503], // 503 is not in spec
                    ],
                ],
            ],
        ];

        $result = $this->validator->validate($dsl, $spec);

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString("Mã trạng thái HTTP '503' không được khai báo", $result->semanticErrors[0]);
    }

    public function test_accepts_status_code_matching_wildcard_or_default(): void
    {
        $spec = [
            'paths' => [
                '/items' => [
                    'post' => [
                        'responses' => [
                            '2XX' => ['description' => 'Successful'],
                            'default' => ['description' => 'Error'],
                        ],
                    ],
                ],
            ],
        ];

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Wildcard 2XX matches 201',
            'endpoint' => ['method' => 'POST', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'create item',
                    'request' => ['method' => 'POST', 'path' => '/items'],
                    'assertions' => [
                        ['type' => 'status', 'equals' => 201],
                    ],
                ],
            ],
        ];

        $result = $this->validator->validate($dsl, $spec);
        $this->assertTrue($result->isValid);
    }

    public function test_detects_non_existent_schema_ref_in_components_schemas(): void
    {
        $spec = [
            'paths' => [
                '/items' => [
                    'post' => [
                        'responses' => ['201' => ['description' => 'Created']],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'Item' => ['type' => 'object'],
                ],
            ],
        ];

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Assert non-existent schema ref',
            'endpoint' => ['method' => 'POST', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'create item',
                    'request' => ['method' => 'POST', 'path' => '/items'],
                    'assertions' => [
                        ['type' => 'status', 'equals' => 201],
                        ['type' => 'json_schema', 'schema_ref' => '#/components/schemas/NonExistentSchema'],
                    ],
                ],
            ],
        ];

        $result = $this->validator->validate($dsl, $spec);

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString("Schema reference '#/components/schemas/NonExistentSchema' không tồn tại trong components.schemas", $result->semanticErrors[0]);
    }

    public function test_semantic_validation_against_parsed_openapi_petstore(): void
    {
        $parser = new OpenApiParser;
        $petstoreFixture = dirname(__DIR__, 2).'/Fixtures/OpenApi/petstore-3.0.json';
        $parsed = $parser->parse((string) file_get_contents($petstoreFixture), ApiSpecFormat::Json);

        $this->assertInstanceOf(ParsedOpenApi::class, $parsed);
        $this->assertTrue($parsed->isValid);

        // Valid DSL calling GET /pets
        $validDsl = [
            'dsl_version' => '1.0',
            'title' => 'List pets happy path',
            'endpoint' => ['method' => 'GET', 'path' => '/pets'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'get pets',
                    'request' => [
                        'method' => 'GET',
                        'path' => '/pets',
                        'query' => ['limit' => 10],
                    ],
                    'assertions' => [
                        ['type' => 'status', 'equals' => 200],
                        ['type' => 'json_schema', 'schema_ref' => '#/components/schemas/Pet'],
                    ],
                ],
            ],
        ];

        $res = $this->validator->validate($validDsl, $parsed);
        $this->assertTrue($res->isValid, 'Petstore valid DSL failed: '.implode('; ', $res->errors));

        // Invalid DSL asserting undeclared query param on /pets
        $invalidDsl = $validDsl;
        $invalidDsl['steps'][0]['request']['query']['non_existent_param'] = 'test';

        $invalidRes = $this->validator->validate($invalidDsl, $parsed);
        $this->assertFalse($invalidRes->isValid);
        $this->assertStringContainsString("Tham số query 'non_existent_param' không được khai báo", $invalidRes->semanticErrors[0]);
    }

    public function test_validate_or_fail_throws_api_exception_on_invalid_dsl(): void
    {
        $invalidDsl = ['dsl_version' => '1.0']; // missing title, endpoint, steps

        $this->expectException(ApiException::class);

        try {
            $this->validator->validateOrFail($invalidDsl);
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::DslInvalid, $e->errorCode);
            $this->assertSame(422, $e->errorCode->httpStatus());
            $this->assertNotEmpty($e->details);
            $this->assertArrayHasKey('schema_errors', $e->details);
            throw $e;
        }
    }

    public function test_validate_or_fail_returns_result_on_valid_dsl(): void
    {
        $validDsl = [
            'dsl_version' => '1.0',
            'title' => 'Minimal valid DSL',
            'endpoint' => ['method' => 'GET', 'path' => '/test'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'step 1',
                    'request' => ['method' => 'GET', 'path' => '/test'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $result = $this->validator->validateOrFail($validDsl);

        $this->assertInstanceOf(DslValidationResult::class, $result);
        $this->assertTrue($result->isValid);
    }

    public function test_regression_query_when_none_declared(): void
    {
        $spec = [
            'paths' => [
                '/items' => [
                    'get' => [
                        'parameters' => [],
                        'responses' => ['200' => ['description' => 'OK']],
                    ],
                ],
            ],
        ];

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Query when none declared',
            'endpoint' => ['method' => 'GET', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'list items',
                    'request' => [
                        'method' => 'GET',
                        'path' => '/items',
                        'query' => ['unexpected_page' => 1],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $result = $this->validator->validate($dsl, $spec);
        $this->assertFalse($result->isValid);
        $this->assertStringContainsString("Tham số query 'unexpected_page' không được khai báo", $result->semanticErrors[0] ?? '');
    }

    public function test_regression_missing_required_path_param(): void
    {
        $spec = [
            'paths' => [
                '/pets/{id}' => [
                    'get' => [
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true],
                        ],
                        'responses' => ['200' => ['description' => 'OK']],
                    ],
                ],
            ],
        ];

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Missing required path param',
            'endpoint' => ['method' => 'GET', 'path' => '/pets/{id}'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'get pet without path_params',
                    'request' => [
                        'method' => 'GET',
                        'path' => '/pets/{id}',
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $result = $this->validator->validate($dsl, $spec);
        $this->assertFalse($result->isValid);
        $this->assertStringContainsString("Thiếu tham số đường dẫn bắt buộc 'id' cho endpoint 'GET /pets/{id}'", $result->semanticErrors[0] ?? '');
    }

    public function test_regression_schema_ref_when_components_absent(): void
    {
        $spec = [
            'paths' => [
                '/items' => [
                    'get' => [
                        'responses' => ['200' => ['description' => 'OK']],
                    ],
                ],
            ],
        ];

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Schema ref when components absent',
            'endpoint' => ['method' => 'GET', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'get items',
                    'request' => ['method' => 'GET', 'path' => '/items'],
                    'assertions' => [
                        ['type' => 'status', 'equals' => 200],
                        ['type' => 'json_schema', 'schema_ref' => '#/components/schemas/Pet'],
                    ],
                ],
            ],
        ];

        $result = $this->validator->validate($dsl, $spec);
        $this->assertFalse($result->isValid);
        $this->assertStringContainsString("Schema reference '#/components/schemas/Pet' không tồn tại trong components.schemas", $result->semanticErrors[0] ?? '');
    }

    public function test_regression_malformed_schema_ref(): void
    {
        $spec = [
            'paths' => [
                '/items' => [
                    'get' => [
                        'responses' => ['200' => ['description' => 'OK']],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'Pet' => ['type' => 'object'],
                ],
            ],
        ];

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Malformed schema ref',
            'endpoint' => ['method' => 'GET', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'get items',
                    'request' => ['method' => 'GET', 'path' => '/items'],
                    'assertions' => [
                        ['type' => 'status', 'equals' => 200],
                        ['type' => 'json_schema', 'schema_ref' => 'not-a-json-pointer'],
                    ],
                ],
            ],
        ];

        $result = $this->validator->validate($dsl, $spec);
        $this->assertFalse($result->isValid);
        $this->assertStringContainsString("Schema reference 'not-a-json-pointer' không đúng định dạng", $result->semanticErrors[0] ?? '');
    }

    public function test_regression_raw_spec_ref_body_unknown_field(): void
    {
        $spec = [
            'paths' => [
                '/items' => [
                    'post' => [
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ItemPayload',
                                    ],
                                ],
                            ],
                        ],
                        'responses' => ['201' => ['description' => 'Created']],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'ItemPayload' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'price' => ['type' => 'number'],
                        ],
                    ],
                ],
            ],
        ];

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Raw spec ref body with unknown field',
            'endpoint' => ['method' => 'POST', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'create item',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/items',
                        'body' => [
                            'name' => 'Notebook',
                            'unknown_ref_field' => 'bad_value',
                        ],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 201]],
                ],
            ],
        ];

        $result = $this->validator->validate($dsl, $spec);
        $this->assertFalse($result->isValid);
        $this->assertStringContainsString("Trường 'unknown_ref_field' trong request body không tồn tại", $result->semanticErrors[0] ?? '');
    }

    public function test_regression_nested_unknown_body_field(): void
    {
        $spec = [
            'paths' => [
                '/users' => [
                    'post' => [
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'username' => ['type' => 'string'],
                                            'profile' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'bio' => ['type' => 'string'],
                                                    'avatar' => ['type' => 'string'],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => ['201' => ['description' => 'Created']],
                    ],
                ],
            ],
        ];

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Nested unknown body field',
            'endpoint' => ['method' => 'POST', 'path' => '/users'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'create user',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/users',
                        'body' => [
                            'username' => 'alice',
                            'profile' => [
                                'bio' => 'Developer',
                                'unregistered_nested_field' => 'invalid',
                            ],
                        ],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 201]],
                ],
            ],
        ];

        $result = $this->validator->validate($dsl, $spec);
        $this->assertFalse($result->isValid);
        $this->assertStringContainsString("Trường 'profile.unregistered_nested_field' trong request body không tồn tại", $result->semanticErrors[0] ?? '');
    }

    public function test_semantic_validation_against_api_spec_model(): void
    {
        $apiSpec = new ApiSpec;
        $apiSpec->normalized = [
            'components' => [
                'schemas' => [
                    'Product' => [
                        'type' => 'object',
                        'properties' => ['id' => ['type' => 'integer']],
                    ],
                ],
            ],
        ];

        $endpoint = new Endpoint;
        $endpoint->method = HttpMethod::Get;
        $endpoint->path = '/products';
        $endpoint->parameters = [];
        $endpoint->setAttribute('responses', ['200' => ['description' => 'OK']]);

        $apiSpec->setRelation('endpoints', collect([$endpoint]));

        // Valid DSL
        $validDsl = [
            'dsl_version' => '1.0',
            'title' => 'Get products from ApiSpec model',
            'endpoint' => ['method' => 'GET', 'path' => '/products'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'get products',
                    'request' => ['method' => 'GET', 'path' => '/products'],
                    'assertions' => [
                        ['type' => 'status', 'equals' => 200],
                        ['type' => 'json_schema', 'schema_ref' => '#/components/schemas/Product'],
                    ],
                ],
            ],
        ];

        $result = $this->validator->validate($validDsl, $apiSpec);
        $this->assertTrue($result->isValid, 'ApiSpec validation failed: '.implode('; ', $result->errors));

        // Invalid DSL asserting non-existent endpoint on ApiSpec
        $invalidDsl = $validDsl;
        $invalidDsl['steps'][0]['request']['path'] = '/products/missing';
        $invalidResult = $this->validator->validate($invalidDsl, $apiSpec);
        $this->assertFalse($invalidResult->isValid);
        $this->assertStringContainsString("Endpoint 'GET /products/missing' không tồn tại", $invalidResult->semanticErrors[0] ?? '');
    }

    public function test_semantic_validation_against_collection_of_endpoints(): void
    {
        $ep1 = new Endpoint;
        $ep1->method = HttpMethod::Post;
        $ep1->path = '/orders';
        $ep1->parameters = [];
        $ep1->request_body = [
            'content' => [
                'application/json' => [
                    'schema' => [
                        'type' => 'object',
                        'properties' => ['item_id' => ['type' => 'integer']],
                    ],
                ],
            ],
        ];
        $ep1->setAttribute('responses', ['201' => ['description' => 'Created']]);

        $collection = collect([$ep1]);

        $validDsl = [
            'dsl_version' => '1.0',
            'title' => 'Create order from collection',
            'endpoint' => ['method' => 'POST', 'path' => '/orders'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'create order',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/orders',
                        'body' => ['item_id' => 123],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 201]],
                ],
            ],
        ];

        $result = $this->validator->validate($validDsl, $collection);
        $this->assertTrue($result->isValid, 'Collection validation failed: '.implode('; ', $result->errors));

        // Invalid body field
        $invalidDsl = $validDsl;
        $invalidDsl['steps'][0]['request']['body']['unknown_collection_prop'] = 'fail';
        $invalidResult = $this->validator->validate($invalidDsl, $collection);
        $this->assertFalse($invalidResult->isValid);
        $this->assertStringContainsString("Trường 'unknown_collection_prop' trong request body không tồn tại", $invalidResult->semanticErrors[0] ?? '');
    }

    public function test_regression_multi_level_nested_all_of_composition(): void
    {
        $spec = [
            'paths' => [
                '/users' => [
                    'post' => [
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/UserProfile',
                                    ],
                                ],
                            ],
                        ],
                        'responses' => ['201' => ['description' => 'Created']],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'BaseEntity' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                        ],
                    ],
                    'AuditedEntity' => [
                        'allOf' => [
                            ['$ref' => '#/components/schemas/BaseEntity'],
                            [
                                'type' => 'object',
                                'properties' => [
                                    'created_at' => ['type' => 'string'],
                                    'updated_at' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                    'UserProfile' => [
                        'allOf' => [
                            ['$ref' => '#/components/schemas/AuditedEntity'],
                            [
                                'type' => 'object',
                                'properties' => [
                                    'username' => ['type' => 'string'],
                                    'email' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // Valid payload combining fields from all 3 levels of allOf
        $validDsl = [
            'dsl_version' => '1.0',
            'title' => 'Multi-tier allOf payload',
            'endpoint' => ['method' => 'POST', 'path' => '/users'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'create user',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/users',
                        'body' => [
                            'id' => 10,
                            'created_at' => '2026-01-01T00:00:00Z',
                            'updated_at' => '2026-01-02T00:00:00Z',
                            'username' => 'alice',
                            'email' => 'alice@example.com',
                        ],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 201]],
                ],
            ],
        ];

        $validRes = $this->validator->validate($validDsl, $spec);
        $this->assertTrue($validRes->isValid, 'Multi-tier allOf valid DSL failed: '.implode('; ', $validRes->errors));

        // Invalid payload with unlisted field
        $invalidDsl = $validDsl;
        $invalidDsl['steps'][0]['request']['body']['unlisted_ghost_field'] = 'should_fail';

        $invalidRes = $this->validator->validate($invalidDsl, $spec);
        $this->assertFalse($invalidRes->isValid);
        $this->assertStringContainsString("Trường 'unlisted_ghost_field' trong request body không tồn tại", $invalidRes->semanticErrors[0] ?? '');
    }

    public function test_regression_one_of_composition_second_branch_is_valid(): void
    {
        $spec = [
            'paths' => [
                '/checkout' => [
                    'post' => [
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'oneOf' => [
                                            [
                                                'type' => 'object',
                                                'properties' => [
                                                    'type' => ['type' => 'string'],
                                                    'card_number' => ['type' => 'string'],
                                                    'cvv' => ['type' => 'string'],
                                                ],
                                            ],
                                            [
                                                'type' => 'object',
                                                'properties' => [
                                                    'type' => ['type' => 'string'],
                                                    'paypal_email' => ['type' => 'string'],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => ['200' => ['description' => 'OK']],
                    ],
                ],
            ],
        ];

        // Valid payload matching branch 2 (PayPal) — must NOT produce false positive error
        $paypalDsl = [
            'dsl_version' => '1.0',
            'title' => 'Checkout via PayPal',
            'endpoint' => ['method' => 'POST', 'path' => '/checkout'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'pay',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/checkout',
                        'body' => [
                            'type' => 'paypal',
                            'paypal_email' => 'buyer@example.com',
                        ],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $paypalRes = $this->validator->validate($paypalDsl, $spec);
        $this->assertTrue($paypalRes->isValid, 'PayPal oneOf branch failed: '.implode('; ', $paypalRes->errors));

        // Valid payload matching branch 1 (Card)
        $cardDsl = [
            'dsl_version' => '1.0',
            'title' => 'Checkout via Card',
            'endpoint' => ['method' => 'POST', 'path' => '/checkout'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'pay',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/checkout',
                        'body' => [
                            'type' => 'card',
                            'card_number' => '411111111111',
                            'cvv' => '999',
                        ],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $cardRes = $this->validator->validate($cardDsl, $spec);
        $this->assertTrue($cardRes->isValid, 'Card oneOf branch failed: '.implode('; ', $cardRes->errors));

        // Invalid payload containing unknown field not matching any branch
        $invalidDsl = $paypalDsl;
        $invalidDsl['steps'][0]['request']['body']['rogue_field'] = 'invalid';

        $invalidRes = $this->validator->validate($invalidDsl, $spec);
        $this->assertFalse($invalidRes->isValid);
        $this->assertStringContainsString("Trường 'rogue_field' trong request body không tồn tại", $invalidRes->semanticErrors[0] ?? '');
    }

    public function test_regression_collection_endpoints_with_schema_ref_when_apispec_not_eager_loaded(): void
    {
        $apiSpec = new ApiSpec;
        $apiSpec->normalized = [
            'components' => [
                'schemas' => [
                    'Voucher' => [
                        'type' => 'object',
                        'properties' => [
                            'code' => ['type' => 'string'],
                            'discount' => ['type' => 'number'],
                        ],
                    ],
                ],
            ],
        ];

        // An Endpoint whose apiSpec relation is explicitly NOT eager-loaded
        $endpoint = new Endpoint;
        $endpoint->setAttribute('apiSpec', $apiSpec);
        $endpoint->method = HttpMethod::Get;
        $endpoint->path = '/vouchers';
        $endpoint->parameters = [];
        $endpoint->setAttribute('responses', ['200' => ['description' => 'OK']]);

        // Explicitly assert that relationLoaded('apiSpec') is false
        $this->assertFalse($endpoint->relationLoaded('apiSpec'));

        $collection = collect([$endpoint]);

        // Valid DSL referencing schema from lazy-loaded ApiSpec
        $validDsl = [
            'dsl_version' => '1.0',
            'title' => 'Get vouchers from non-eager-loaded collection',
            'endpoint' => ['method' => 'GET', 'path' => '/vouchers'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'get vouchers',
                    'request' => ['method' => 'GET', 'path' => '/vouchers'],
                    'assertions' => [
                        ['type' => 'status', 'equals' => 200],
                        ['type' => 'json_schema', 'schema_ref' => '#/components/schemas/Voucher'],
                    ],
                ],
            ],
        ];

        $validRes = $this->validator->validate($validDsl, $collection);
        $this->assertTrue($validRes->isValid, 'Non-eager-loaded collection validation failed: '.implode('; ', $validRes->errors));

        // Invalid DSL referencing non-existent schema
        $invalidDsl = $validDsl;
        $invalidDsl['steps'][0]['assertions'][1]['schema_ref'] = '#/components/schemas/MissingVoucher';

        $invalidRes = $this->validator->validate($invalidDsl, $collection);
        $this->assertFalse($invalidRes->isValid);
        $this->assertStringContainsString("Schema reference '#/components/schemas/MissingVoucher' không tồn tại", $invalidRes->semanticErrors[0] ?? '');
    }

    public function test_regression_all_of_preserves_array_items_schema_and_rejects_undeclared_fields(): void
    {
        $spec = [
            'paths' => [
                '/items' => [
                    'post' => [
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'allOf' => [
                                            [
                                                'type' => 'array',
                                                'items' => [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'name' => ['type' => 'string'],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => ['200' => ['description' => 'OK']],
                    ],
                ],
            ],
        ];

        // Valid payload
        $validDsl = [
            'dsl_version' => '1.0',
            'title' => 'Post array items',
            'endpoint' => ['method' => 'POST', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'step 1',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/items',
                        'body' => [
                            ['name' => 'Widget'],
                        ],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];
        $validRes = $this->validator->validate($validDsl, $spec);
        $this->assertTrue($validRes->isValid, 'Array allOf items schema validation failed: '.implode('; ', $validRes->errors));

        // Invalid payload with ghost property
        $invalidDsl = $validDsl;
        $invalidDsl['steps'][0]['request']['body'] = [['ghost' => 'x']];
        $invalidRes = $this->validator->validate($invalidDsl, $spec);
        $this->assertFalse($invalidRes->isValid);
        $this->assertStringContainsString("Trường '[0].ghost' trong request body không tồn tại", $invalidRes->semanticErrors[0] ?? '');
    }

    public function test_regression_one_of_and_any_of_with_ref_branches_merges_parent_properties(): void
    {
        $spec = [
            'paths' => [
                '/hybrid-oneof' => [
                    'post' => [
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'common' => ['type' => 'string'],
                                        ],
                                        'oneOf' => [
                                            ['$ref' => '#/components/schemas/ChildSchema'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => ['200' => ['description' => 'OK']],
                    ],
                ],
                '/hybrid-anyof' => [
                    'post' => [
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'common' => ['type' => 'string'],
                                        ],
                                        'anyOf' => [
                                            ['$ref' => '#/components/schemas/ChildSchema'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => ['200' => ['description' => 'OK']],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'ChildSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];

        // Valid payload for oneOf
        $oneOfDsl = [
            'dsl_version' => '1.0',
            'title' => 'Test oneOf with ref and parent properties',
            'endpoint' => ['method' => 'POST', 'path' => '/hybrid-oneof'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'step 1',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/hybrid-oneof',
                        'body' => [
                            'common' => 'x',
                            'name' => 'ok',
                        ],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];
        $oneOfRes = $this->validator->validate($oneOfDsl, $spec);
        $this->assertTrue($oneOfRes->isValid, 'oneOf ref branch validation failed: '.implode('; ', $oneOfRes->errors));

        // Valid payload for anyOf
        $anyOfDsl = $oneOfDsl;
        $anyOfDsl['endpoint']['path'] = '/hybrid-anyof';
        $anyOfDsl['steps'][0]['request']['path'] = '/hybrid-anyof';
        $anyOfRes = $this->validator->validate($anyOfDsl, $spec);
        $this->assertTrue($anyOfRes->isValid, 'anyOf ref branch validation failed: '.implode('; ', $anyOfRes->errors));

        // Invalid payload with undeclared field
        $invalidDsl = $oneOfDsl;
        $invalidDsl['steps'][0]['request']['body']['unknown'] = 'bad';
        $invalidRes = $this->validator->validate($invalidDsl, $spec);
        $this->assertFalse($invalidRes->isValid);
        $this->assertStringContainsString("Trường 'unknown' trong request body không tồn tại", $invalidRes->semanticErrors[0] ?? '');
    }

    public function test_regression_additional_properties_handling(): void
    {
        $spec = [
            'paths' => [
                '/dynamic' => [
                    'post' => [
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'additionalProperties' => ['type' => 'string'],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => ['200' => ['description' => 'OK']],
                    ],
                ],
                '/map' => [
                    'post' => [
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'additionalProperties' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'name' => ['type' => 'string'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => ['200' => ['description' => 'OK']],
                    ],
                ],
            ],
        ];

        // 3a: dynamic keys should be accepted
        $dynDsl = [
            'dsl_version' => '1.0',
            'title' => 'Test dynamic keys',
            'endpoint' => ['method' => 'POST', 'path' => '/dynamic'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'step 1',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/dynamic',
                        'body' => ['label' => 'custom', 'tag' => 'v1'],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];
        $dynRes = $this->validator->validate($dynDsl, $spec);
        $this->assertTrue($dynRes->isValid, 'Dynamic keys failed: '.implode('; ', $dynRes->errors));

        // 3b: map valid payload
        $mapDsl = [
            'dsl_version' => '1.0',
            'title' => 'Test map entries',
            'endpoint' => ['method' => 'POST', 'path' => '/map'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'step 1',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/map',
                        'body' => ['entry' => ['name' => 'item1']],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];
        $mapRes = $this->validator->validate($mapDsl, $spec);
        $this->assertTrue($mapRes->isValid, 'Map valid payload failed: '.implode('; ', $mapRes->errors));

        // 3b: map invalid payload with ghost field inside map value
        $invalidMapDsl = $mapDsl;
        $invalidMapDsl['steps'][0]['request']['body'] = ['entry' => ['ghost' => 'x']];
        $invalidMapRes = $this->validator->validate($invalidMapDsl, $spec);
        $this->assertFalse($invalidMapRes->isValid);
        $this->assertStringContainsString("Trường 'entry.ghost' trong request body không tồn tại", $invalidMapRes->semanticErrors[0] ?? '');
    }

    public function test_regression_inline_json_schema_assertion_syntax_validation(): void
    {
        $spec = [
            'paths' => [
                '/check' => [
                    'get' => [
                        'responses' => ['200' => ['description' => 'OK']],
                    ],
                ],
            ],
        ];

        // Valid inline json schema
        $validDsl = [
            'dsl_version' => '1.0',
            'title' => 'Valid inline schema',
            'endpoint' => ['method' => 'GET', 'path' => '/check'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'step 1',
                    'request' => ['method' => 'GET', 'path' => '/check'],
                    'assertions' => [
                        ['type' => 'status', 'equals' => 200],
                        [
                            'type' => 'json_schema',
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'id' => ['type' => 'integer'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $validRes = $this->validator->validate($validDsl, $spec);
        $this->assertTrue($validRes->isValid, 'Valid inline schema assertion failed: '.implode('; ', $validRes->errors));

        // Invalid inline json schema with unknown type
        $invalidDsl = $validDsl;
        $invalidDsl['steps'][0]['assertions'][1]['schema'] = ['type' => 'imaginary_type'];
        $invalidRes = $this->validator->validate($invalidDsl, $spec);
        $this->assertFalse($invalidRes->isValid);
        $this->assertStringContainsString('Cấu trúc JSON Schema inline không hợp lệ', $invalidRes->semanticErrors[0] ?? '');
        $this->assertStringContainsString('type', $invalidRes->semanticErrors[0] ?? '');
    }

    public function test_regression_inline_empty_json_schema_is_accepted(): void
    {
        $spec = [
            'paths' => [
                '/check' => [
                    'get' => [
                        'responses' => ['200' => ['description' => 'OK']],
                    ],
                ],
            ],
        ];

        // JSON string with empty inline JSON Schema {}
        $jsonDsl = json_encode([
            'dsl_version' => '1.0',
            'title' => 'Empty inline schema',
            'endpoint' => ['method' => 'GET', 'path' => '/check'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'step 1',
                    'request' => ['method' => 'GET', 'path' => '/check'],
                    'assertions' => [
                        ['type' => 'status', 'equals' => 200],
                        ['type' => 'json_schema', 'schema' => new \stdClass],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $res = $this->validator->validate($jsonDsl, $spec);
        $this->assertTrue($res->isValid, 'Empty inline schema {} should be valid: '.implode('; ', $res->errors));

        // Array with empty object schema (object) []
        $arrayDsl = [
            'dsl_version' => '1.0',
            'title' => 'Empty inline schema object representation',
            'endpoint' => ['method' => 'GET', 'path' => '/check'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'step 1',
                    'request' => ['method' => 'GET', 'path' => '/check'],
                    'assertions' => [
                        ['type' => 'status', 'equals' => 200],
                        ['type' => 'json_schema', 'schema' => (object) []],
                    ],
                ],
            ],
        ];

        $resArray = $this->validator->validate($arrayDsl, $spec);
        $this->assertTrue($resArray->isValid, 'Empty object schema (object) [] should be valid: '.implode('; ', $resArray->errors));
    }

    public function test_regression_inline_json_schema_rejects_invalid_keyword_types(): void
    {
        $spec = [
            'paths' => [
                '/check' => [
                    'get' => [
                        'responses' => ['200' => ['description' => 'OK']],
                    ],
                ],
            ],
        ];

        // minimum is string "wrong" instead of number
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Wrong keyword type',
            'endpoint' => ['method' => 'GET', 'path' => '/check'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'step 1',
                    'request' => ['method' => 'GET', 'path' => '/check'],
                    'assertions' => [
                        ['type' => 'status', 'equals' => 200],
                        [
                            'type' => 'json_schema',
                            'schema' => [
                                'type' => 'number',
                                'minimum' => 'wrong',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $res = $this->validator->validate($dsl, $spec);
        $this->assertFalse($res->isValid, 'Schema with minimum: "wrong" must be rejected');
        $this->assertStringContainsString('Cấu trúc JSON Schema inline không hợp lệ', $res->semanticErrors[0] ?? '');
        $this->assertStringContainsString('minimum', $res->semanticErrors[0] ?? '');
    }

    public function test_regression_numeric_key_object_body_detects_undeclared_properties(): void
    {
        $spec = [
            'paths' => [
                '/numeric-keys' => [
                    'post' => [
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            '0' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'name' => ['type' => 'string'],
                                                ],
                                                'additionalProperties' => false,
                                            ],
                                        ],
                                        'additionalProperties' => false,
                                    ],
                                ],
                            ],
                        ],
                        'responses' => ['200' => ['description' => 'OK']],
                    ],
                ],
            ],
        ];

        // Valid payload {"0": {"name": "test"}}
        $validJson = json_encode([
            'dsl_version' => '1.0',
            'title' => 'Valid numeric key object',
            'endpoint' => ['method' => 'POST', 'path' => '/numeric-keys'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'step 1',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/numeric-keys',
                        'body' => ['0' => ['name' => 'test']],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $validRes = $this->validator->validate($validJson, $spec);
        $this->assertTrue($validRes->isValid, 'Valid numeric key payload failed: '.implode('; ', $validRes->errors));

        // Invalid payload {"0": {"ghost": "x"}}
        $invalidJson = json_encode([
            'dsl_version' => '1.0',
            'title' => 'Invalid numeric key object',
            'endpoint' => ['method' => 'POST', 'path' => '/numeric-keys'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'step 1',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/numeric-keys',
                        'body' => ['0' => ['ghost' => 'x']],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $invalidRes = $this->validator->validate($invalidJson, $spec);
        $this->assertFalse($invalidRes->isValid, 'Payload {"0": {"ghost": "x"}} must be rejected');
        $this->assertStringContainsString("Trường '0.ghost' trong request body không tồn tại", $invalidRes->semanticErrors[0] ?? '');
    }

    public function test_all_of_merging_preserves_additional_properties_false(): void
    {
        $spec = [
            'paths' => [
                '/merged' => [
                    'post' => [
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'allOf' => [
                                            [
                                                'type' => 'object',
                                                'properties' => ['a' => ['type' => 'string']],
                                                'additionalProperties' => true,
                                            ],
                                            [
                                                'type' => 'object',
                                                'properties' => ['b' => ['type' => 'integer']],
                                                'additionalProperties' => false,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => ['200' => ['description' => 'OK']],
                    ],
                ],
            ],
        ];

        // Valid payload with only allowed properties
        $validDsl = [
            'dsl_version' => '1.0',
            'title' => 'Valid allOf payload',
            'endpoint' => ['method' => 'POST', 'path' => '/merged'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'step 1',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/merged',
                        'body' => ['a' => 'hello', 'b' => 42],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];
        $validRes = $this->validator->validate($validDsl, $spec);
        $this->assertTrue($validRes->isValid, 'Valid payload should pass: '.implode('; ', $validRes->errors));

        // Invalid payload with unknown property - must be rejected because branch 2 has additionalProperties: false
        $invalidDsl = [
            'dsl_version' => '1.0',
            'title' => 'Invalid allOf payload with unknown property',
            'endpoint' => ['method' => 'POST', 'path' => '/merged'],
            'technique' => 'INVALID_INPUT',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'step 1',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/merged',
                        'body' => ['a' => 'hello', 'b' => 42, 'unknown' => 1],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];
        $invalidRes = $this->validator->validate($invalidDsl, $spec);
        $this->assertFalse($invalidRes->isValid, 'Payload with unknown property should be rejected when any branch specifies additionalProperties: false');
        $this->assertStringContainsString("Trường 'unknown' trong request body không tồn tại trong schema", $invalidRes->semanticErrors[0] ?? '');
    }

    public function test_inline_schema_with_invalid_prefix_items_is_rejected(): void
    {
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Invalid prefixItems schema',
            'endpoint' => ['method' => 'GET', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'step 1',
                    'request' => ['method' => 'GET', 'path' => '/items'],
                    'assertions' => [
                        [
                            'type' => 'json_schema',
                            'schema' => ['type' => 'array', 'prefixItems' => 'invalid'],
                        ],
                    ],
                ],
            ],
        ];

        $res = $this->validator->validate($dsl);
        $this->assertFalse($res->isValid, 'Schema with prefixItems string should be rejected');
        $this->assertStringContainsString('Cấu trúc JSON Schema inline không hợp lệ', $res->semanticErrors[0] ?? '');
    }

    public function test_inline_schema_with_empty_any_of_is_rejected(): void
    {
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Empty anyOf schema',
            'endpoint' => ['method' => 'GET', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'step 1',
                    'request' => ['method' => 'GET', 'path' => '/items'],
                    'assertions' => [
                        [
                            'type' => 'json_schema',
                            'schema' => ['anyOf' => []],
                        ],
                    ],
                ],
            ],
        ];

        $res = $this->validator->validate($dsl);
        $this->assertFalse($res->isValid, 'Schema with empty anyOf must be rejected');
        $this->assertStringContainsString('Cấu trúc JSON Schema inline không hợp lệ', $res->semanticErrors[0] ?? '');
    }

    public function test_inline_schema_is_validated_when_spec_is_null(): void
    {
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Garbage type inline schema without spec',
            'endpoint' => ['method' => 'GET', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'step 1',
                    'request' => ['method' => 'GET', 'path' => '/items'],
                    'assertions' => [
                        [
                            'type' => 'json_schema',
                            'schema' => ['type' => 'garbage'],
                        ],
                    ],
                ],
            ],
        ];

        $res = $this->validator->validate($dsl, null);
        $this->assertFalse($res->isValid, 'Inline schema with type garbage must be rejected even when spec is null');
        $this->assertStringContainsString('Cấu trúc JSON Schema inline không hợp lệ', $res->semanticErrors[0] ?? '');
    }

    public function test_lcm_and_rational_multiple_of_helper(): void
    {
        $reflection = new \ReflectionClass($this->validator);
        $lcmMethod = $reflection->getMethod('calculateLcm');
        $lcmMethod->setAccessible(true);

        // Large integer LCM
        $largeLcm = $lcmMethod->invoke($this->validator, 2000000001, 2000000101);
        $this->assertSame(4000000204000000101, $largeLcm);

        // Fractional LCM beyond 12 decimal places
        $fracLcm = $lcmMethod->invoke($this->validator, 0.0000010000001, 0.0000010000003);
        $this->assertSame(10.0000040000003, $fracLcm);

        // Validating test case against spec with large integer ref
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Test', 'version' => '1'],
            'paths' => [
                '/items' => [
                    'post' => [
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'array',
                                        'items' => [
                                            '$ref' => '#/components/schemas/Num',
                                            'multipleOf' => 2000000101,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => ['201' => ['description' => 'Created']],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'Num' => [
                        'type' => 'integer',
                        'minimum' => 0,
                        'maximum' => 4000000204000000101,
                        'multipleOf' => 2000000001,
                    ],
                ],
            ],
        ];

        $case = [
            'dsl_version' => '1.0',
            'title' => 'Large integer LCM case',
            'endpoint' => ['method' => 'POST', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'create',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/items',
                        'body' => [0, 4000000204000000101],
                    ],
                    'assertions' => [
                        ['type' => 'status', 'equals' => 201],
                    ],
                ],
            ],
        ];

        $res = $this->validator->validate($case, $spec);
        $this->assertTrue($res->isValid, implode('; ', $res->errors));
    }
}
