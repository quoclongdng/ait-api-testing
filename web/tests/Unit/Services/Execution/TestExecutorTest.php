<?php

namespace Tests\Unit\Services\Execution;

use App\Contracts\TargetHttpClient;
use App\Data\Execution\StepExecutionResult;
use App\Data\TargetRequest;
use App\Data\TargetResponse;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Services\Execution\TestExecutor;
use App\Services\Target\FakeTargetHostResolver;
use App\Services\Target\FakeTargetHttpClient;
use App\Services\Target\TargetAllowlistService;
use Exception;
use Tests\TestCase;

class TestExecutorTest extends TestCase
{
    private FakeTargetHttpClient $fakeClient;

    private TargetAllowlistService $allowlistService;

    private TestExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeClient = new FakeTargetHttpClient;
        $this->allowlistService = new TargetAllowlistService(
            ['localhost', '127.0.0.1', 'benchmark'],
            resolver: new FakeTargetHostResolver(['localhost' => ['127.0.0.1'], 'benchmark' => ['127.0.0.1']]),
        );
        $this->executor = new TestExecutor($this->fakeClient, $this->allowlistService);
    }

    public function test_it_throws_target_not_allowed_when_target_outside_allowlist(): void
    {
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Test Untrusted Host',
            'endpoint' => ['method' => 'GET', 'path' => '/secret'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'Call untrusted host',
                    'request' => ['method' => 'GET', 'path' => '/secret'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        try {
            $this->executor->execute($dsl, 'http://malicious-domain.com');
            $this->fail('Expected ApiException TARGET_NOT_ALLOWED');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::TargetNotAllowed, $e->errorCode);
            $this->assertSame('TARGET_NOT_ALLOWED', $e->errorCode->value);
            $this->assertSame(422, $e->errorCode->httpStatus());
        }

        $this->assertCount(0, $this->fakeClient->sent());
    }

    public function test_it_executes_step_using_fake_target_http_client(): void
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
                    'name' => 'Check health',
                    'request' => ['method' => 'GET', 'path' => '/health'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $result = $this->executor->execute($dsl, 'http://benchmark:8002');

        $this->assertTrue($result->isSuccess);
        $this->assertSame(1, $result->stepCount());
        $this->assertCount(1, $this->fakeClient->sent());

        $sent = $this->fakeClient->sent()[0];
        $this->assertSame('GET', $sent->method);
        $this->assertSame('http://benchmark:8002/health', $sent->url);

        $lastStep = $result->lastStep();
        $this->assertInstanceOf(StepExecutionResult::class, $lastStep);
        $this->assertNotNull($lastStep->response);
        $this->assertSame(200, $lastStep->response->status);
        $this->assertSame(['status' => 'ok'], $lastStep->response->json());
    }

    public function test_it_interpolates_variables_in_headers_query_body_and_path(): void
    {
        $this->fakeClient->respondWith(201, ['id' => 999]);

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Create user item',
            'endpoint' => ['method' => 'POST', 'path' => '/users/{userId}/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'variables' => [
                'userId' => 42,
                'token' => 'jwt_secret_token',
                'itemCount' => 10,
                'activeFlag' => true,
            ],
            'steps' => [
                [
                    'name' => 'Create item step',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/users/{{userId}}/items',
                        'headers' => [
                            'Authorization' => 'Bearer {{token}}',
                            'X-Custom' => 'value-{{userId}}',
                        ],
                        'query' => [
                            'filter' => 'user-{{userId}}',
                        ],
                        'body' => [
                            'quantity' => '{{itemCount}}',
                            'is_active' => '{{activeFlag}}',
                            'description' => 'User {{userId}} order',
                        ],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 201]],
                ],
            ],
        ];

        $result = $this->executor->execute($dsl, 'http://127.0.0.1:8000');

        $this->assertTrue($result->isSuccess);
        $sent = $this->fakeClient->sent()[0];

        $this->assertSame('http://127.0.0.1:8000/users/42/items', $sent->url);
        $this->assertSame('Bearer jwt_secret_token', $sent->headers['Authorization']);
        $this->assertSame('value-42', $sent->headers['X-Custom']);
        $this->assertSame('user-42', $sent->query['filter']);

        // Check preservation of native types (int 10, bool true)
        $this->assertSame(10, $sent->body['quantity']);
        $this->assertTrue($sent->body['is_active']);
        $this->assertSame('User 42 order', $sent->body['description']);
    }

    public function test_unresolved_auth_token_variable_fails_the_step_without_sending(): void
    {
        // S11-01: {{auth_token_<role>}} only exists in an experiment run (ADR-0006).
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Read cart',
            'endpoint' => ['method' => 'GET', 'path' => '/cart'],
            'technique' => 'HAPPY_PATH',
            'source' => 'LLM',
            'steps' => [
                [
                    'name' => 'Read cart',
                    'request' => ['method' => 'GET', 'path' => '/cart', 'headers' => ['Authorization' => 'Bearer {{auth_token_customer_bob}}']],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $result = $this->executor->execute($dsl, 'http://127.0.0.1:8000');

        $this->assertFalse($result->isSuccess);
        $this->assertCount(0, $this->fakeClient->sent());
        $step = $result->stepResults[0];
        $this->assertNull($step->response);
        $this->assertStringContainsString('Biến {{auth_token_customer_bob}} chưa có giá trị', (string) $step->errorMessage);
        $this->assertStringContainsString('token sandbox của benchmark', (string) $step->errorMessage);

        // With the run variable of an experiment the same case is sent with the token.
        $this->fakeClient->respondWith(200, ['items' => []]);
        $result = $this->executor->execute($dsl, 'http://127.0.0.1:8000', ['auth_token_customer_bob' => 'tok-bob']);

        $this->assertTrue($result->isSuccess);
        $this->assertSame('Bearer tok-bob', $this->fakeClient->sent()[0]->headers['Authorization']);
    }

    public function test_other_unresolved_variables_keep_their_previous_behaviour(): void
    {
        $this->fakeClient->respondWith(200, []);
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Read cart',
            'endpoint' => ['method' => 'GET', 'path' => '/cart'],
            'technique' => 'HAPPY_PATH',
            'source' => 'LLM',
            'steps' => [
                [
                    'name' => 'Read cart',
                    'request' => ['method' => 'GET', 'path' => '/cart', 'headers' => ['X-Trace' => '{{trace_id}}']],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $result = $this->executor->execute($dsl, 'http://127.0.0.1:8000');

        $this->assertTrue($result->isSuccess);
        $this->assertSame('{{trace_id}}', $this->fakeClient->sent()[0]->headers['X-Trace']);
    }

    public function test_it_interpolates_openapi_path_params(): void
    {
        $this->fakeClient->respondWith(200, ['status' => 'paid']);

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Pay order with path params',
            'endpoint' => ['method' => 'PATCH', 'path' => '/orders/{orderId}/pay'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'variables' => [
                'activeOrderId' => 888,
            ],
            'steps' => [
                [
                    'name' => 'Pay order',
                    'request' => [
                        'method' => 'PATCH',
                        'path' => '/orders/{id}/pay',
                        'path_params' => [
                            'id' => '{{activeOrderId}}',
                        ],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $result = $this->executor->execute($dsl, 'http://benchmark:8002');

        $this->assertTrue($result->isSuccess);
        $sent = $this->fakeClient->sent()[0];
        $this->assertSame('http://benchmark:8002/orders/888/pay', $sent->url);
    }

    public function test_it_extracts_variables_from_status_header_and_body_jsonpath(): void
    {
        $this->fakeClient->respondWith(
            status: 201,
            json: [
                'data' => [
                    'order' => [
                        'id' => 777,
                        'code' => 'ORD-777',
                    ],
                    'items' => [
                        ['sku' => 'SKU-A', 'price' => 100],
                        ['sku' => 'SKU-B', 'price' => 200],
                    ],
                ],
            ],
            headers: [
                'X-Auth-Token' => ['token_abc_123'],
                'Location' => ['/orders/777'],
            ]
        );

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Extract variables test',
            'endpoint' => ['method' => 'POST', 'path' => '/orders'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'Create and extract',
                    'request' => ['method' => 'POST', 'path' => '/orders'],
                    'extract' => [
                        ['var' => 'httpStatus', 'from' => 'status'],
                        ['var' => 'authToken', 'from' => 'header', 'path' => 'x-auth-token'],
                        ['var' => 'orderId', 'from' => 'body', 'path' => '$.data.order.id'],
                        ['var' => 'orderCode', 'from' => 'body', 'path' => 'data.order.code'],
                        ['var' => 'secondSku', 'from' => 'body', 'path' => '$.data.items[1].sku'],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 201]],
                ],
            ],
        ];

        $result = $this->executor->execute($dsl, 'http://benchmark:8002');

        $this->assertTrue($result->isSuccess);
        $this->assertSame(201, $result->variables['httpStatus']);
        $this->assertSame('token_abc_123', $result->variables['authToken']);
        $this->assertSame(777, $result->variables['orderId']);
        $this->assertSame('ORD-777', $result->variables['orderCode']);
        $this->assertSame('SKU-B', $result->variables['secondSku']);

        $step = $result->lastStep();
        $this->assertNotNull($step);
        $this->assertSame(777, $step->extractedVariables['orderId']);
    }

    public function test_it_chains_extracted_variables_across_multi_step_flow(): void
    {
        // Step 1: Create order -> returns id 501
        $this->fakeClient->respondWith(201, ['id' => 501, 'status' => 'DRAFT']);
        // Step 2: Pay order -> returns status PAID
        $this->fakeClient->respondWith(200, ['id' => 501, 'status' => 'PAID']);
        // Step 3: Get order -> returns full order
        $this->fakeClient->respondWith(200, ['id' => 501, 'status' => 'PAID', 'amount' => 150]);

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Multi-step order chain',
            'endpoint' => ['method' => 'POST', 'path' => '/orders'],
            'technique' => 'SEQUENCE',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'Step 1: Create Order',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/orders',
                        'body' => ['amount' => 150],
                    ],
                    'extract' => [
                        ['var' => 'createdId', 'from' => 'body', 'path' => '$.id'],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 201]],
                ],
                [
                    'name' => 'Step 2: Pay Order',
                    'request' => [
                        'method' => 'PATCH',
                        'path' => '/orders/{id}/pay',
                        'path_params' => [
                            'id' => '{{createdId}}',
                        ],
                    ],
                    'extract' => [
                        ['var' => 'paidStatus', 'from' => 'body', 'path' => '$.status'],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
                [
                    'name' => 'Step 3: Verify Order',
                    'request' => [
                        'method' => 'GET',
                        'path' => '/orders/{{createdId}}',
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $result = $this->executor->execute($dsl, 'http://benchmark:8002');

        $this->assertTrue($result->isSuccess);
        $this->assertSame(3, $result->stepCount());
        $this->assertSame(501, $result->variables['createdId']);
        $this->assertSame('PAID', $result->variables['paidStatus']);

        $sent = $this->fakeClient->sent();
        $this->assertSame('http://benchmark:8002/orders', $sent[0]->url);
        $this->assertSame('http://benchmark:8002/orders/501/pay', $sent[1]->url);
        $this->assertSame('http://benchmark:8002/orders/501', $sent[2]->url);
    }

    public function test_it_executes_canonical_sequence_fixture(): void
    {
        $fixturePath = dirname(__DIR__, 5).'/docs/contracts/examples/valid-sequence-multi-step.json';
        $this->assertFileExists($fixturePath);

        /** @var array<string, mixed> $dsl */
        $dsl = json_decode((string) file_get_contents($fixturePath), true);

        // Queue responses for 4 steps:
        // Step 1: POST /orders
        $this->fakeClient->respondWith(201, ['id' => 101, 'status' => 'DRAFT']);
        // Step 2: PATCH /orders/{id}/pay
        $this->fakeClient->respondWith(200, ['id' => 101, 'status' => 'PAID']);
        // Step 3: GET /orders/{id}
        $this->fakeClient->respondWith(200, ['id' => 101, 'status' => 'PAID']);
        // Step 4: DELETE /orders/{id}
        $this->fakeClient->respondWith(204, null);

        $result = $this->executor->execute($dsl, 'http://benchmark:8002');

        $this->assertTrue($result->isSuccess);
        $this->assertSame(4, $result->stepCount());
        $this->assertSame(101, $result->variables['orderId']);
        $this->assertSame('DRAFT', $result->variables['orderStatus']);

        $sent = $this->fakeClient->sent();
        $this->assertSame(4, count($sent));
        $this->assertSame('http://benchmark:8002/orders/101/pay', $sent[1]->url);
        $this->assertSame('http://benchmark:8002/orders/101', $sent[2]->url);
        $this->assertSame('http://benchmark:8002/orders/101', $sent[3]->url);
    }

    public function test_it_applies_timeout_from_config_or_parameter(): void
    {
        $this->fakeClient->respondWith(200, ['ok' => true]);

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Timeout test',
            'endpoint' => ['method' => 'GET', 'path' => '/ping'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'Ping',
                    'request' => ['method' => 'GET', 'path' => '/ping'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        // Custom timeout 5000ms
        $this->executor->execute($dsl, 'http://localhost:8000', timeoutMs: 5000);
        $this->assertSame(5000, $this->fakeClient->sent()[0]->timeoutMs);

        // Default timeout from config (default 10000ms)
        $this->executor->execute($dsl, 'http://localhost:8000');
        $this->assertSame(10000, $this->fakeClient->sent()[1]->timeoutMs);
    }

    public function test_it_sanitizes_authorization_in_request_log_and_truncates_large_response(): void
    {
        $largeBody = str_repeat('A', 70000); // 70 KB > 64 KB
        $this->fakeClient->respondWith(200, rawBody: $largeBody);

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Security sanitization test',
            'endpoint' => ['method' => 'POST', 'path' => '/login'],
            'technique' => 'AUTH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'Login step',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/login',
                        'headers' => [
                            'Authorization' => 'Bearer super-secret-token-12345',
                            'X-Api-Key' => 'public-key',
                        ],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $result = $this->executor->execute($dsl, 'http://localhost:8000');
        $stepResult = $result->lastStep();
        $this->assertNotNull($stepResult);

        // Request log must mask credential headers (Authorization, X-Api-Key, ...)
        $requestLog = $stepResult->requestLog();
        $this->assertSame('***', $requestLog['headers']['Authorization']);
        // S10-01: API keys are credentials too (docs/DESIGN.md §9).
        $this->assertSame('***', $requestLog['headers']['X-Api-Key']);

        // Response log must be truncated
        $responseLog = $stepResult->responseLog();
        $this->assertNotNull($responseLog);
        $this->assertSame(str_repeat('A', StepExecutionResult::MAX_RESPONSE_LOG_BYTES), $responseLog['body']);
        $this->assertLessThanOrEqual(65536, strlen((string) $responseLog['body']));
    }

    public function test_it_handles_client_connection_error_gracefully(): void
    {
        $mockClient = new class implements TargetHttpClient
        {
            public function send(TargetRequest $request): TargetResponse
            {
                throw new Exception('Connection timeout to target server');
            }
        };

        $executor = new TestExecutor($mockClient, $this->allowlistService);

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Failing connection',
            'endpoint' => ['method' => 'GET', 'path' => '/ping'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'Ping step 1',
                    'request' => ['method' => 'GET', 'path' => '/ping'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
                [
                    'name' => 'Ping step 2',
                    'request' => ['method' => 'GET', 'path' => '/ping'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $result = $executor->execute($dsl, 'http://localhost:8000');

        $this->assertFalse($result->isSuccess);
        $this->assertSame(1, $result->stepCount()); // Halts after first failure
        $this->assertStringContainsString('Connection timeout', (string) $result->errorMessage);
        $this->assertFalse($result->lastStep()?->isSuccess());
    }

    public function test_it_preserves_object_types_in_assertion_value_from_json_dsl(): void
    {
        $makeDsl = fn (array $assertion): string => json_encode([
            'dsl_version' => '1.0',
            'title' => 'Object value preservation',
            'endpoint' => ['method' => 'GET', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'RULE',
            'steps' => [
                [
                    'name' => 'Step 1',
                    'request' => ['method' => 'GET', 'path' => '/items'],
                    'assertions' => [$assertion],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        // 1. Empty object: eq {} passes for {} response, fails for [] response
        $this->fakeClient->respondWith(200, rawBody: '{}');
        $res = $this->executor->execute($makeDsl(['type' => 'json_path', 'path' => '$', 'op' => 'eq', 'value' => (object) []]), 'http://localhost:8000');
        $this->assertTrue($res->isSuccess);

        $this->fakeClient->respondWith(200, rawBody: '[]');
        $res = $this->executor->execute($makeDsl(['type' => 'json_path', 'path' => '$', 'op' => 'eq', 'value' => (object) []]), 'http://localhost:8000');
        $this->assertFalse($res->isSuccess);

        // 2. Numeric-keyed object vs array
        $numObj = (object) ['0' => 'a', '1' => 'b'];
        $this->fakeClient->respondWith(200, rawBody: '{"0":"a","1":"b"}');
        $res = $this->executor->execute($makeDsl(['type' => 'json_path', 'path' => '$', 'op' => 'eq', 'value' => $numObj]), 'http://localhost:8000');
        $this->assertTrue($res->isSuccess);

        $this->fakeClient->respondWith(200, rawBody: '["a","b"]');
        $res = $this->executor->execute($makeDsl(['type' => 'json_path', 'path' => '$', 'op' => 'eq', 'value' => $numObj]), 'http://localhost:8000');
        $this->assertFalse($res->isSuccess);

        // 3. Nested object
        $nestedObj = (object) ['child' => (object) []];
        $this->fakeClient->respondWith(200, rawBody: '{"child":{}}');
        $res = $this->executor->execute($makeDsl(['type' => 'json_path', 'path' => '$', 'op' => 'eq', 'value' => $nestedObj]), 'http://localhost:8000');
        $this->assertTrue($res->isSuccess);

        $this->fakeClient->respondWith(200, rawBody: '{"child":[]}');
        $res = $this->executor->execute($makeDsl(['type' => 'json_path', 'path' => '$', 'op' => 'eq', 'value' => $nestedObj]), 'http://localhost:8000');
        $this->assertFalse($res->isSuccess);

        // 4. Contains object
        $this->fakeClient->respondWith(200, rawBody: '[{}]');
        $res = $this->executor->execute($makeDsl(['type' => 'json_path', 'path' => '$', 'op' => 'contains', 'value' => (object) []]), 'http://localhost:8000');
        $this->assertTrue($res->isSuccess);

        $this->fakeClient->respondWith(200, rawBody: '[[]]');
        $res = $this->executor->execute($makeDsl(['type' => 'json_path', 'path' => '$', 'op' => 'contains', 'value' => (object) []]), 'http://localhost:8000');
        $this->assertFalse($res->isSuccess);
    }
}
