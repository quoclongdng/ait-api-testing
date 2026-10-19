<?php

namespace Tests\Unit\Services;

use App\Contracts\TargetHttpClient;
use App\Data\Execution\StepExecutionResult;
use App\Data\TargetRequest;
use App\Data\TargetResponse;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Services\Dsl\DslValidator;
use App\Services\Execution\TargetAllowlistValidator;
use App\Services\Execution\TestExecutor;
use App\Services\Target\FakeTargetHostResolver;
use App\Services\Target\FakeTargetHttpClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TestExecutorTest extends TestCase
{
    public function test_path_parameters_with_punctuation_are_validated_and_encoded(): void
    {
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Path parameter punctuation',
            'endpoint' => ['method' => 'GET', 'path' => '/items/{item-id}/{item.code}'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [[
                'name' => 'Get item',
                'request' => [
                    'method' => 'GET',
                    'path' => '/items/{item-id}/{item.code}',
                    'path_params' => ['item-id' => 42, 'item.code' => 'a/b c'],
                ],
                'assertions' => [['type' => 'status', 'equals' => 200]],
            ]],
        ];
        $validation = (new DslValidator)->validate($dsl, [
            'endpoints' => [
                ['method' => 'GET', 'path' => '/items/{item-id}/{item.code}', 'responses' => ['200' => []]],
            ],
        ]);
        $this->assertTrue($validation->isValid, implode('; ', $validation->errors));
        $this->fakeClient->respondWith(200, ['ok' => true]);
        $result = $this->executor->execute($dsl, 'http://benchmark');
        $this->assertTrue($result->isSuccess);
        $this->assertSame('http://benchmark/items/42/a%2Fb%20c', $this->fakeClient->sent()[0]->url);
        $this->assertSame('/items/{{missing}}', $this->executor->buildResolvedPath('/items/{{missing}}', [], []));
    }

    public function test_ipv6_allowlist_entries_respect_host_and_port(): void
    {
        foreach (['::1', '[::1]', '[::1]:8000'] as $entry) {
            $validator = new TargetAllowlistValidator([$entry]);
            $this->assertTrue($validator->isAllowed('http://[::1]:8000/items'), $entry);
            $this->assertFalse($validator->isAllowed('http://[::2]:8000/items'), $entry);
        }
        $validator = new TargetAllowlistValidator(['[::1]:8000']);
        $this->assertFalse($validator->isAllowed('http://[::1]:8001/items'));
        $this->assertFalse($validator->isAllowed('http://[::1]/items'));
        $this->assertTrue((new TargetAllowlistValidator(['[::1]:443']))->isAllowed('https://[::1]/items'));
        foreach (['[::1]:0', '[::1]:65536', '[::1]:invalid', 'localhost:8000junk'] as $entry) {
            $this->assertFalse((new TargetAllowlistValidator([$entry]))->isAllowed('http://[::1]:8000/items'));
            $this->assertFalse((new TargetAllowlistValidator([$entry]))->isAllowed('http://localhost:8000/items'));
        }
    }

    public function test_json_path_decodes_escaped_property_names(): void
    {
        $data = (object) ['a"b' => 42, "it's" => 43, 'a\\b' => 44, 'id' => 45, '*' => 46];
        $this->assertSame(42, $this->executor->extractJsonPath($data, '$["a\\"b"]'));
        $this->assertSame(43, $this->executor->extractJsonPath($data, "$['it\\'s']"));
        $this->assertSame(44, $this->executor->extractJsonPath($data, '$["a\\\\b"]'));
        $this->assertSame(45, $this->executor->extractJsonPath($data, '$["\\u0069d"]'));
        $this->assertSame(46, $this->executor->extractJsonPath($data, '$["*"]'));
        $this->assertSame('second', $this->executor->extractJsonPath(['first', 'second'], '$[1]'));
        $this->expectException(RuntimeException::class);
        $this->executor->extractJsonPath(['first', 'second'], '$["01"]');
    }

    public function test_unsupported_json_path_fails_execution_before_next_request(): void
    {
        foreach (['$..id', '$.items[*].id', '$.items[0:2]', '$.items[?(@.id)]', '$.items[0,1]', '$.id.', '$["bad\\q"]'] as $path) {
            $client = new FakeTargetHttpClient;
            $client->respondWith(200, ['id' => 1, 'items' => [['id' => 2]]]);
            $executor = new TestExecutor($client, $this->allowlistValidator);
            $result = $executor->execute([
                'steps' => [
                    [
                        'request' => ['method' => 'GET', 'path' => '/items'],
                        'extract' => [['var' => 'id', 'from' => 'body', 'path' => $path]],
                    ],
                    ['request' => ['method' => 'GET', 'path' => '/items/{{id}}']],
                ],
            ], 'http://benchmark');
            $this->assertFalse($result->isSuccess, $path);
            $this->assertStringContainsString('JSONPath', (string) $result->errorMessage);
            $this->assertArrayNotHasKey('id', $result->finalVariables);
            $this->assertCount(1, $client->sent());
        }
    }

    private FakeTargetHttpClient $fakeClient;

    private TargetAllowlistValidator $allowlistValidator;

    private TestExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeClient = new FakeTargetHttpClient;
        $this->allowlistValidator = new TargetAllowlistValidator(
            ['localhost:8000', '127.0.0.1', 'benchmark'],
            new FakeTargetHostResolver(['localhost' => ['127.0.0.1'], 'benchmark' => ['127.0.0.1']]),
        );
        $this->executor = new TestExecutor($this->fakeClient, $this->allowlistValidator);
    }

    public function test_target_outside_allowlist_throws_target_not_allowed_exception(): void
    {
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'SSRF test',
            'endpoint' => ['method' => 'GET', 'path' => '/data'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'Evil request',
                    'request' => ['method' => 'GET', 'path' => '/data'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        try {
            $this->executor->execute($dsl, 'http://malicious-external-site.com');
            $this->fail('Expected ApiException was not thrown for disallowed host.');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::TargetNotAllowed, $e->errorCode);
            $this->assertSame(422, $e->errorCode->httpStatus());
        }
    }

    public function test_target_with_wrong_port_throws_target_not_allowed(): void
    {
        // localhost:8000 is allowed, but localhost:9999 is not
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Port test',
            'endpoint' => ['method' => 'GET', 'path' => '/test'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'Wrong port',
                    'request' => ['method' => 'GET', 'path' => '/test'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $this->expectException(ApiException::class);
        $this->executor->execute($dsl, 'http://localhost:9999');
    }

    public function test_single_step_execution_with_fake_target_http_client(): void
    {
        $this->fakeClient->respondWith(200, ['status' => 'ok']);

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Health check',
            'endpoint' => ['method' => 'GET', 'path' => '/health'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'Call health',
                    'request' => [
                        'method' => 'GET',
                        'path' => '/health',
                        'headers' => ['X-Client' => 'AIT-Test'],
                        'query' => ['verbose' => 'true'],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $result = $this->executor->execute($dsl, 'http://localhost:8000');

        $this->assertTrue($result->isSuccess);
        $this->assertCount(1, $result->stepResults);
        $this->assertNull($result->errorMessage);

        $sent = $this->fakeClient->sent();
        $this->assertCount(1, $sent);
        $this->assertSame('GET', $sent[0]->method);
        $this->assertSame('http://localhost:8000/health', $sent[0]->url);
        $this->assertSame(['verbose' => 'true'], $sent[0]->query);
        $this->assertSame('AIT-Test', $sent[0]->headers['X-Client']);

        $step = $result->stepResults[0];
        $this->assertNotNull($step->response);
        $this->assertSame(200, $step->response->status);
        $this->assertSame(['status' => 'ok'], $step->response->json());
    }

    public function test_variable_interpolation_in_path_query_and_body(): void
    {
        $this->fakeClient->respondWith(201, ['id' => 101, 'name' => 'Gadget']);

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Create item with variables',
            'endpoint' => ['method' => 'POST', 'path' => '/categories/{cat_id}/products'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'variables' => [
                'cat_id' => 42,
                'item_name' => 'Smart Watch',
                'price_val' => 199.99,
                'in_stock' => true,
            ],
            'steps' => [
                [
                    'name' => 'Post product',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/categories/{cat_id}/products',
                        'path_params' => [
                            'cat_id' => '{{cat_id}}',
                        ],
                        'query' => [
                            'tag' => 'tech-{{cat_id}}',
                        ],
                        'headers' => [
                            'X-Category' => 'cat-{{cat_id}}',
                        ],
                        'body' => [
                            'name' => '{{item_name}}',
                            'price' => '{{price_val}}',
                            'available' => '{{in_stock}}',
                        ],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 201]],
                ],
            ],
        ];

        $result = $this->executor->execute($dsl, 'http://benchmark');

        $this->assertTrue($result->isSuccess);
        $sent = $this->fakeClient->sent();
        $this->assertCount(1, $sent);

        // Path parameter was interpolated to /categories/42/products
        $this->assertSame('http://benchmark/categories/42/products', $sent[0]->url);
        $this->assertSame('tech-42', $sent[0]->query['tag']);
        $this->assertSame('cat-42', $sent[0]->headers['X-Category']);

        // Types were preserved for exact variable matches
        $this->assertSame('Smart Watch', $sent[0]->body['name']);
        $this->assertSame(199.99, $sent[0]->body['price']);
        $this->assertTrue($sent[0]->body['available']);
    }

    public function test_multi_step_sequence_with_variable_extraction(): void
    {
        // Step 1 response: creates category
        $this->fakeClient->respondWith(201, [
            'id' => 99,
            'slug' => 'electronics',
        ]);

        // Step 2 response: retrieves category
        $this->fakeClient->respondWith(200, [
            'id' => 99,
            'name' => 'Electronics Department',
        ]);

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Multi-step create and get',
            'endpoint' => ['method' => 'POST', 'path' => '/categories'],
            'technique' => 'SEQUENCE',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'Step 1: Create category',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/categories',
                        'body' => ['slug' => 'electronics'],
                    ],
                    'extract' => [
                        ['var' => 'created_id', 'from' => 'body', 'path' => '$.id'],
                        ['var' => 'http_code', 'from' => 'status'],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 201]],
                ],
                [
                    'name' => 'Step 2: Get category by extracted ID',
                    'request' => [
                        'method' => 'GET',
                        'path' => '/categories/{id}',
                        'path_params' => [
                            'id' => '{{created_id}}',
                        ],
                    ],
                    'extract' => [
                        ['var' => 'cat_name', 'from' => 'body', 'path' => 'name'],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $result = $this->executor->execute($dsl, 'http://127.0.0.1');

        $this->assertTrue($result->isSuccess);
        $this->assertCount(2, $result->stepResults);

        // Verify Step 1
        $this->assertSame(99, $result->stepResults[0]->extractedVariables['created_id']);
        $this->assertSame(201, $result->stepResults[0]->extractedVariables['http_code']);

        // Verify Step 2 called the URL with extracted ID 99
        $sent = $this->fakeClient->sent();
        $this->assertCount(2, $sent);
        $this->assertSame('http://127.0.0.1/categories/99', $sent[1]->url);

        // Verify final variables
        $this->assertSame(99, $result->finalVariables['created_id']);
        $this->assertSame('Electronics Department', $result->finalVariables['cat_name']);
    }

    public function test_header_extraction_case_insensitive(): void
    {
        $customClient = new class implements TargetHttpClient
        {
            public function send(TargetRequest $request): TargetResponse
            {
                return new TargetResponse(
                    status: 200,
                    headers: ['X-Session-Token' => ['token_abc_123']],
                    body: '{"ok":true}',
                    durationMs: 5
                );
            }
        };

        $executor = new TestExecutor($customClient, $this->allowlistValidator);

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Header extract test',
            'endpoint' => ['method' => 'POST', 'path' => '/login'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'Login',
                    'request' => ['method' => 'POST', 'path' => '/login'],
                    'extract' => [
                        ['var' => 'auth_token', 'from' => 'header', 'path' => 'x-session-token'],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $result = $executor->execute($dsl, 'http://localhost:8000');

        $this->assertTrue($result->isSuccess);
        $this->assertSame('token_abc_123', $result->finalVariables['auth_token']);
    }

    public function test_network_or_timeout_failure_short_circuits_and_records_error(): void
    {
        $failingClient = new class implements TargetHttpClient
        {
            public function send(TargetRequest $request): TargetResponse
            {
                throw new RuntimeException('Connection timed out after 10000ms');
            }
        };

        $executor = new TestExecutor($failingClient, $this->allowlistValidator);

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Timeout test',
            'endpoint' => ['method' => 'GET', 'path' => '/slow'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'Step 1: Slow request',
                    'request' => ['method' => 'GET', 'path' => '/slow'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
                [
                    'name' => 'Step 2: Should not run',
                    'request' => ['method' => 'GET', 'path' => '/never'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $result = $executor->execute($dsl, 'http://localhost:8000');

        $this->assertFalse($result->isSuccess);
        $this->assertCount(1, $result->stepResults);
        $this->assertFalse($result->stepResults[0]->isSuccess);
        $this->assertStringContainsString('Connection timed out', (string) $result->stepResults[0]->errorMessage);
        $this->assertStringContainsString('thất bại', (string) $result->errorMessage);
    }

    public function test_execution_against_contract_sequence_fixture(): void
    {
        $fixturePath = dirname(__DIR__, 4).'/docs/contracts/examples/valid-sequence-multi-step.json';
        $dslContent = file_get_contents($fixturePath);
        $this->assertIsString($dslContent);

        // Queue responses for all 4 steps of valid-sequence-multi-step.json:
        // Step 1: POST /orders -> 201 {"id": 10, "status": "DRAFT"}
        $this->fakeClient->respondWith(201, ['id' => 10, 'status' => 'DRAFT']);
        // Step 2: PATCH /orders/10/pay -> 200 {"id": 10, "status": "PAID"}
        $this->fakeClient->respondWith(200, ['id' => 10, 'status' => 'PAID']);
        // Step 3: GET /orders/10 -> 200 {"id": 10, "status": "PAID"}
        $this->fakeClient->respondWith(200, ['id' => 10, 'status' => 'PAID']);
        // Step 4: DELETE /orders/10 -> 204
        $this->fakeClient->respondWith(204, null);

        $result = $this->executor->execute($dslContent, 'http://benchmark');

        $this->assertTrue($result->isSuccess);
        $this->assertCount(4, $result->stepResults);

        // Step 1 extracted orderId = 10, orderStatus = DRAFT
        $this->assertSame(10, $result->finalVariables['orderId']);
        $this->assertSame('DRAFT', $result->finalVariables['orderStatus']);

        // Steps 2, 3, 4 called URLs with orderId = 10
        $sent = $this->fakeClient->sent();
        $this->assertCount(4, $sent);
        $this->assertSame('http://benchmark/orders', $sent[0]->url);
        $this->assertSame('http://benchmark/orders/10/pay', $sent[1]->url);
        $this->assertSame('http://benchmark/orders/10', $sent[2]->url);
        $this->assertSame('http://benchmark/orders/10', $sent[3]->url);
    }

    public function test_json_logging_helpers(): void
    {
        $this->fakeClient->respondWith(200, ['ok' => true]);

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Log test',
            'endpoint' => ['method' => 'GET', 'path' => '/log'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'Get log',
                    'request' => ['method' => 'GET', 'path' => '/log'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $result = $this->executor->execute($dsl, 'http://localhost:8000');

        $reqLogs = $result->requestLogs();
        $resLogs = $result->responseLogs();

        $this->assertCount(1, $reqLogs);
        $this->assertSame('GET', $reqLogs[0]['method']);
        $this->assertSame('http://localhost:8000/log', $reqLogs[0]['url']);

        $this->assertCount(1, $resLogs);
        $this->assertNotNull($resLogs[0]);
        $this->assertSame(200, $resLogs[0]['status']);
    }

    public function test_regression_authorization_header_is_masked_in_request_and_step_logs(): void
    {
        $this->fakeClient->respondWith(200, ['status' => 'authenticated']);

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Auth header log test',
            'endpoint' => ['method' => 'GET', 'path' => '/secure'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'Call secure endpoint',
                    'request' => [
                        'method' => 'GET',
                        'path' => '/secure',
                        'headers' => [
                            'Authorization' => 'Bearer secret-token-value-12345',
                            'X-Custom' => 'public-header',
                        ],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $result = $this->executor->execute($dsl, 'http://localhost:8000');
        $this->assertTrue($result->isSuccess);

        // 1. Check requestLogs() masks Authorization
        $reqLogs = $result->requestLogs();
        $this->assertCount(1, $reqLogs);
        $this->assertSame('***', $reqLogs[0]['headers']['Authorization']);
        $this->assertSame('public-header', $reqLogs[0]['headers']['X-Custom']);

        // 2. Check StepExecutionResult::toArray() masks Authorization
        $stepArray = $result->stepResults[0]->toArray();
        $this->assertSame('***', $stepArray['request']['headers']['Authorization']);
        $this->assertSame('public-header', $stepArray['request']['headers']['X-Custom']);

        // 3. Ensure actual sent request retained original unmasked token for HTTP transport
        $sent = $this->fakeClient->sent();
        $this->assertSame('Bearer secret-token-value-12345', $sent[0]->headers['Authorization']);
    }

    public function test_regression_response_body_log_is_truncated_at_64_kb(): void
    {
        // 70,011 bytes response body (greater than 64 KB = 65,536 bytes)
        $largeBody = str_repeat('A', 70011);
        $this->fakeClient->respondWith(200, null);
        // Overwrite last queued response with large raw body
        $largeResponseClient = new class($largeBody) implements TargetHttpClient
        {
            public function __construct(private string $body) {}

            public function send(TargetRequest $request): TargetResponse
            {
                return new TargetResponse(
                    status: 200,
                    headers: ['Content-Type' => ['text/plain']],
                    body: $this->body,
                    durationMs: 10
                );
            }
        };

        $executor = new TestExecutor($largeResponseClient, $this->allowlistValidator);

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Large response log test',
            'endpoint' => ['method' => 'GET', 'path' => '/large'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'Get large content',
                    'request' => ['method' => 'GET', 'path' => '/large'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $result = $executor->execute($dsl, 'http://localhost:8000');
        $this->assertTrue($result->isSuccess);

        // Raw response preserved for assertions
        $this->assertNotNull($result->stepResults[0]->response);
        $this->assertSame(70011, strlen($result->stepResults[0]->response->body));

        // responseLogs() body truncated to exactly 65,536 bytes (64 KB)
        $resLogs = $result->responseLogs();
        $this->assertCount(1, $resLogs);
        $this->assertNotNull($resLogs[0]);
        $this->assertSame(65536, strlen((string) $resLogs[0]['body']));

        // StepExecutionResult::toArray() response body also truncated to 65,536 bytes
        $stepArray = $result->stepResults[0]->toArray();
        $this->assertNotNull($stepArray['response']);
        $this->assertSame(65536, strlen((string) $stepArray['response']['body']));
    }

    public function test_regression_path_parameter_is_rawurlencoded_to_prevent_injection(): void
    {
        $this->fakeClient->respondWith(200, ['status' => 'ok']);

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Path param injection test',
            'endpoint' => ['method' => 'GET', 'path' => '/items/{item_id}'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'Request item with complex parameter',
                    'request' => [
                        'method' => 'GET',
                        'path' => '/items/{item_id}',
                        'path_params' => [
                            'item_id' => 'a/b?x=1#frag',
                        ],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $result = $this->executor->execute($dsl, 'http://localhost:8000');
        $this->assertTrue($result->isSuccess);

        $sent = $this->fakeClient->sent();
        $this->assertCount(1, $sent);
        // The path parameter must be properly encoded without creating new path segment, query, or fragment
        $this->assertSame('http://localhost:8000/items/a%2Fb%3Fx%3D1%23frag', $sent[0]->url);
    }

    public function test_regression_json_path_bracket_notation_and_quoted_syntax(): void
    {
        $data = [
            'id' => 123,
            'user' => [
                'name' => 'Alice',
                'profile' => [
                    'email' => 'alice@example.com',
                ],
            ],
            'items' => [
                ['id' => 10, 'title' => 'Widget'],
                ['id' => 20, 'title' => 'Gadget'],
            ],
        ];

        // Standard dot notation
        $this->assertSame(123, $this->executor->extractJsonPath($data, '$.id'));
        $this->assertSame('Alice', $this->executor->extractJsonPath($data, '$.user.name'));

        // Bracket notation with quotes: $['id'] and $["id"]
        $this->assertSame(123, $this->executor->extractJsonPath($data, "$['id']"));
        $this->assertSame(123, $this->executor->extractJsonPath($data, '$["id"]'));

        // Single-quote bracket without square brackets: $'id' and $"id"
        $this->assertSame(123, $this->executor->extractJsonPath($data, "$'id'"));
        $this->assertSame(123, $this->executor->extractJsonPath($data, '$"id"'));

        // Nested bracket notation
        $this->assertSame('Alice', $this->executor->extractJsonPath($data, "$['user']['name']"));
        $this->assertSame('alice@example.com', $this->executor->extractJsonPath($data, "$.user['profile']['email']"));

        // Array index with bracket notation
        $this->assertSame(10, $this->executor->extractJsonPath($data, '$.items[0].id'));
        $this->assertSame(20, $this->executor->extractJsonPath($data, "$['items'][1]['id']"));
        $this->assertSame('Gadget', $this->executor->extractJsonPath($data, "items[1]['title']"));
    }

    public function test_regression_dsl_json_string_preserves_empty_object_body(): void
    {
        $this->fakeClient->respondWith(200, ['status' => 'ok']);
        $this->fakeClient->respondWith(200, ['status' => 'ok']);

        // 1. DSL with empty object body: "body": {}
        $jsonWithEmptyObject = json_encode([
            'dsl_version' => '1.0',
            'title' => 'Empty object body test',
            'endpoint' => ['method' => 'POST', 'path' => '/test-object'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'Send empty object',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/test-object',
                        'body' => (object) [],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ]);
        $this->assertIsString($jsonWithEmptyObject);
        $this->assertStringContainsString('"body":{}', $jsonWithEmptyObject);

        $resultObj = $this->executor->execute($jsonWithEmptyObject, 'http://localhost:8000');
        $this->assertTrue($resultObj->isSuccess);

        $sent = $this->fakeClient->sent();
        $this->assertCount(1, $sent);
        $this->assertIsObject($sent[0]->body, 'Body {} in JSON string DSL must remain an object, not array []');
        $this->assertSame('{}', json_encode($sent[0]->body));

        // 2. DSL with empty array body: "body": []
        $jsonWithEmptyArray = json_encode([
            'dsl_version' => '1.0',
            'title' => 'Empty array body test',
            'endpoint' => ['method' => 'POST', 'path' => '/test-array'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'Send empty array',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/test-array',
                        'body' => [],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ]);
        $this->assertIsString($jsonWithEmptyArray);
        $this->assertStringContainsString('"body":[]', $jsonWithEmptyArray);

        $resultArr = $this->executor->execute($jsonWithEmptyArray, 'http://localhost:8000');
        $this->assertTrue($resultArr->isSuccess);

        $sent = $this->fakeClient->sent();
        $this->assertCount(2, $sent);
        $this->assertIsArray($sent[1]->body, 'Body [] in JSON string DSL must remain an array []');
        $this->assertSame('[]', json_encode($sent[1]->body));
    }

    public function test_regression_response_body_truncation_preserves_valid_utf8(): void
    {
        // 65,535 ASCII characters + 'ệ' (3-byte UTF-8: 0xE1 0xBB 0x87)
        // Total byte length: 65,538 bytes. Cutting at 65,536 bytes with substr() would cut inside 'ệ',
        // leaving 0xE1 which produces invalid/malformed UTF-8.
        $vietnameseChar = 'ệ';
        $rawBody = str_repeat('A', 65535).$vietnameseChar;
        $this->assertSame(65538, strlen($rawBody));

        // Ensure raw substr indeed causes malformed UTF-8 in json_encode (regression reproduction)
        $brokenUtf8 = substr($rawBody, 0, 65536);
        $this->assertFalse(json_encode($brokenUtf8));
        $this->assertSame(JSON_ERROR_UTF8, json_last_error());

        // StepExecutionResult::truncateBody cuts safely at character boundary
        $safeTruncated = StepExecutionResult::truncateBody($rawBody);
        $this->assertLessThanOrEqual(65536, strlen($safeTruncated));
        $this->assertSame(65535, strlen($safeTruncated));
        $this->assertNotFalse(json_encode($safeTruncated));
        $this->assertSame(JSON_ERROR_NONE, json_last_error());

        // Also test through executor execution result
        $largeResponseClient = new class($rawBody) implements TargetHttpClient
        {
            public function __construct(private string $body) {}

            public function send(TargetRequest $request): TargetResponse
            {
                return new TargetResponse(
                    status: 200,
                    headers: ['Content-Type' => ['text/plain; charset=utf-8']],
                    body: $this->body,
                    durationMs: 10
                );
            }
        };

        $executor = new TestExecutor($largeResponseClient, $this->allowlistValidator);
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'UTF-8 response truncation test',
            'endpoint' => ['method' => 'GET', 'path' => '/utf8'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'Get UTF-8 body',
                    'request' => ['method' => 'GET', 'path' => '/utf8'],
                ],
            ],
        ];

        $result = $executor->execute($dsl, 'http://localhost:8000');
        $this->assertTrue($result->isSuccess);

        $resLogs = $result->responseLogs();
        $this->assertCount(1, $resLogs);
        $encodedLogs = json_encode($resLogs);
        $this->assertNotFalse($encodedLogs, 'responseLogs() must be valid JSON');

        $stepArray = $result->stepResults[0]->toArray();
        $encodedStep = json_encode($stepArray);
        $this->assertNotFalse($encodedStep, 'StepExecutionResult::toArray() must be valid JSON');
    }

    public function test_regression_dsl_json_string_preserves_numeric_key_object_in_payload(): void
    {
        $this->fakeClient->respondWith(200, ['status' => 'ok']);

        // DSL passed as JSON string where body has numeric keys: {"0":"zero","1":"one"}
        $jsonDsl = json_encode([
            'dsl_version' => '1.0',
            'title' => 'Numeric keys in body object test',
            'endpoint' => ['method' => 'POST', 'path' => '/numeric-keys'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'Send numeric key object',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/numeric-keys',
                        'body' => (object) [
                            '0' => 'zero',
                            '1' => 'one',
                        ],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ]);
        $this->assertIsString($jsonDsl);
        $this->assertStringContainsString('"body":{"0":"zero","1":"one"}', $jsonDsl);

        $result = $this->executor->execute($jsonDsl, 'http://localhost:8000');
        $this->assertTrue($result->isSuccess);

        $sent = $this->fakeClient->sent();
        $this->assertCount(1, $sent);
        $this->assertIsObject($sent[0]->body, 'Body {"0":"zero","1":"one"} in JSON string DSL must remain an object');
        // Crucial: json_encode must produce object {"0":"zero","1":"one"}, NOT array ["zero","one"]
        $this->assertSame('{"0":"zero","1":"one"}', json_encode($sent[0]->body));
    }

    public function test_regression_url_placeholder_double_curly_does_not_leave_single_curly_brackets(): void
    {
        $this->fakeClient->respondWith(200, ['status' => 'ok']);

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Double curly URL variable replacement',
            'endpoint' => ['method' => 'GET', 'path' => '/items/{{id}}'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'variables' => ['id' => 42],
            'steps' => [
                [
                    'name' => 'Get item',
                    'request' => ['method' => 'GET', 'path' => '/items/{{id}}'],
                ],
            ],
        ];

        $result = $this->executor->execute($dsl, 'http://localhost:8000');
        $this->assertTrue($result->isSuccess);

        $sent = $this->fakeClient->sent();
        $this->assertCount(1, $sent);
        $this->assertSame('http://localhost:8000/items/42', $sent[0]->url);

        // Also test buildResolvedPath directly with mixed {param} and {{var}}
        $resolved = $this->executor->buildResolvedPath(
            '/users/{userId}/items/{{itemId}}',
            ['userId' => 'u1'],
            ['itemId' => 'i2']
        );
        $this->assertSame('/users/u1/items/i2', $resolved);

        // And test fallback to variables for single curly
        $resolvedSingle = $this->executor->buildResolvedPath(
            '/items/{id}',
            [],
            ['id' => 42]
        );
        $this->assertSame('/items/42', $resolvedSingle);
    }

    public function test_regression_dsl_json_string_preserves_query_parameters_with_numeric_keys(): void
    {
        $this->fakeClient->respondWith(200, ['status' => 'ok']);

        $jsonDsl = json_encode([
            'dsl_version' => '1.0',
            'title' => 'Numeric keys in query test',
            'endpoint' => ['method' => 'GET', 'path' => '/search'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'Search with numeric query key',
                    'request' => [
                        'method' => 'GET',
                        'path' => '/search',
                        'query' => (object) [
                            '0' => 'zero',
                            'q' => 'search',
                        ],
                    ],
                ],
            ],
        ]);
        $this->assertIsString($jsonDsl);
        $this->assertStringContainsString('"query":{"0":"zero","q":"search"}', $jsonDsl);

        $result = $this->executor->execute($jsonDsl, 'http://localhost:8000');
        $this->assertTrue($result->isSuccess);

        $sent = $this->fakeClient->sent();
        $this->assertCount(1, $sent);
        /** @var array<array-key, mixed> $sentQuery */
        $sentQuery = $sent[0]->query;
        $this->assertSame('zero', $sentQuery[0] ?? $sentQuery['0'] ?? null);
        $this->assertSame('search', $sentQuery['q'] ?? null);
    }

    public function test_regression_extracted_response_object_with_numeric_keys_preserves_object_type(): void
    {
        // Step 1 returns an object with numeric keys: {"0":"zero","1":"one"}
        $this->fakeClient->respondWith(200, (object) ['0' => 'zero', '1' => 'one']);
        // Step 2 returns 200 ok
        $this->fakeClient->respondWith(200, ['status' => 'created']);

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Extract numeric-key object from response body',
            'endpoint' => ['method' => 'POST', 'path' => '/step2'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'Step 1: Extract body',
                    'request' => ['method' => 'GET', 'path' => '/step1'],
                    'extract' => [
                        [
                            'var' => 'extracted_payload',
                            'from' => 'body',
                            'path' => '$',
                        ],
                    ],
                ],
                [
                    'name' => 'Step 2: Use extracted payload as body',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/step2',
                        'body' => '{{extracted_payload}}',
                    ],
                ],
            ],
        ];

        $result = $this->executor->execute($dsl, 'http://localhost:8000');
        $this->assertTrue($result->isSuccess);

        $sent = $this->fakeClient->sent();
        $this->assertCount(2, $sent);

        // Step 2 body must be an object (stdClass), not an array
        $this->assertIsObject($sent[1]->body, 'Extracted response object must remain stdClass when passed as next step body');
        $this->assertSame('{"0":"zero","1":"one"}', json_encode($sent[1]->body));
    }

    public function test_regression_variable_objects_preserved_across_array_json_string_and_object_dsl(): void
    {
        $dslArray = [
            'dsl_version' => '1.0',
            'title' => 'Variable objects preservation',
            'endpoint' => ['method' => 'POST', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'variables' => [
                'payload' => (object) ['0' => 'zero', '1' => 'one'],
                'empty_obj' => new \stdClass,
            ],
            'steps' => [
                [
                    'name' => 'Step 1: Send payload',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/items',
                        'body' => '{{payload}}',
                    ],
                ],
                [
                    'name' => 'Step 2: Send empty object',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/empty',
                        'body' => '{{empty_obj}}',
                    ],
                ],
            ],
        ];

        $jsonDsl = (string) json_encode($dslArray);
        $objectDsl = json_decode($jsonDsl, false);

        // 1. Execute DSL as array
        $clientArray = new FakeTargetHttpClient;
        $executorArray = new TestExecutor($clientArray, $this->allowlistValidator);
        $executorArray->execute($dslArray, 'http://localhost:8000');
        $sentArray = $clientArray->sent();
        $this->assertSame('{"0":"zero","1":"one"}', json_encode($sentArray[0]->body));
        $this->assertSame('{}', json_encode($sentArray[1]->body));

        // 2. Execute DSL as JSON string
        $clientJson = new FakeTargetHttpClient;
        $executorJson = new TestExecutor($clientJson, $this->allowlistValidator);
        $executorJson->execute($jsonDsl, 'http://localhost:8000');
        $sentJson = $clientJson->sent();
        $this->assertSame('{"0":"zero","1":"one"}', json_encode($sentJson[0]->body));
        $this->assertSame('{}', json_encode($sentJson[1]->body));

        // 3. Execute DSL as stdClass object
        $clientObject = new FakeTargetHttpClient;
        $executorObject = new TestExecutor($clientObject, $this->allowlistValidator);
        $executorObject->execute($objectDsl, 'http://localhost:8000');
        $sentObject = $clientObject->sent();
        $this->assertSame('{"0":"zero","1":"one"}', json_encode($sentObject[0]->body));
        $this->assertSame('{}', json_encode($sentObject[1]->body));
    }

    public function test_path_parameter_and_url_variable_boolean_serialized_as_true_false(): void
    {
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Boolean path parameter and URL variable test',
            'endpoint' => ['method' => 'GET', 'path' => '/flags/{enabled}'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'variables' => [
                'feature_active' => false,
                'feature_ready' => true,
            ],
            'steps' => [
                [
                    'name' => 'Step 1: boolean false path_param',
                    'request' => [
                        'method' => 'GET',
                        'path' => '/flags/{enabled}',
                        'path_params' => ['enabled' => false],
                    ],
                ],
                [
                    'name' => 'Step 2: boolean true path_param',
                    'request' => [
                        'method' => 'GET',
                        'path' => '/flags/{enabled}',
                        'path_params' => ['enabled' => true],
                    ],
                ],
                [
                    'name' => 'Step 3: boolean false via url variable {{feature_active}}',
                    'request' => [
                        'method' => 'GET',
                        'path' => '/flags/{{feature_active}}',
                    ],
                ],
                [
                    'name' => 'Step 4: boolean true via url variable {{feature_ready}}',
                    'request' => [
                        'method' => 'GET',
                        'path' => '/flags/{{feature_ready}}',
                    ],
                ],
            ],
        ];

        $this->fakeClient->respondWith(200, ['ok' => true]);
        $this->fakeClient->respondWith(200, ['ok' => true]);
        $this->fakeClient->respondWith(200, ['ok' => true]);
        $this->fakeClient->respondWith(200, ['ok' => true]);

        $result = $this->executor->execute($dsl, 'http://benchmark');

        $this->assertTrue($result->isSuccess);
        $sent = $this->fakeClient->sent();
        $this->assertCount(4, $sent);
        $this->assertSame('http://benchmark/flags/false', $sent[0]->url);
        $this->assertSame('http://benchmark/flags/true', $sent[1]->url);
        $this->assertSame('http://benchmark/flags/false', $sent[2]->url);
        $this->assertSame('http://benchmark/flags/true', $sent[3]->url);
    }

    public function test_request_body_absent_vs_null_vs_false_vs_empty_object_and_array(): void
    {
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Request body variations',
            'endpoint' => ['method' => 'POST', 'path' => '/body-test'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'Step 1: absent body',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/body-test/absent',
                    ],
                ],
                [
                    'name' => 'Step 2: null body',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/body-test/null',
                        'body' => null,
                    ],
                ],
                [
                    'name' => 'Step 3: false body',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/body-test/false',
                        'body' => false,
                    ],
                ],
                [
                    'name' => 'Step 4: empty object body',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/body-test/empty-object',
                        'body' => new \stdClass,
                    ],
                ],
                [
                    'name' => 'Step 5: empty array body',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/body-test/empty-array',
                        'body' => [],
                    ],
                ],
            ],
        ];

        for ($i = 0; $i < 5; $i++) {
            $this->fakeClient->respondWith(200, ['status' => 'ok']);
        }

        $result = $this->executor->execute($dsl, 'http://benchmark');
        $this->assertTrue($result->isSuccess);

        $sent = $this->fakeClient->sent();
        $this->assertCount(5, $sent);

        // Step 1: Absent body
        $this->assertFalse($sent[0]->hasBody);
        $this->assertNull($sent[0]->body);
        $this->assertArrayNotHasKey('Content-Type', $sent[0]->headers);

        // Step 2: Body is null
        $this->assertTrue($sent[1]->hasBody);
        $this->assertNull($sent[1]->body);
        $this->assertSame('application/json', $sent[1]->headers['Content-Type']);

        // Step 3: Body is false
        $this->assertTrue($sent[2]->hasBody);
        $this->assertFalse($sent[2]->body);
        $this->assertSame('application/json', $sent[2]->headers['Content-Type']);

        // Step 4: Body is empty object
        $this->assertTrue($sent[3]->hasBody);
        $this->assertInstanceOf(\stdClass::class, $sent[3]->body);
        $this->assertSame('application/json', $sent[3]->headers['Content-Type']);

        // Step 5: Body is empty array
        $this->assertTrue($sent[4]->hasBody);
        $this->assertSame([], $sent[4]->body);
        $this->assertSame('application/json', $sent[4]->headers['Content-Type']);
    }

    public function test_extract_empty_string_key_with_quotes(): void
    {
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Extract empty string key test',
            'endpoint' => ['method' => 'GET', 'path' => '/data'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'Step 1: Get data and extract empty key',
                    'request' => [
                        'method' => 'GET',
                        'path' => '/data',
                    ],
                    'extract' => [
                        ['var' => 'val_double_quote', 'from' => 'body', 'path' => '$""'],
                        ['var' => 'val_single_quote', 'from' => 'body', 'path' => "$''"],
                        ['var' => 'val_bracket_double', 'from' => 'body', 'path' => '$[""]'],
                        ['var' => 'val_bracket_single', 'from' => 'body', 'path' => "$['']"],
                        ['var' => 'whole_root_dollar', 'from' => 'body', 'path' => '$'],
                        ['var' => 'whole_root_empty', 'from' => 'body', 'path' => ''],
                    ],
                ],
                [
                    'name' => 'Step 2: Use extracted empty key variable',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/result',
                        'body' => [
                            'extracted' => '{{val_double_quote}}',
                        ],
                    ],
                ],
            ],
        ];

        // Step 1 returns object with empty string key
        $this->fakeClient->respondWith(200, ['' => 'wanted', 'other' => 123]);
        $this->fakeClient->respondWith(201, ['created' => true]);

        $result = $this->executor->execute($dsl, 'http://benchmark');

        $this->assertTrue($result->isSuccess);

        $step1 = $result->stepResults[0];
        $this->assertSame('wanted', $step1->extractedVariables['val_double_quote']);
        $this->assertSame('wanted', $step1->extractedVariables['val_single_quote']);
        $this->assertSame('wanted', $step1->extractedVariables['val_bracket_double']);
        $this->assertSame('wanted', $step1->extractedVariables['val_bracket_single']);
        $this->assertEquals((object) ['' => 'wanted', 'other' => 123], $step1->extractedVariables['whole_root_dollar']);
        $this->assertEquals((object) ['' => 'wanted', 'other' => 123], $step1->extractedVariables['whole_root_empty']);

        $sent = $this->fakeClient->sent();
        $this->assertCount(2, $sent);
        $this->assertSame(['extracted' => 'wanted'], $sent[1]->body);
    }
}
