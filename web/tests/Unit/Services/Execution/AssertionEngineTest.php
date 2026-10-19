<?php

namespace Tests\Unit\Services\Execution;

use App\Data\Execution\AssertionResult;
use App\Data\Execution\StepExecutionResult;
use App\Data\OpenApi\ParsedOpenApi;
use App\Data\TargetRequest;
use App\Data\TargetResponse;
use App\Enums\ApiSpecFormat;
use App\Enums\TestVerdict;
use App\Models\ApiSpec;
use App\Services\Execution\AssertionEngine;
use App\Services\Execution\TargetAllowlistValidator;
use App\Services\Execution\TestExecutor;
use App\Services\OpenApi\OpenApiParser;
use App\Services\Target\FakeTargetHttpClient;
use PHPUnit\Framework\TestCase;

class AssertionEngineTest extends TestCase
{
    private AssertionEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new AssertionEngine;
    }

    /**
     * @param  array<string, list<string>>  $headers
     */
    private function createResponse(
        int $status = 200,
        array $headers = [],
        string $body = '{}',
        int $durationMs = 50
    ): TargetResponse {
        return new TargetResponse(
            status: $status,
            headers: $headers,
            body: $body,
            durationMs: $durationMs
        );
    }

    // ==========================================
    // 1. STATUS ASSERTION TESTS
    // ==========================================

    public function test_status_assertion_passes_when_equal(): void
    {
        $response = $this->createResponse(status: 200);
        $result = $this->engine->assert(['type' => 'status', 'equals' => 200], $response);

        $this->assertInstanceOf(AssertionResult::class, $result);
        $this->assertSame('status', $result->type);
        $this->assertTrue($result->passed);
        $this->assertSame(200, $result->expected);
        $this->assertSame(200, $result->actual);
        $this->assertNull($result->message);
    }

    public function test_status_assertion_fails_when_not_equal(): void
    {
        $response = $this->createResponse(status: 404);
        $result = $this->engine->assert(['type' => 'status', 'equals' => 200], $response);

        $this->assertFalse($result->passed);
        $this->assertSame(200, $result->expected);
        $this->assertSame(404, $result->actual);
        $this->assertSame('Mong đợi mã trạng thái 200, nhưng nhận được 404.', $result->message);
    }

    public function test_status_assertion_interpolates_variables(): void
    {
        $response = $this->createResponse(status: 201);
        $result = $this->engine->assert(
            ['type' => 'status', 'equals' => '{{expected_code}}'],
            $response,
            variables: ['expected_code' => 201]
        );

        $this->assertTrue($result->passed);
        $this->assertSame(201, $result->expected);
    }

    // ==========================================
    // 2. STATUS_IN ASSERTION TESTS
    // ==========================================

    public function test_status_in_assertion_passes_when_in_list(): void
    {
        $response = $this->createResponse(status: 204);
        $result = $this->engine->assert(['type' => 'status_in', 'values' => [200, 201, 204]], $response);

        $this->assertSame('status_in', $result->type);
        $this->assertTrue($result->passed);
        $this->assertSame([200, 201, 204], $result->expected);
        $this->assertSame(204, $result->actual);
        $this->assertNull($result->message);
    }

    public function test_status_in_assertion_fails_when_not_in_list(): void
    {
        $response = $this->createResponse(status: 500);
        $result = $this->engine->assert(['type' => 'status_in', 'values' => [200, 201]], $response);

        $this->assertFalse($result->passed);
        $this->assertSame([200, 201], $result->expected);
        $this->assertSame(500, $result->actual);
        $this->assertSame('Mong đợi mã trạng thái thuộc [200, 201], nhưng nhận được 500.', $result->message);
    }

    // ==========================================
    // 3. RESPONSE_TIME ASSERTION TESTS
    // ==========================================

    public function test_response_time_assertion_passes_when_lte(): void
    {
        $response = $this->createResponse(durationMs: 120);
        $result = $this->engine->assert(['type' => 'response_time', 'lte_ms' => 200], $response);

        $this->assertSame('response_time', $result->type);
        $this->assertTrue($result->passed);
        $this->assertSame(200, $result->expected);
        $this->assertSame(120, $result->actual);
        $this->assertNull($result->message);
    }

    public function test_response_time_assertion_fails_when_exceeded(): void
    {
        $response = $this->createResponse(durationMs: 350);
        $result = $this->engine->assert(['type' => 'response_time', 'lte_ms' => 200], $response);

        $this->assertFalse($result->passed);
        $this->assertSame(200, $result->expected);
        $this->assertSame(350, $result->actual);
        $this->assertSame('Mong đợi thời gian phản hồi ≤ 200 ms, nhưng mất 350 ms.', $result->message);
    }

    // ==========================================
    // 4. HEADER ASSERTION TESTS
    // ==========================================

    public function test_header_exists_assertion_passes_and_fails(): void
    {
        $response = $this->createResponse(headers: ['content-type' => ['application/json']]);

        // PASS: case-insensitive
        $passResult = $this->engine->assert(['type' => 'header', 'name' => 'Content-Type', 'op' => 'exists'], $response);
        $this->assertTrue($passResult->passed);
        $this->assertSame('application/json', $passResult->actual);

        // FAIL: missing header
        $failResult = $this->engine->assert(['type' => 'header', 'name' => 'X-Request-Id', 'op' => 'exists'], $response);
        $this->assertFalse($failResult->passed);
        $this->assertSame("Mong đợi có header 'X-Request-Id', nhưng phản hồi không có.", $failResult->message);
    }

    public function test_header_not_exists_assertion_passes_and_fails(): void
    {
        $response = $this->createResponse(headers: ['content-type' => ['application/json']]);

        // PASS: not present
        $passResult = $this->engine->assert(['type' => 'header', 'name' => 'X-Deprecated', 'op' => 'not_exists'], $response);
        $this->assertTrue($passResult->passed);
        $this->assertNull($passResult->actual);

        // FAIL: header actually exists
        $failResult = $this->engine->assert(['type' => 'header', 'name' => 'Content-Type', 'op' => 'not_exists'], $response);
        $this->assertFalse($failResult->passed);
        $this->assertSame("Mong đợi không có header 'Content-Type', nhưng nhận được 'application/json'.", $failResult->message);
    }

    public function test_header_eq_assertion_passes_and_fails(): void
    {
        $response = $this->createResponse(headers: ['content-type' => ['application/json']]);

        // PASS
        $passResult = $this->engine->assert(
            ['type' => 'header', 'name' => 'content-type', 'op' => 'eq', 'value' => 'application/json'],
            $response
        );
        $this->assertTrue($passResult->passed);

        // FAIL: wrong value
        $failResult = $this->engine->assert(
            ['type' => 'header', 'name' => 'content-type', 'op' => 'eq', 'value' => 'text/html'],
            $response
        );
        $this->assertFalse($failResult->passed);
        $this->assertSame("Mong đợi header 'content-type' bằng 'text/html', nhưng nhận được 'application/json'.", $failResult->message);

        // FAIL: header missing
        $missingResult = $this->engine->assert(
            ['type' => 'header', 'name' => 'X-Token', 'op' => 'eq', 'value' => 'secret'],
            $response
        );
        $this->assertFalse($missingResult->passed);
        $this->assertStringContainsString('phản hồi không có', (string) $missingResult->message);
    }

    public function test_header_contains_assertion_passes_and_fails(): void
    {
        $response = $this->createResponse(headers: ['content-type' => ['application/json; charset=utf-8']]);

        // PASS
        $passResult = $this->engine->assert(
            ['type' => 'header', 'name' => 'content-type', 'op' => 'contains', 'value' => 'charset=utf-8'],
            $response
        );
        $this->assertTrue($passResult->passed);

        // FAIL
        $failResult = $this->engine->assert(
            ['type' => 'header', 'name' => 'content-type', 'op' => 'contains', 'value' => 'charset=iso-8859-1'],
            $response
        );
        $this->assertFalse($failResult->passed);
    }

    public function test_header_matches_assertion_passes_and_fails(): void
    {
        $response = $this->createResponse(headers: ['content-type' => ['application/json; charset=utf-8']]);

        // PASS
        $passResult = $this->engine->assert(
            ['type' => 'header', 'name' => 'content-type', 'op' => 'matches', 'value' => '^application/json'],
            $response
        );
        $this->assertTrue($passResult->passed);

        // FAIL
        $failResult = $this->engine->assert(
            ['type' => 'header', 'name' => 'content-type', 'op' => 'matches', 'value' => '^text/'],
            $response
        );
        $this->assertFalse($failResult->passed);

        // FAIL: invalid pattern
        $invalidResult = $this->engine->assert(
            ['type' => 'header', 'name' => 'content-type', 'op' => 'matches', 'value' => '[unclosed'],
            $response
        );
        $this->assertFalse($invalidResult->passed);
        $this->assertStringContainsString('Biểu thức chính quy (regex) không hợp lệ', (string) $invalidResult->message);
    }

    // ==========================================
    // 5. JSON_PATH ASSERTION TESTS
    // ==========================================

    public function test_json_path_exists_and_not_exists(): void
    {
        $body = json_encode([
            'id' => 101,
            'name' => 'Laptop',
            'deleted_at' => null, // Explicit null key
            'tags' => ['pc', 'gaming'],
        ], JSON_THROW_ON_ERROR);
        $response = $this->createResponse(body: $body);

        // PASS: key exists
        $res1 = $this->engine->assert(['type' => 'json_path', 'path' => '$.name', 'op' => 'exists'], $response);
        $this->assertTrue($res1->passed);
        $this->assertSame('Laptop', $res1->actual);

        // PASS: explicit null key exists
        $res2 = $this->engine->assert(['type' => 'json_path', 'path' => '$.deleted_at', 'op' => 'exists'], $response);
        $this->assertTrue($res2->passed);
        $this->assertNull($res2->actual);

        // FAIL: non-existent key
        $res3 = $this->engine->assert(['type' => 'json_path', 'path' => '$.category.id', 'op' => 'exists'], $response);
        $this->assertFalse($res3->passed);
        $this->assertSame("Đường dẫn '$.category.id' không tồn tại trong JSON phản hồi.", $res3->message);

        // PASS: not_exists
        $res4 = $this->engine->assert(['type' => 'json_path', 'path' => '$.category', 'op' => 'not_exists'], $response);
        $this->assertTrue($res4->passed);

        // FAIL: not_exists when it exists
        $res5 = $this->engine->assert(['type' => 'json_path', 'path' => '$.id', 'op' => 'not_exists'], $response);
        $this->assertFalse($res5->passed);
    }

    public function test_json_path_eq_and_ne_with_type_strictness(): void
    {
        $body = json_encode([
            'count' => 10,
            'code' => '10',
            'status' => 'PENDING',
            'active' => true,
        ], JSON_THROW_ON_ERROR);
        $response = $this->createResponse(body: $body);

        // PASS: numeric eq
        $res1 = $this->engine->assert(['type' => 'json_path', 'path' => '$.count', 'op' => 'eq', 'value' => 10], $response);
        $this->assertTrue($res1->passed);

        // FAIL: type strictness - number 10 is not string "10"
        $res2 = $this->engine->assert(['type' => 'json_path', 'path' => '$.count', 'op' => 'eq', 'value' => '10'], $response);
        $this->assertFalse($res2->passed);

        // PASS: boolean eq
        $res3 = $this->engine->assert(['type' => 'json_path', 'path' => '$.active', 'op' => 'eq', 'value' => true], $response);
        $this->assertTrue($res3->passed);

        // PASS: ne
        $res4 = $this->engine->assert(['type' => 'json_path', 'path' => '$.status', 'op' => 'ne', 'value' => 'COMPLETED'], $response);
        $this->assertTrue($res4->passed);

        // FAIL: ne when equal
        $res5 = $this->engine->assert(['type' => 'json_path', 'path' => '$.status', 'op' => 'ne', 'value' => 'PENDING'], $response);
        $this->assertFalse($res5->passed);
    }

    public function test_json_path_numeric_comparisons(): void
    {
        $body = json_encode(['price' => 150.5, 'stock' => 5], JSON_THROW_ON_ERROR);
        $response = $this->createResponse(body: $body);

        // gt
        $this->assertTrue($this->engine->assert(['type' => 'json_path', 'path' => '$.price', 'op' => 'gt', 'value' => 100], $response)->passed);
        $this->assertFalse($this->engine->assert(['type' => 'json_path', 'path' => '$.price', 'op' => 'gt', 'value' => 200], $response)->passed);

        // gte
        $this->assertTrue($this->engine->assert(['type' => 'json_path', 'path' => '$.price', 'op' => 'gte', 'value' => 150.5], $response)->passed);
        $this->assertFalse($this->engine->assert(['type' => 'json_path', 'path' => '$.price', 'op' => 'gte', 'value' => 151], $response)->passed);

        // lt
        $this->assertTrue($this->engine->assert(['type' => 'json_path', 'path' => '$.stock', 'op' => 'lt', 'value' => 10], $response)->passed);
        $this->assertFalse($this->engine->assert(['type' => 'json_path', 'path' => '$.stock', 'op' => 'lt', 'value' => 5], $response)->passed);

        // lte
        $this->assertTrue($this->engine->assert(['type' => 'json_path', 'path' => '$.stock', 'op' => 'lte', 'value' => 5], $response)->passed);
        $this->assertFalse($this->engine->assert(['type' => 'json_path', 'path' => '$.stock', 'op' => 'lte', 'value' => 4], $response)->passed);
    }

    public function test_json_path_contains_and_matches(): void
    {
        $body = json_encode([
            'description' => 'Fast delivery guaranteed',
            'tags' => ['urgent', 'prime'],
            'email' => 'support@example.com',
        ], JSON_THROW_ON_ERROR);
        $response = $this->createResponse(body: $body);

        // contains string
        $this->assertTrue($this->engine->assert(['type' => 'json_path', 'path' => '$.description', 'op' => 'contains', 'value' => 'delivery'], $response)->passed);
        $this->assertFalse($this->engine->assert(['type' => 'json_path', 'path' => '$.description', 'op' => 'contains', 'value' => 'slow'], $response)->passed);

        // contains array element
        $this->assertTrue($this->engine->assert(['type' => 'json_path', 'path' => '$.tags', 'op' => 'contains', 'value' => 'prime'], $response)->passed);
        $this->assertFalse($this->engine->assert(['type' => 'json_path', 'path' => '$.tags', 'op' => 'contains', 'value' => 'free'], $response)->passed);

        // matches regex
        $this->assertTrue($this->engine->assert(['type' => 'json_path', 'path' => '$.email', 'op' => 'matches', 'value' => '^support@'], $response)->passed);
        $this->assertFalse($this->engine->assert(['type' => 'json_path', 'path' => '$.email', 'op' => 'matches', 'value' => '^admin@'], $response)->passed);
    }

    public function test_json_path_type_operator(): void
    {
        $body = json_encode([
            'str' => 'text',
            'num_int' => 42,
            'num_float' => 3.14,
            'flag' => false,
            'arr' => [1, 2, 3],
            'obj' => ['key' => 'val'],
            'nil' => null,
        ], JSON_THROW_ON_ERROR);
        $response = $this->createResponse(body: $body);

        $this->assertTrue($this->engine->assert(['type' => 'json_path', 'path' => '$.str', 'op' => 'type', 'value' => 'string'], $response)->passed);
        $this->assertTrue($this->engine->assert(['type' => 'json_path', 'path' => '$.num_int', 'op' => 'type', 'value' => 'integer'], $response)->passed);
        $this->assertTrue($this->engine->assert(['type' => 'json_path', 'path' => '$.num_float', 'op' => 'type', 'value' => 'number'], $response)->passed);
        $this->assertTrue($this->engine->assert(['type' => 'json_path', 'path' => '$.flag', 'op' => 'type', 'value' => 'boolean'], $response)->passed);
        $this->assertTrue($this->engine->assert(['type' => 'json_path', 'path' => '$.arr', 'op' => 'type', 'value' => 'array'], $response)->passed);
        $this->assertTrue($this->engine->assert(['type' => 'json_path', 'path' => '$.obj', 'op' => 'type', 'value' => 'object'], $response)->passed);
        $this->assertTrue($this->engine->assert(['type' => 'json_path', 'path' => '$.nil', 'op' => 'type', 'value' => 'null'], $response)->passed);

        // FAIL: wrong type
        $fail = $this->engine->assert(['type' => 'json_path', 'path' => '$.str', 'op' => 'type', 'value' => 'integer'], $response);
        $this->assertFalse($fail->passed);
        $this->assertSame("Mong đợi '$.str' có kiểu 'integer', nhưng nhận được 'string'.", $fail->message);
    }

    public function test_json_path_handles_nested_and_bracket_syntax(): void
    {
        $body = json_encode([
            'users' => [
                ['id' => 1, 'name' => 'Alice'],
                ['id' => 2, 'name' => 'Bob'],
            ],
            'custom-key' => 'value-1',
            'dotted.name' => 'value-2',
        ], JSON_THROW_ON_ERROR);
        $response = $this->createResponse(body: $body);

        $this->assertTrue($this->engine->assert(['type' => 'json_path', 'path' => '$.users[0].name', 'op' => 'eq', 'value' => 'Alice'], $response)->passed);
        $this->assertTrue($this->engine->assert(['type' => 'json_path', 'path' => '$.users[1].id', 'op' => 'eq', 'value' => 2], $response)->passed);
        $this->assertTrue($this->engine->assert(['type' => 'json_path', 'path' => '$["custom-key"]', 'op' => 'eq', 'value' => 'value-1'], $response)->passed);
        $this->assertTrue($this->engine->assert(['type' => 'json_path', 'path' => '$[\'dotted.name\']', 'op' => 'eq', 'value' => 'value-2'], $response)->passed);
    }

    public function test_json_path_fails_gracefully_on_invalid_json(): void
    {
        $response = $this->createResponse(body: '<html><body>502 Bad Gateway</body></html>');

        $result = $this->engine->assert(['type' => 'json_path', 'path' => '$.error', 'op' => 'exists'], $response);
        $this->assertFalse($result->passed);
        $this->assertStringContainsString('Body phản hồi không phải JSON hợp lệ', (string) $result->message);
    }

    // ==========================================
    // 6. JSON_SCHEMA ASSERTION TESTS
    // ==========================================

    public function test_json_schema_inline_passes_and_fails(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['id', 'title'],
            'properties' => [
                'id' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
            ],
        ];

        // PASS
        $passBody = json_encode(['id' => 1, 'title' => 'Article 1'], JSON_THROW_ON_ERROR);
        $passRes = $this->engine->assert(
            ['type' => 'json_schema', 'schema' => $schema],
            $this->createResponse(body: $passBody)
        );
        $this->assertTrue($passRes->passed);
        $this->assertNull($passRes->message);

        // FAIL: missing required title
        $failBody = json_encode(['id' => 1], JSON_THROW_ON_ERROR);
        $failRes = $this->engine->assert(
            ['type' => 'json_schema', 'schema' => $schema],
            $this->createResponse(body: $failBody)
        );
        $this->assertFalse($failRes->passed);
        $this->assertNotNull($failRes->message);
        $this->assertStringContainsString('title', (string) $failRes->message);
    }

    public function test_json_schema_ref_resolved_against_spec(): void
    {
        $spec = [
            'components' => [
                'schemas' => [
                    'Product' => [
                        'type' => 'object',
                        'required' => ['sku', 'price'],
                        'properties' => [
                            'sku' => ['type' => 'string'],
                            'price' => ['type' => 'number', 'minimum' => 0],
                        ],
                    ],
                ],
            ],
        ];

        // PASS
        $validBody = json_encode(['sku' => 'SKU-001', 'price' => 29.99], JSON_THROW_ON_ERROR);
        $passRes = $this->engine->assert(
            ['type' => 'json_schema', 'schema_ref' => '#/components/schemas/Product'],
            $this->createResponse(body: $validBody),
            spec: $spec
        );
        $this->assertTrue($passRes->passed);

        // FAIL: negative price
        $invalidBody = json_encode(['sku' => 'SKU-001', 'price' => -5], JSON_THROW_ON_ERROR);
        $failRes = $this->engine->assert(
            ['type' => 'json_schema', 'schema_ref' => '#/components/schemas/Product'],
            $this->createResponse(body: $invalidBody),
            spec: $spec
        );
        $this->assertFalse($failRes->passed);
        $this->assertNotNull($failRes->message);

        // FAIL: schema_ref does not exist
        $missingRefRes = $this->engine->assert(
            ['type' => 'json_schema', 'schema_ref' => '#/components/schemas/NonExistent'],
            $this->createResponse(body: $validBody),
            spec: $spec
        );
        $this->assertFalse($missingRefRes->passed);
        $this->assertStringContainsString('không tồn tại', (string) $missingRefRes->message);

        // FAIL: spec not provided
        $noSpecRes = $this->engine->assert(
            ['type' => 'json_schema', 'schema_ref' => '#/components/schemas/Product'],
            $this->createResponse(body: $validBody),
            spec: null
        );
        $this->assertFalse($noSpecRes->passed);
        $this->assertStringContainsString('không có đặc tả OpenAPI', (string) $noSpecRes->message);
    }

    public function test_json_schema_ref_with_parsed_openapi(): void
    {
        $parsedSpec = new ParsedOpenApi(
            isValid: true,
            format: ApiSpecFormat::Json,
            openapiVersion: '3.0.3',
            title: 'Store API',
            normalized: [
                'components' => [
                    'schemas' => [
                        'Item' => [
                            'type' => 'object',
                            'required' => ['name'],
                            'properties' => ['name' => ['type' => 'string']],
                        ],
                    ],
                ],
            ]
        );

        $body = json_encode(['name' => 'Notebook'], JSON_THROW_ON_ERROR);
        $res = $this->engine->assert(
            ['type' => 'json_schema', 'schema_ref' => '#/components/schemas/Item'],
            $this->createResponse(body: $body),
            spec: $parsedSpec
        );
        $this->assertTrue($res->passed);
    }

    // ==========================================
    // 7. MULTI-ASSERTION AND VERDICT TESTS
    // ==========================================

    public function test_assert_step_and_determine_verdict(): void
    {
        $step = [
            'name' => 'Create order step',
            'assertions' => [
                ['type' => 'status', 'equals' => 201],
                ['type' => 'header', 'name' => 'Content-Type', 'op' => 'contains', 'value' => 'json'],
                ['type' => 'json_path', 'path' => '$.order_id', 'op' => 'gt', 'value' => 0],
                ['type' => 'response_time', 'lte_ms' => 1000],
            ],
        ];

        // All passing case
        $responsePass = $this->createResponse(
            status: 201,
            headers: ['content-type' => ['application/json']],
            body: json_encode(['order_id' => 456], JSON_THROW_ON_ERROR),
            durationMs: 150
        );
        $resultsPass = $this->engine->assertStep($step, $responsePass);
        $this->assertCount(4, $resultsPass);
        $this->assertSame(TestVerdict::Pass, $this->engine->determineVerdict($resultsPass));

        // One failing case (status 200 instead of 201)
        $responseFail = $this->createResponse(
            status: 200,
            headers: ['content-type' => ['application/json']],
            body: json_encode(['order_id' => 456], JSON_THROW_ON_ERROR),
            durationMs: 150
        );
        $resultsFail = $this->engine->assertStep($step, $responseFail);
        $this->assertCount(4, $resultsFail);
        $this->assertFalse($resultsFail[0]->passed); // status failed
        $this->assertTrue($resultsFail[1]->passed); // header passed
        $this->assertTrue($resultsFail[2]->passed); // json_path passed
        $this->assertTrue($resultsFail[3]->passed); // response_time passed
        $this->assertSame(TestVerdict::Fail, $this->engine->determineVerdict($resultsFail));
    }

    public function test_assertion_result_to_array_conforms_to_openapi_contract(): void
    {
        $result = new AssertionResult(
            type: 'status',
            passed: true,
            expected: 200,
            actual: 200,
            message: null
        );

        $array = $result->toArray();
        $this->assertSame([
            'type' => 'status',
            'passed' => true,
            'expected' => 200,
            'actual' => 200,
            'message' => null,
        ], $array);
    }

    // ==========================================
    // 8. TEST EXECUTOR INTEGRATION WITH ASSERTIONS
    // ==========================================

    public function test_test_executor_integrates_with_assertion_engine(): void
    {
        $fakeClient = new FakeTargetHttpClient;
        $fakeClient->respondWith(201, ['id' => 999, 'name' => 'New Widget']);

        $executor = new TestExecutor(
            targetClient: $fakeClient,
            allowlistValidator: new TargetAllowlistValidator(['127.0.0.1']),
            assertionEngine: $this->engine
        );

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Test with AssertionEngine',
            'endpoint' => ['method' => 'POST', 'path' => '/widgets'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'Create widget',
                    'request' => ['method' => 'POST', 'path' => '/widgets', 'body' => ['name' => 'New Widget']],
                    'assertions' => [
                        ['type' => 'status', 'equals' => 201],
                        ['type' => 'json_path', 'path' => '$.id', 'op' => 'eq', 'value' => 999],
                    ],
                ],
            ],
        ];

        $execResult = $executor->execute($dsl, 'http://127.0.0.1');
        $this->assertTrue($execResult->isSuccess);

        $step = $execResult->lastStep();
        $this->assertNotNull($step);
        $this->assertCount(2, $step->assertionResults);
        $this->assertTrue($step->assertionResults[0]->passed);
        $this->assertTrue($step->assertionResults[1]->passed);

        // Now test when assertion fails in executor
        $fakeClient->respondWith(200, ['id' => 999]); // returns 200, expected 201
        $execResultFail = $executor->execute($dsl, 'http://127.0.0.1');
        $this->assertFalse($execResultFail->isSuccess);
        $this->assertStringContainsString('assertion không đạt', (string) $execResultFail->errorMessage);
    }

    // ==========================================
    // 9. REVIEW FINDINGS REGRESSION TESTS (PR #86)
    // ==========================================

    /**
     * Finding 1: TestExecutor must not skip assertions when AssertionEngine is omitted.
     */
    public function test_test_executor_defaults_to_running_assertions_without_explicit_engine(): void
    {
        $fakeClient = new FakeTargetHttpClient;
        $fakeClient->respondWith(500, ['error' => 'Internal Server Error']);

        // Instantiated WITHOUT passing AssertionEngine
        $executor = new TestExecutor(
            targetClient: $fakeClient,
            allowlistValidator: new TargetAllowlistValidator(['127.0.0.1'])
        );

        $dsl = [
            'steps' => [
                [
                    'name' => 'Should return 200',
                    'request' => ['method' => 'GET', 'path' => '/api/test'],
                    'assertions' => [
                        ['type' => 'status', 'equals' => 200],
                    ],
                ],
            ],
        ];

        $execResult = $executor->execute($dsl, 'http://127.0.0.1');

        $this->assertFalse($execResult->isSuccess);
        $this->assertCount(1, $execResult->stepResults);
        $step = $execResult->stepResults[0];
        $this->assertFalse($step->isSuccess);
        $this->assertCount(1, $step->assertionResults);
        $this->assertFalse($step->assertionResults[0]->passed);
        $this->assertSame(200, $step->assertionResults[0]->expected);
        $this->assertSame(500, $step->assertionResults[0]->actual);
    }

    /**
     * Finding 2: schema_ref must work seamlessly through TestExecutor.
     */
    public function test_test_executor_propagates_spec_for_schema_ref(): void
    {
        $fakeClient = new FakeTargetHttpClient;
        $fakeClient->respondWith(200, ['id' => 101, 'name' => 'Gadget']);

        $executor = new TestExecutor(
            targetClient: $fakeClient,
            allowlistValidator: new TargetAllowlistValidator(['127.0.0.1'])
        );

        $spec = [
            'components' => [
                'schemas' => [
                    'Item' => [
                        'type' => 'object',
                        'required' => ['id', 'name'],
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];

        $dsl = [
            'steps' => [
                [
                    'name' => 'Validate Item schema',
                    'request' => ['method' => 'GET', 'path' => '/items/101'],
                    'assertions' => [
                        ['type' => 'json_schema', 'schema_ref' => '#/components/schemas/Item'],
                    ],
                ],
            ],
        ];

        // 1. Pass spec directly to execute()
        $execResult = $executor->execute($dsl, 'http://127.0.0.1', spec: $spec);
        $this->assertTrue($execResult->isSuccess);
        $this->assertCount(1, $execResult->stepResults[0]->assertionResults);
        $this->assertTrue($execResult->stepResults[0]->assertionResults[0]->passed);

        // 2. Fails when response violates schema_ref
        $fakeClient->respondWith(200, ['id' => 'not-an-int', 'name' => 'Gadget']);
        $failResult = $executor->execute($dsl, 'http://127.0.0.1', spec: $spec);
        $this->assertFalse($failResult->isSuccess);
        $this->assertFalse($failResult->stepResults[0]->assertionResults[0]->passed);

        // 3. Works when defaultSpec is set on executor
        $executorWithDefaultSpec = new TestExecutor(
            targetClient: $fakeClient,
            allowlistValidator: new TargetAllowlistValidator(['127.0.0.1']),
            defaultSpec: $spec
        );
        $fakeClient->respondWith(200, ['id' => 202, 'name' => 'Another Gadget']);
        $defaultSpecResult = $executorWithDefaultSpec->execute($dsl, 'http://127.0.0.1');
        $this->assertTrue($defaultSpecResult->isSuccess);
    }

    /**
     * Finding 3: OpenAPI 3.0 nullable: true support for primitive types and schema_ref.
     */
    public function test_json_schema_openapi_3_nullable_support(): void
    {
        $schema = [
            'type' => 'string',
            'nullable' => true,
        ];

        // PASS: null value with nullable: true
        $resNull = $this->createResponse(body: 'null');
        $result1 = $this->engine->assert(['type' => 'json_schema', 'schema' => $schema], $resNull);
        $this->assertTrue($result1->passed, 'OpenAPI 3.0 nullable string must accept null');

        // PASS: string value
        $resStr = $this->createResponse(body: '"hello"');
        $result2 = $this->engine->assert(['type' => 'json_schema', 'schema' => $schema], $resStr);
        $this->assertTrue($result2->passed, 'OpenAPI 3.0 nullable string must accept string');

        // FAIL: number value
        $resNum = $this->createResponse(body: '123');
        $result3 = $this->engine->assert(['type' => 'json_schema', 'schema' => $schema], $resNum);
        $this->assertFalse($result3->passed, 'OpenAPI 3.0 nullable string must reject integer');

        // schema_ref with nullable: true object
        $spec = [
            'components' => [
                'schemas' => [
                    'NullableProfile' => [
                        'type' => 'object',
                        'nullable' => true,
                        'properties' => [
                            'bio' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];

        $assertion = ['type' => 'json_schema', 'schema_ref' => '#/components/schemas/NullableProfile'];

        // PASS: null response with nullable object ref
        $this->assertTrue($this->engine->assert($assertion, $resNull, spec: $spec)->passed);

        // PASS: valid object
        $resObj = $this->createResponse(body: '{"bio": "developer"}');
        $this->assertTrue($this->engine->assert($assertion, $resObj, spec: $spec)->passed);

        // FAIL: primitive number
        $this->assertFalse($this->engine->assert($assertion, $resNum, spec: $spec)->passed);
    }

    /**
     * Finding 3: OpenAPI 3.0 boolean exclusiveMinimum / exclusiveMaximum.
     */
    public function test_json_schema_openapi_3_exclusive_minimum_boolean(): void
    {
        $schema = [
            'type' => 'number',
            'minimum' => 10,
            'exclusiveMinimum' => true,
        ];

        $res10 = $this->createResponse(body: '10');
        $res11 = $this->createResponse(body: '11');

        // 10 is equal to minimum, must FAIL when exclusiveMinimum is true
        $result10 = $this->engine->assert(['type' => 'json_schema', 'schema' => $schema], $res10);
        $this->assertFalse($result10->passed);

        // 11 is strictly greater than 10, must PASS
        $result11 = $this->engine->assert(['type' => 'json_schema', 'schema' => $schema], $res11);
        $this->assertTrue($result11->passed);
    }

    /**
     * Finding 4: JSONPath recursive strict equality ("1" != 1, ["1"] does not contain 1).
     */
    public function test_json_path_strict_equality_and_membership(): void
    {
        // {"x": "1"} vs {"x": 1}
        $responseObj = $this->createResponse(body: '{"x": "1"}');
        $resultEq = $this->engine->assert(
            ['type' => 'json_path', 'path' => '$', 'op' => 'eq', 'value' => ['x' => 1]],
            $responseObj
        );
        $this->assertFalse($resultEq->passed, '{"x": "1"} must NOT equal {"x": 1}');

        // Equal with same types
        $resultEqSame = $this->engine->assert(
            ['type' => 'json_path', 'path' => '$', 'op' => 'eq', 'value' => ['x' => '1']],
            $responseObj
        );
        $this->assertTrue($resultEqSame->passed);

        // ["1"] contains 1
        $responseArr = $this->createResponse(body: '["1"]');
        $resultContainsInt = $this->engine->assert(
            ['type' => 'json_path', 'path' => '$', 'op' => 'contains', 'value' => 1],
            $responseArr
        );
        $this->assertFalse($resultContainsInt->passed, '["1"] must NOT contain integer 1');

        // [1] contains "1"
        $responseArrNum = $this->createResponse(body: '[1]');
        $resultContainsStr = $this->engine->assert(
            ['type' => 'json_path', 'path' => '$', 'op' => 'contains', 'value' => '1'],
            $responseArrNum
        );
        $this->assertFalse($resultContainsStr->passed, '[1] must NOT contain string "1"');

        // [1] contains 1
        $resultContainsIntMatch = $this->engine->assert(
            ['type' => 'json_path', 'path' => '$', 'op' => 'contains', 'value' => 1],
            $responseArrNum
        );
        $this->assertTrue($resultContainsIntMatch->passed);
    }

    /**
     * Finding 5: Invalid regex in json_path matches must NOT emit PHP/PHPUnit warning.
     */
    public function test_json_path_invalid_regex_does_not_emit_warning(): void
    {
        $response = $this->createResponse(body: '{"email": "test@example.com"}');

        $result = $this->engine->assert(
            ['type' => 'json_path', 'path' => '$.email', 'op' => 'matches', 'value' => '[unclosed-bracket'],
            $response
        );

        $this->assertFalse($result->passed);
        $this->assertStringContainsString('Biểu thức chính quy (regex) không hợp lệ', (string) $result->message);
    }

    /**
     * Finding 1 (Re-review): ApiSpec model with normalized attribute must resolve schema_ref via TestExecutor.
     */
    public function test_test_executor_propagates_api_spec_model_instance_for_schema_ref(): void
    {
        $fakeClient = new FakeTargetHttpClient;
        $fakeClient->respondWith(200, ['id' => 101, 'name' => 'Gadget']);

        $executor = new TestExecutor(
            targetClient: $fakeClient,
            allowlistValidator: new TargetAllowlistValidator(['127.0.0.1'])
        );

        $apiSpec = new ApiSpec([
            'normalized' => [
                'components' => [
                    'schemas' => [
                        'Item' => [
                            'type' => 'object',
                            'required' => ['id', 'name'],
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'name' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $dsl = [
            'steps' => [
                [
                    'name' => 'Validate Item schema via ApiSpec model',
                    'request' => ['method' => 'GET', 'path' => '/items/101'],
                    'assertions' => [
                        ['type' => 'json_schema', 'schema_ref' => '#/components/schemas/Item'],
                    ],
                ],
            ],
        ];

        // 1. Valid payload passes
        $execResult = $executor->execute($dsl, 'http://127.0.0.1', spec: $apiSpec);
        $this->assertTrue($execResult->isSuccess);
        $this->assertCount(1, $execResult->stepResults[0]->assertionResults);
        $this->assertTrue($execResult->stepResults[0]->assertionResults[0]->passed);

        // 2. Invalid payload fails with schema error (not "OpenAPI specification was not provided")
        $fakeClient->respondWith(200, ['id' => 'not-an-int', 'name' => 'Gadget']);
        $failResult = $executor->execute($dsl, 'http://127.0.0.1', spec: $apiSpec);
        $this->assertFalse($failResult->isSuccess);
        $this->assertStringNotContainsString('không có đặc tả OpenAPI', (string) $failResult->stepResults[0]->assertionResults[0]->message);
    }

    /**
     * Finding 2 (Re-review): Sequential execution of two specs with identical schema name must not leak cached schemas.
     */
    public function test_sequential_specs_with_same_schema_name_do_not_collide_cache(): void
    {
        $spec1 = [
            'components' => [
                'schemas' => [
                    'User' => [
                        'type' => 'object',
                        'required' => ['username'],
                        'properties' => [
                            'username' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];

        $spec2 = [
            'components' => [
                'schemas' => [
                    'User' => [
                        'type' => 'object',
                        'required' => ['email'],
                        'properties' => [
                            'email' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];

        $responseUsernameOnly = $this->createResponse(body: '{"username": "johndoe"}');
        $responseEmailOnly = $this->createResponse(body: '{"email": "john@example.com"}');

        $assertion = ['type' => 'json_schema', 'schema_ref' => '#/components/schemas/User'];

        // 1. Validate against Spec 1: username succeeds, email fails
        $res1Success = $this->engine->assert($assertion, $responseUsernameOnly, spec: $spec1);
        $this->assertTrue($res1Success->passed);

        $res1Fail = $this->engine->assert($assertion, $responseEmailOnly, spec: $spec1);
        $this->assertFalse($res1Fail->passed);

        // 2. Validate against Spec 2: email succeeds, username fails
        $res2Success = $this->engine->assert($assertion, $responseEmailOnly, spec: $spec2);
        $this->assertTrue($res2Success->passed, 'Spec 2 must validate email without using Spec 1 User schema');

        $res2Fail = $this->engine->assert($assertion, $responseUsernameOnly, spec: $spec2);
        $this->assertFalse($res2Fail->passed, 'Spec 2 must reject username-only payload');

        // 3. Re-validate against Spec 1: username still succeeds
        $res1SuccessAgain = $this->engine->assert($assertion, $responseUsernameOnly, spec: $spec1);
        $this->assertTrue($res1SuccessAgain->passed);
    }

    public function test_schema_ref_cache_isolation_when_specs_share_same_schema_id(): void
    {
        $specA = [
            'openapi' => '3.1.0',
            'components' => [
                'schemas' => [
                    'Item' => [
                        '$id' => 'https://schema.local/shared',
                        'type' => 'string',
                        'const' => 'alpha',
                    ],
                ],
            ],
        ];

        $specB = [
            'openapi' => '3.1.0',
            'components' => [
                'schemas' => [
                    'Item' => [
                        '$id' => 'https://schema.local/shared',
                        'type' => 'string',
                        'const' => 'beta',
                    ],
                ],
            ],
        ];

        $assertion = ['type' => 'json_schema', 'schema_ref' => '#/components/schemas/Item'];

        // Spec A with 'alpha' -> true
        $resA1 = $this->engine->assert($assertion, $this->createResponse(body: (string) json_encode('alpha')), spec: $specA);
        $this->assertTrue($resA1->passed, $resA1->message ?? '');

        // Spec B with 'beta' -> true (must not throw DuplicateSchemaIdException or reuse A)
        $resB1 = $this->engine->assert($assertion, $this->createResponse(body: (string) json_encode('beta')), spec: $specB);
        $this->assertTrue($resB1->passed, $resB1->message ?? '');

        // Spec B with 'alpha' -> false
        $resB2 = $this->engine->assert($assertion, $this->createResponse(body: (string) json_encode('alpha')), spec: $specB);
        $this->assertFalse($resB2->passed);

        // Spec A with 'alpha' again -> true
        $resA2 = $this->engine->assert($assertion, $this->createResponse(body: (string) json_encode('alpha')), spec: $specA);
        $this->assertTrue($resA2->passed);

        // Spec A with 'beta' -> false
        $resA3 = $this->engine->assert($assertion, $this->createResponse(body: (string) json_encode('beta')), spec: $specA);
        $this->assertFalse($resA3->passed);
    }

    public function test_inline_schema_cache_isolation_when_inline_schemas_share_same_schema_id(): void
    {
        $inlineA = [
            'type' => 'json_schema',
            'schema' => [
                '$id' => 'https://schema.local/shared',
                'type' => 'string',
                'const' => 'alpha',
            ],
        ];

        $inlineB = [
            'type' => 'json_schema',
            'schema' => [
                '$id' => 'https://schema.local/shared',
                'type' => 'string',
                'const' => 'beta',
            ],
        ];

        // Inline A with 'alpha' -> true
        $resA1 = $this->engine->assert($inlineA, $this->createResponse(body: (string) json_encode('alpha')));
        $this->assertTrue($resA1->passed);

        // Inline B with 'beta' -> true
        $resB1 = $this->engine->assert($inlineB, $this->createResponse(body: (string) json_encode('beta')));
        $this->assertTrue($resB1->passed);

        // Inline B with 'alpha' -> false
        $resB2 = $this->engine->assert($inlineB, $this->createResponse(body: (string) json_encode('alpha')));
        $this->assertFalse($resB2->passed);

        // Inline A with 'alpha' -> true
        $resA2 = $this->engine->assert($inlineA, $this->createResponse(body: (string) json_encode('alpha')));
        $this->assertTrue($resA2->passed);
    }

    public function test_json_path_preserves_object_and_array_types_and_selectors(): void
    {
        // 1. Root empty object {}
        $resEmptyObj = $this->engine->assert(['type' => 'json_path', 'path' => '$', 'op' => 'type', 'value' => 'object'], $this->createResponse(body: '{}'));
        $this->assertTrue($resEmptyObj->passed, $resEmptyObj->message ?? '');

        $resEmptyObjNotArr = $this->engine->assert(['type' => 'json_path', 'path' => '$', 'op' => 'type', 'value' => 'array'], $this->createResponse(body: '{}'));
        $this->assertFalse($resEmptyObjNotArr->passed);

        // 2. Root empty array []
        $resEmptyArr = $this->engine->assert(['type' => 'json_path', 'path' => '$', 'op' => 'type', 'value' => 'array'], $this->createResponse(body: '[]'));
        $this->assertTrue($resEmptyArr->passed);

        $resEmptyArrNotObj = $this->engine->assert(['type' => 'json_path', 'path' => '$', 'op' => 'type', 'value' => 'object'], $this->createResponse(body: '[]'));
        $this->assertFalse($resEmptyArrNotObj->passed);

        // 3. Equality between empty object and empty array
        $resEqObjToArr = $this->engine->assert(['type' => 'json_path', 'path' => '$', 'op' => 'eq', 'value' => []], $this->createResponse(body: '{}'));
        $this->assertFalse($resEqObjToArr->passed, 'Empty object must not equal empty array');

        $resEqArrToArr = $this->engine->assert(['type' => 'json_path', 'path' => '$', 'op' => 'eq', 'value' => []], $this->createResponse(body: '[]'));
        $this->assertTrue($resEqArrToArr->passed);

        // 4. Object with numeric keys
        $numericObj = $this->createResponse(body: '{"0": "a", "1": "b"}');
        $resNumObjType = $this->engine->assert(['type' => 'json_path', 'path' => '$', 'op' => 'type', 'value' => 'object'], $numericObj);
        $this->assertTrue($resNumObjType->passed);

        $resNumObjNotArr = $this->engine->assert(['type' => 'json_path', 'path' => '$', 'op' => 'type', 'value' => 'array'], $numericObj);
        $this->assertFalse($resNumObjNotArr->passed);

        // 5. Index bracket $[0] must not match object member "0"
        $singleKeyObj = $this->createResponse(body: '{"0": "a"}');
        $resIndexOnObj = $this->engine->assert(['type' => 'json_path', 'path' => '$[0]', 'op' => 'exists'], $singleKeyObj);
        $this->assertFalse($resIndexOnObj->passed, '$[0] array index must not match object member "0"');

        $singleItemArr = $this->createResponse(body: '["a"]');
        $resIndexOnArr = $this->engine->assert(['type' => 'json_path', 'path' => '$[0]', 'op' => 'exists'], $singleItemArr);
        $this->assertTrue($resIndexOnArr->passed);

        // 6. Nested empty object
        $nestedObj = $this->createResponse(body: '{"child": {}}');
        $resNestedObjType = $this->engine->assert(['type' => 'json_path', 'path' => '$.child', 'op' => 'type', 'value' => 'object'], $nestedObj);
        $this->assertTrue($resNestedObjType->passed);

        $resNestedObjNotArr = $this->engine->assert(['type' => 'json_path', 'path' => '$.child', 'op' => 'type', 'value' => 'array'], $nestedObj);
        $this->assertFalse($resNestedObjNotArr->passed);

        // 7. Property selector with numeric name must not access array index
        $arrayBody = $this->createResponse(body: '["a"]');
        $this->assertFalse($this->engine->assert(['type' => 'json_path', 'path' => "$['0']", 'op' => 'exists'], $arrayBody)->passed);
        $this->assertTrue($this->engine->assert(['type' => 'json_path', 'path' => "$['0']", 'op' => 'not_exists'], $arrayBody)->passed);
        $this->assertFalse($this->engine->assert(['type' => 'json_path', 'path' => '$.0', 'op' => 'exists'], $arrayBody)->passed);

        $nestedArrayBody = $this->createResponse(body: '{"items":["a"]}');
        $this->assertFalse($this->engine->assert(['type' => 'json_path', 'path' => "$.items['0']", 'op' => 'exists'], $nestedArrayBody)->passed);

        $objectBody = $this->createResponse(body: '{"0":"a"}');
        $this->assertTrue($this->engine->assert(['type' => 'json_path', 'path' => "$['0']", 'op' => 'exists'], $objectBody)->passed);
        $this->assertFalse($this->engine->assert(['type' => 'json_path', 'path' => '$[0]', 'op' => 'exists'], $objectBody)->passed);
        $this->assertTrue($this->engine->assert(['type' => 'json_path', 'path' => '$[0]', 'op' => 'exists'], $arrayBody)->passed);
    }

    public function test_json_dsl_inline_schema_preserves_original_semantics(): void
    {
        $cases = [
            'empty-schema-object' => ['{}', '{}', true],
            'empty-schema-array-body' => ['{}', '[]', true],
            'const-object-object-body' => ['{"const":{}}', '{}', true],
            'const-object-array-body' => ['{"const":{}}', '[]', false],
            'enum-object-object-body' => ['{"enum":[{}]}', '{}', true],
            'enum-object-array-body' => ['{"enum":[{}]}', '[]', false],
            'numeric-object-const-object' => ['{"const":{"0":"a","1":"b"}}', '{"0":"a","1":"b"}', true],
            'numeric-object-const-array' => ['{"const":{"0":"a","1":"b"}}', '["a","b"]', false],
            'unconstrained-property-schema' => ['{"type":"object","properties":{"meta":{}},"required":["meta"]}', '{"meta":42}', true],
            'additional-properties-empty-schema' => ['{"type":"object","additionalProperties":{}}', '{"a":42}', true],
            'control-array-const-array' => ['{"const":[]}', '[]', true],
            'control-array-const-object' => ['{"const":[]}', '{}', false],
            'control-normal-schema' => ['{"type":"object","properties":{"id":{"type":"integer"}}}', '{"id":1}', true],
        ];

        foreach ($cases as $name => [$schemaJson, $body, $expected]) {
            $schema = json_decode($schemaJson);
            $dsl = json_encode([
                'dsl_version' => '1.0',
                'title' => 'Schema shape regression',
                'source' => 'MANUAL',
                'technique' => 'HAPPY_PATH',
                'endpoint' => ['method' => 'GET', 'path' => '/items'],
                'steps' => [[
                    'name' => 'Check',
                    'request' => ['method' => 'GET', 'path' => '/items'],
                    'assertions' => [['type' => 'json_schema', 'schema' => $schema]],
                ]],
            ], JSON_THROW_ON_ERROR);

            $executor = new TestExecutor(
                (new FakeTargetHttpClient)->respondWith(200, rawBody: trim($body)),
                new TargetAllowlistValidator(['127.0.0.1'])
            );
            $result = $executor->execute($dsl, 'http://127.0.0.1');
            $this->assertSame($expected, $result->isSuccess, "Case {$name} failed: ".json_encode($result));
        }
    }

    public function test_schema_ref_preserves_object_shapes_in_components_schemas(): void
    {
        $spec = (object) [
            'openapi' => '3.0.0',
            'components' => (object) [
                'schemas' => (object) [
                    'EmptySchema' => (object) [],
                    'ConstObj' => (object) ['const' => (object) []],
                    'ConstNumeric' => (object) ['const' => (object) ['0' => 'a']],
                    'NestedEmpty' => (object) [
                        'type' => 'object',
                        'properties' => (object) ['meta' => (object) []],
                        'required' => ['meta'],
                    ],
                ],
            ],
        ];

        // 1. EmptySchema accepts {} and []
        $resEmptyObj = $this->engine->assert(['type' => 'json_schema', 'schema_ref' => '#/components/schemas/EmptySchema'], $this->createResponse(body: '{}'), spec: $spec);
        $this->assertTrue($resEmptyObj->passed);
        $resEmptyArr = $this->engine->assert(['type' => 'json_schema', 'schema_ref' => '#/components/schemas/EmptySchema'], $this->createResponse(body: '[]'), spec: $spec);
        $this->assertTrue($resEmptyArr->passed);

        // 2. ConstObj accepts {} and rejects []
        $resConstObj = $this->engine->assert(['type' => 'json_schema', 'schema_ref' => '#/components/schemas/ConstObj'], $this->createResponse(body: '{}'), spec: $spec);
        $this->assertTrue($resConstObj->passed);
        $resConstArr = $this->engine->assert(['type' => 'json_schema', 'schema_ref' => '#/components/schemas/ConstObj'], $this->createResponse(body: '[]'), spec: $spec);
        $this->assertFalse($resConstArr->passed);

        // 3. ConstNumeric accepts {"0":"a"} and rejects ["a"]
        $resNumObj = $this->engine->assert(['type' => 'json_schema', 'schema_ref' => '#/components/schemas/ConstNumeric'], $this->createResponse(body: '{"0":"a"}'), spec: $spec);
        $this->assertTrue($resNumObj->passed);
        $resNumArr = $this->engine->assert(['type' => 'json_schema', 'schema_ref' => '#/components/schemas/ConstNumeric'], $this->createResponse(body: '["a"]'), spec: $spec);
        $this->assertFalse($resNumArr->passed);

        // 4. NestedEmpty accepts {"meta": 42}
        $resNested = $this->engine->assert(['type' => 'json_schema', 'schema_ref' => '#/components/schemas/NestedEmpty'], $this->createResponse(body: '{"meta":42}'), spec: $spec);
        $this->assertTrue($resNested->passed);
    }

    public function test_hydrated_api_spec_and_parsed_openapi_schema_ref_preserves_json_shapes(): void
    {
        $cases = [
            'empty-schema-object' => ['{}', '{}', true],
            'empty-schema-array-body' => ['{}', '[]', true],
            'const-object-object-body' => ['{"const":{}}', '{}', true],
            'const-object-array-body' => ['{"const":{}}', '[]', false],
            'enum-object-object-body' => ['{"enum":[{}]}', '{}', true],
            'enum-object-array-body' => ['{"enum":[{}]}', '[]', false],
            'numeric-object-const-object' => ['{"const":{"0":"a","1":"b"}}', '{"0":"a","1":"b"}', true],
            'numeric-object-const-array' => ['{"const":{"0":"a","1":"b"}}', '["a","b"]', false],
            'unconstrained-property-schema' => ['{"type":"object","properties":{"meta":{}},"required":["meta"]}', '{"meta":42}', true],
            'additional-properties-empty-schema' => ['{"type":"object","additionalProperties":{}}', '{"a":42}', true],
            'control-array-const-array' => ['{"const":[]}', '[]', true],
            'control-array-const-object' => ['{"const":[]}', '{}', false],
            'control-normal-schema' => ['{"type":"object","properties":{"id":{"type":"integer"}}}', '{"id":1}', true],
        ];

        foreach ($cases as $name => [$schemaJson, $body, $expected]) {
            $raw = '{"openapi":"3.1.0","info":{"title":"Review","version":"1"},"paths":{"/items":{"get":{"responses":{"200":{"description":"OK"}}}}},"components":{"schemas":{"Shape":'.$schemaJson.'}}}';

            $model = new ApiSpec;
            $model->setRawAttributes(['normalized' => $raw, 'raw_content' => $raw]);

            $dsl = json_encode([
                'dsl_version' => '1.0',
                'title' => 'Hydrated ApiSpec shape regression',
                'source' => 'MANUAL',
                'technique' => 'HAPPY_PATH',
                'endpoint' => ['method' => 'GET', 'path' => '/items'],
                'steps' => [[
                    'name' => 'Check',
                    'request' => ['method' => 'GET', 'path' => '/items'],
                    'assertions' => [['type' => 'json_schema', 'schema_ref' => '#/components/schemas/Shape']],
                ]],
            ], JSON_THROW_ON_ERROR);

            $createExecutor = fn () => new TestExecutor(
                (new FakeTargetHttpClient)->respondWith(200, rawBody: trim($body)),
                new TargetAllowlistValidator(['127.0.0.1'])
            );
            $execResult = $createExecutor()->execute($dsl, 'http://127.0.0.1', spec: $model);
            $this->assertSame($expected, $execResult->isSuccess, "Model executor case {$name} failed: ".json_encode($execResult));

            $engineResult = $this->engine->assert(
                ['type' => 'json_schema', 'schema_ref' => '#/components/schemas/Shape'],
                $this->createResponse(body: trim($body)),
                spec: $model
            );
            $this->assertSame($expected, $engineResult->passed, "Model engine case {$name} failed: ".json_encode($engineResult));

            // Also test real OpenApiParser output directly
            $parsedReal = (new OpenApiParser)->parse($raw);
            $this->assertTrue($parsedReal->isValid, "OpenApiParser failed on {$name}: ".implode('; ', $parsedReal->errors));

            $parsedExec = $createExecutor()->execute($dsl, 'http://127.0.0.1', spec: $parsedReal);
            $this->assertSame($expected, $parsedExec->isSuccess, "ParsedOpenApi executor case {$name} failed: ".json_encode($parsedExec));

            $parsedResult = $this->engine->assert(
                ['type' => 'json_schema', 'schema_ref' => '#/components/schemas/Shape'],
                $this->createResponse(body: trim($body)),
                spec: $parsedReal
            );
            $this->assertSame($expected, $parsedResult->passed, "ParsedOpenApi engine case {$name} failed: ".json_encode($parsedResult));

            // Also test parser-model: ApiSpec hydrated from json_encoded parsed->normalized
            $parserModel = (new ApiSpec)->setRawAttributes([
                'normalized' => json_encode($parsedReal->normalized, JSON_THROW_ON_ERROR),
                'raw_content' => $raw,
            ]);
            $parserModelExec = $createExecutor()->execute($dsl, 'http://127.0.0.1', spec: $parserModel);
            $this->assertSame($expected, $parserModelExec->isSuccess, "ParserModel executor case {$name} failed: ".json_encode($parserModelExec));

            $parserModelResult = $this->engine->assert(
                ['type' => 'json_schema', 'schema_ref' => '#/components/schemas/Shape'],
                $this->createResponse(body: trim($body)),
                spec: $parserModel
            );
            $this->assertSame($expected, $parserModelResult->passed, "ParserModel engine case {$name} failed: ".json_encode($parserModelResult));
        }
    }

    public function test_schema_ref_with_associative_array_spec_treats_empty_arrays_as_object_schemas(): void
    {
        // OpenApiParser normalized output decodes `{}` schemas as empty PHP arrays.
        $spec = [
            'openapi' => '3.0.3',
            'components' => [
                'schemas' => [
                    'Any' => [],
                    'NullableAny' => ['nullable' => true],
                    'Item' => [
                        'type' => 'object',
                        'properties' => ['meta' => [], 'id' => ['type' => 'integer']],
                        'required' => ['meta', 'id'],
                        'additionalProperties' => [],
                    ],
                ],
            ],
        ];

        $any = $this->engine->assert(['type' => 'json_schema', 'schema_ref' => '#/components/schemas/Any'], $this->createResponse(body: '[1,2]'), spec: $spec);
        $this->assertTrue($any->passed, (string) $any->message);

        $nullable = $this->engine->assert(['type' => 'json_schema', 'schema_ref' => '#/components/schemas/NullableAny'], $this->createResponse(body: 'null'), spec: $spec);
        $this->assertTrue($nullable->passed, (string) $nullable->message);

        $pass = $this->engine->assert(['type' => 'json_schema', 'schema_ref' => '#/components/schemas/Item'], $this->createResponse(body: '{"meta":"x","id":1,"extra":true}'), spec: $spec);
        $this->assertTrue($pass->passed, (string) $pass->message);

        $fail = $this->engine->assert(['type' => 'json_schema', 'schema_ref' => '#/components/schemas/Item'], $this->createResponse(body: '{"meta":"x","id":"1"}'), spec: $spec);
        $this->assertFalse($fail->passed);
    }

    /**
     * Review S4-02: DSL `matches` uses bare (ECMA/Python-style) patterns, not PHP-delimited regex.
     */
    public function test_header_matches_uses_bare_patterns(): void
    {
        $response = $this->createResponse(headers: [
            'x-contact' => ['support@example.com'],
            'x-request-count' => ['12345'],
        ]);

        $this->assertTrue($this->engine->assert(['type' => 'header', 'name' => 'x-contact', 'op' => 'matches', 'value' => '^support@'], $response)->passed);
        $this->assertFalse($this->engine->assert(['type' => 'header', 'name' => 'x-contact', 'op' => 'matches', 'value' => '^admin@'], $response)->passed);
        $this->assertTrue($this->engine->assert(['type' => 'header', 'name' => 'x-request-count', 'op' => 'matches', 'value' => '^[0-9]+$'], $response)->passed);
        $this->assertFalse($this->engine->assert(['type' => 'header', 'name' => 'x-contact', 'op' => 'matches', 'value' => '^[0-9]+$'], $response)->passed);
    }

    public function test_json_path_matches_uses_bare_patterns(): void
    {
        $response = $this->createResponse(body: json_encode([
            'email' => 'support@example.com',
            'code' => '12345',
            'path' => '/api/v1~x',
            'name' => 'Đà Nẵng',
        ], JSON_THROW_ON_ERROR));

        $this->assertTrue($this->engine->assert(['type' => 'json_path', 'path' => '$.email', 'op' => 'matches', 'value' => '^support@'], $response)->passed);
        $this->assertFalse($this->engine->assert(['type' => 'json_path', 'path' => '$.email', 'op' => 'matches', 'value' => '^admin@'], $response)->passed);
        $this->assertTrue($this->engine->assert(['type' => 'json_path', 'path' => '$.code', 'op' => 'matches', 'value' => '^[0-9]+$'], $response)->passed);
        $this->assertFalse($this->engine->assert(['type' => 'json_path', 'path' => '$.email', 'op' => 'matches', 'value' => '^[0-9]+$'], $response)->passed);
        $this->assertTrue($this->engine->assert(['type' => 'json_path', 'path' => '$.email', 'op' => 'matches', 'value' => '[a-z]+'], $response)->passed);
        // Slashes and the internal delimiter character are literal.
        $this->assertTrue($this->engine->assert(['type' => 'json_path', 'path' => '$.path', 'op' => 'matches', 'value' => '^/api/v1~x$'], $response)->passed);
        // A PHP-style delimited pattern is not special: "/^support@/" matches the literal slashes.
        $this->assertFalse($this->engine->assert(['type' => 'json_path', 'path' => '$.email', 'op' => 'matches', 'value' => '/^support@/'], $response)->passed);
        // Unicode-aware.
        $this->assertTrue($this->engine->assert(['type' => 'json_path', 'path' => '$.name', 'op' => 'matches', 'value' => '^.{7}$'], $response)->passed);
    }

    /**
     * Review S4-02: a large target body must not leak into assertion_results beyond the 64 KB cap.
     */
    public function test_large_body_does_not_bloat_serialized_assertion_results(): void
    {
        $items = [];
        for ($i = 0; $i < 5000; $i++) {
            $items[] = ['id' => $i, 'name' => str_repeat('x', 40)];
        }
        $body = json_encode(['items' => $items], JSON_THROW_ON_ERROR);
        $this->assertGreaterThan(StepExecutionResult::MAX_RESPONSE_LOG_BYTES, strlen($body));
        $response = $this->createResponse(body: $body);

        $results = [
            $this->engine->assert(['type' => 'json_schema', 'schema' => ['type' => 'object']], $response),
            $this->engine->assert(['type' => 'json_schema', 'schema' => ['type' => 'array']], $response),
            $this->engine->assert(['type' => 'json_path', 'path' => '$', 'op' => 'contains', 'value' => 'missing'], $response),
            $this->engine->assert(['type' => 'json_path', 'path' => '$.items', 'op' => 'eq', 'value' => []], $response),
        ];
        $this->assertTrue($results[0]->passed);
        $this->assertFalse($results[1]->passed);
        $this->assertNull($results[0]->actual);
        $this->assertNull($results[1]->actual);

        $step = new StepExecutionResult(
            stepIndex: 0,
            stepName: 'large',
            request: new TargetRequest('GET', 'http://localhost/items'),
            response: $response,
            assertionResults: $results,
        );
        $serialized = json_encode($step->toArray()['assertion_results'], JSON_THROW_ON_ERROR);
        $this->assertLessThanOrEqual(StepExecutionResult::MAX_RESPONSE_LOG_BYTES, strlen($serialized));

        foreach ($results as $result) {
            $row = json_encode($result->toArray(), JSON_THROW_ON_ERROR);
            $this->assertLessThan(4 * AssertionResult::MAX_VALUE_LOG_BYTES, strlen($row));
        }
    }
}
