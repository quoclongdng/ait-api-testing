<?php

namespace Tests\Unit\Services\Execution;

use App\Contracts\TargetHttpClient;
use App\Data\TargetRequest;
use App\Data\TargetResponse;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Services\Dsl\DslValidator;
use App\Services\Execution\AssertionEngine;
use App\Services\Execution\TestExecutor;
use App\Services\Target\FakeTargetHostResolver;
use App\Services\Target\FakeTargetHttpClient;
use App\Services\Target\TargetAllowlistService;
use Tests\TestCase;

class SequenceLifecycleTest extends TestCase
{
    private FakeTargetHttpClient $fakeClient;

    private TargetAllowlistService $allowlistService;

    private AssertionEngine $assertionEngine;

    private DslValidator $validator;

    private TestExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeClient = new FakeTargetHttpClient;
        $this->allowlistService = new TargetAllowlistService(
            ['localhost', '127.0.0.1', 'benchmark'],
            resolver: new FakeTargetHostResolver(['localhost' => ['127.0.0.1'], 'benchmark' => ['127.0.0.1']]),
        );
        $this->assertionEngine = new AssertionEngine;
        $this->validator = new DslValidator;
        $this->executor = new TestExecutor(
            $this->fakeClient,
            $this->allowlistService,
            $this->assertionEngine,
            dslValidator: $this->validator,
        );
    }

    // =========================================================================
    // SECTION 1: ACCEPTANCE CRITERIA 1 - BIẾN CHƯA EXTRACT → DSL_INVALID (HTTP 422)
    // =========================================================================

    public function test_unextracted_variable_in_path_fails_validation_and_throws_dsl_invalid(): void
    {
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Unextracted variable in request path',
            'endpoint' => ['method' => 'GET', 'path' => '/orders/{id}'],
            'technique' => 'SEQUENCE',
            'source' => 'HYBRID',
            'steps' => [
                [
                    'name' => 'Access order with undeclared variable',
                    'request' => ['method' => 'GET', 'path' => '/orders/{{unextractedOrderId}}'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        // 1. Semantic validation fails
        $result = $this->validator->validate($dsl);
        $this->assertFalse($result->isValid);
        $this->assertStringContainsString("Biến '{{unextractedOrderId}}' được sử dụng nhưng chưa được định nghĩa", $result->semanticErrors[0]);

        // 2. validateOrThrow throws ApiException with ErrorCode::DslInvalid (HTTP 422)
        try {
            $this->validator->validateOrThrow($dsl);
            $this->fail('Expected ApiException DSL_INVALID to be thrown');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::DslInvalid, $e->errorCode);
            $this->assertSame('DSL_INVALID', $e->errorCode->value);
            $this->assertSame(422, $e->errorCode->httpStatus());
        }
    }

    public function test_unextracted_variable_in_path_params_fails_validation(): void
    {
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Unextracted variable in path_params',
            'endpoint' => ['method' => 'GET', 'path' => '/items/{id}'],
            'technique' => 'SEQUENCE',
            'source' => 'HYBRID',
            'steps' => [
                [
                    'name' => 'Get item',
                    'request' => [
                        'method' => 'GET',
                        'path' => '/items/{id}',
                        'path_params' => ['id' => '{{missingItemId}}'],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $result = $this->validator->validate($dsl);
        $this->assertFalse($result->isValid);
        $this->assertStringContainsString("Biến '{{missingItemId}}' được sử dụng nhưng chưa được định nghĩa", $result->semanticErrors[0]);

        try {
            $this->validator->validateOrThrow($dsl);
            $this->fail('Expected ApiException DSL_INVALID to be thrown');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::DslInvalid, $e->errorCode);
            $this->assertSame('DSL_INVALID', $e->errorCode->value);
            $this->assertSame(422, $e->errorCode->httpStatus());
        }
    }

    public function test_unextracted_variable_in_headers_fails_validation(): void
    {
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Unextracted variable in headers',
            'endpoint' => ['method' => 'GET', 'path' => '/profile'],
            'technique' => 'SEQUENCE',
            'source' => 'HYBRID',
            'steps' => [
                [
                    'name' => 'Call with missing auth token',
                    'request' => [
                        'method' => 'GET',
                        'path' => '/profile',
                        'headers' => ['Authorization' => 'Bearer {{unextractedToken}}'],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $result = $this->validator->validate($dsl);
        $this->assertFalse($result->isValid);
        $this->assertStringContainsString("Biến '{{unextractedToken}}' được sử dụng nhưng chưa được định nghĩa", $result->semanticErrors[0]);
    }

    public function test_unextracted_variable_in_query_fails_validation(): void
    {
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Unextracted variable in query',
            'endpoint' => ['method' => 'GET', 'path' => '/search'],
            'technique' => 'SEQUENCE',
            'source' => 'HYBRID',
            'steps' => [
                [
                    'name' => 'Search with missing query term',
                    'request' => [
                        'method' => 'GET',
                        'path' => '/search',
                        'query' => ['q' => '{{missingKeyword}}'],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $result = $this->validator->validate($dsl);
        $this->assertFalse($result->isValid);
        $this->assertStringContainsString("Biến '{{missingKeyword}}' được sử dụng nhưng chưa được định nghĩa", $result->semanticErrors[0]);
    }

    public function test_unextracted_variable_in_body_fails_validation(): void
    {
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Unextracted variable in request body',
            'endpoint' => ['method' => 'POST', 'path' => '/checkout'],
            'technique' => 'SEQUENCE',
            'source' => 'HYBRID',
            'steps' => [
                [
                    'name' => 'Checkout missing cart ID',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/checkout',
                        'body' => ['cart_id' => '{{missingCartId}}', 'total' => 200],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 201]],
                ],
            ],
        ];

        $result = $this->validator->validate($dsl);
        $this->assertFalse($result->isValid);
        $this->assertStringContainsString("Biến '{{missingCartId}}' được sử dụng nhưng chưa được định nghĩa", $result->semanticErrors[0]);
    }

    public function test_unextracted_variable_in_assertion_value_fails_validation(): void
    {
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Unextracted variable in assertion comparison',
            'endpoint' => ['method' => 'GET', 'path' => '/orders/10'],
            'technique' => 'SEQUENCE',
            'source' => 'HYBRID',
            'steps' => [
                [
                    'name' => 'Verify order against undeclared variable',
                    'request' => ['method' => 'GET', 'path' => '/orders/10'],
                    'assertions' => [
                        ['type' => 'status', 'equals' => 200],
                        ['type' => 'json_path', 'path' => '$.customer_id', 'op' => 'eq', 'value' => '{{unextractedCustomerId}}'],
                    ],
                ],
            ],
        ];

        $result = $this->validator->validate($dsl);
        $this->assertFalse($result->isValid);
        $this->assertStringContainsString("Biến '{{unextractedCustomerId}}' được sử dụng nhưng chưa được định nghĩa", $result->semanticErrors[0]);
    }

    public function test_forward_reference_before_extraction_step_fails_validation(): void
    {
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Forward reference to variable extracted in subsequent step',
            'endpoint' => ['method' => 'POST', 'path' => '/checkout'],
            'technique' => 'SEQUENCE',
            'source' => 'HYBRID',
            'steps' => [
                [
                    'name' => 'Step 1: Uses token before it is created',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/checkout',
                        'headers' => ['Authorization' => 'Bearer {{sessionToken}}'],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
                [
                    'name' => 'Step 2: Creates and extracts session token',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/auth/login',
                        'body' => ['username' => 'test', 'password' => 'secret'],
                    ],
                    'extract' => [
                        ['var' => 'sessionToken', 'from' => 'body', 'path' => '$.token'],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $result = $this->validator->validate($dsl);
        $this->assertFalse($result->isValid);
        $this->assertStringContainsString("Bước 1 ('Step 1: Uses token before it is created'): Biến '{{sessionToken}}' được sử dụng nhưng chưa được định nghĩa trong variables hoặc trích xuất (extract) từ bước trước đó.", $result->semanticErrors[0]);
    }

    public function test_executor_with_dsl_validator_rejects_unextracted_variable_with_dsl_invalid(): void
    {
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Invalid DSL executed by TestExecutor',
            'endpoint' => ['method' => 'GET', 'path' => '/orders/{{missingId}}'],
            'technique' => 'SEQUENCE',
            'source' => 'HYBRID',
            'steps' => [
                [
                    'name' => 'Fetch with undeclared variable',
                    'request' => ['method' => 'GET', 'path' => '/orders/{{missingId}}'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        try {
            $this->executor->execute($dsl, 'http://benchmark:8000');
            $this->fail('Expected TestExecutor to reject unextracted variable with DSL_INVALID');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::DslInvalid, $e->errorCode);
            $this->assertSame('DSL_INVALID', $e->errorCode->value);
            $this->assertSame(422, $e->errorCode->httpStatus());
        }

        // Ensure no requests were dispatched
        $this->assertEmpty($this->fakeClient->sent());
    }

    // =========================================================================
    // SECTION 2: ACCEPTANCE CRITERIA 2 - CHUỖI SEQUENCE ĐA BƯỚC HOÀN CHỈNH
    // =========================================================================

    public function test_full_sequence_lifecycle_canonical_contract_fixture(): void
    {
        $fixturePath = dirname(__DIR__, 5).'/docs/contracts/examples/valid-sequence-multi-step.json';
        $this->assertFileExists($fixturePath);

        /** @var array<string, mixed> $dsl */
        $dsl = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);

        // 1. Validate DSL adheres to Test DSL v1.0 schema and semantic constraints
        $validation = $this->validator->validate($dsl);
        $this->assertTrue($validation->isValid, implode('; ', $validation->errors));

        // 2. Queue simulated responses for all 4 sequence steps:
        // Step 1: POST /orders -> 201 Created (draft status)
        $this->fakeClient->respondWith(201, ['id' => 999, 'status' => 'DRAFT', 'quantity' => 2, 'price' => 50]);
        // Step 2: PATCH /orders/{id}/pay -> 200 OK (paid status)
        $this->fakeClient->respondWith(200, ['id' => 999, 'status' => 'PAID']);
        // Step 3: GET /orders/{id} -> 200 OK (details check)
        $this->fakeClient->respondWith(200, ['id' => 999, 'status' => 'PAID', 'quantity' => 2]);
        // Step 4: DELETE /orders/{id} -> 204 No Content
        $this->fakeClient->respondWith(204, null);

        // Execute without strict validation during run or with validation passing
        $result = $this->executor->execute($dsl, 'http://benchmark:8000');

        $this->assertTrue($result->isSuccess);
        $this->assertSame(4, $result->stepCount());
        $this->assertCount(4, $result->stepResults);

        // Verify state passed cleanly between steps
        $this->assertSame(2, $result->variables['itemCount']);
        $this->assertSame(50, $result->variables['unitPrice']);
        $this->assertSame(999, $result->variables['orderId']);
        $this->assertSame('DRAFT', $result->variables['orderStatus']);

        // Verify wire HTTP requests:
        $sent = $this->fakeClient->sent();
        $this->assertCount(4, $sent);

        // Step 1: POST /orders with interpolated body
        $this->assertSame('POST', $sent[0]->method);
        $this->assertSame('http://benchmark:8000/orders', $sent[0]->url);
        $this->assertSame(['quantity' => 2, 'price' => 50], $sent[0]->body);

        // Step 2: PATCH /orders/999/pay (interpolated path_param)
        $this->assertSame('PATCH', $sent[1]->method);
        $this->assertSame('http://benchmark:8000/orders/999/pay', $sent[1]->url);

        // Step 3: GET /orders/999
        $this->assertSame('GET', $sent[2]->method);
        $this->assertSame('http://benchmark:8000/orders/999', $sent[2]->url);

        // Step 4: DELETE /orders/999
        $this->assertSame('DELETE', $sent[3]->method);
        $this->assertSame('http://benchmark:8000/orders/999', $sent[3]->url);

        // Verify assertions in each step passed
        foreach ($result->stepResults as $stepResult) {
            $this->assertTrue($stepResult->isSuccess);
            $this->assertNotEmpty($stepResult->assertionResults);
            foreach ($stepResult->assertionResults as $aResult) {
                $this->assertTrue($aResult->passed, (string) $aResult->message);
            }
        }
    }

    public function test_sequence_with_extractions_from_all_sources_status_header_and_body(): void
    {
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Multi-step sequence extracting from status, header, and body',
            'endpoint' => ['method' => 'POST', 'path' => '/auth/login'],
            'technique' => 'SEQUENCE',
            'source' => 'HYBRID',
            'steps' => [
                [
                    'name' => 'Step 1: Authenticate and extract status, header and body variables',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/auth/login',
                        'body' => ['username' => 'admin', 'password' => 'secret'],
                    ],
                    'extract' => [
                        ['var' => 'httpStatus', 'from' => 'status', 'path' => ''],
                        ['var' => 'bearerToken', 'from' => 'header', 'path' => 'Authorization'],
                        ['var' => 'userId', 'from' => 'body', 'path' => '$.data.user.id'],
                    ],
                    'assertions' => [
                        ['type' => 'status', 'equals' => 200],
                        ['type' => 'header', 'name' => 'Authorization', 'op' => 'exists'],
                        ['type' => 'json_path', 'path' => '$.data.user.id', 'op' => 'eq', 'value' => 42],
                    ],
                ],
                [
                    'name' => 'Step 2: Use extracted variables in header, query, and path',
                    'request' => [
                        'method' => 'GET',
                        'path' => '/users/{{userId}}/dashboard',
                        'headers' => [
                            'Authorization' => '{{bearerToken}}',
                            'X-Prev-Status' => '{{httpStatus}}',
                        ],
                        'query' => [
                            'origin_status' => '{{httpStatus}}',
                        ],
                    ],
                    'assertions' => [
                        ['type' => 'status', 'equals' => 200],
                        ['type' => 'json_path', 'path' => '$.owner_id', 'op' => 'eq', 'value' => '{{userId}}'],
                    ],
                ],
            ],
        ];

        // 1. Validation passes
        $validation = $this->validator->validate($dsl);
        $this->assertTrue($validation->isValid, implode('; ', $validation->errors));

        // 2. Queue simulated responses
        $this->fakeClient->respondWith(
            200,
            ['data' => ['user' => ['id' => 42, 'role' => 'admin']]],
            headers: ['Authorization' => ['Bearer token-secret-xyz']],
        );
        $this->fakeClient->respondWith(
            200,
            ['owner_id' => 42, 'status' => 'active'],
        );

        // 3. Execute
        $result = $this->executor->execute($dsl, 'http://benchmark:8000');

        $this->assertTrue($result->isSuccess);
        $this->assertSame(2, $result->stepCount());

        $this->assertSame(200, $result->variables['httpStatus']);
        $this->assertSame('Bearer token-secret-xyz', $result->variables['bearerToken']);
        $this->assertSame(42, $result->variables['userId']);

        $sent = $this->fakeClient->sent();
        $this->assertCount(2, $sent);

        // Verify Step 2 wire request
        $this->assertSame('http://benchmark:8000/users/42/dashboard', $sent[1]->url);
        $this->assertSame('Bearer token-secret-xyz', $sent[1]->headers['Authorization']);
        $this->assertSame('200', $sent[1]->headers['X-Prev-Status']);
        $this->assertSame(['origin_status' => 200], $sent[1]->query);
    }

    public function test_extract_path_with_variable_placeholder_is_interpolated_at_runtime(): void
    {
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Templated extract path',
            'endpoint' => ['method' => 'GET', 'path' => '/items'],
            'technique' => 'SEQUENCE',
            'source' => 'HYBRID',
            'variables' => ['idx' => 1],
            'steps' => [
                [
                    'name' => 'Step 1: List items and extract the item at {{idx}}',
                    'request' => ['method' => 'GET', 'path' => '/items'],
                    'extract' => [
                        ['var' => 'itemId', 'from' => 'body', 'path' => '$.items[{{idx}}].id'],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
                [
                    'name' => 'Step 2: Fetch the selected item',
                    'request' => ['method' => 'GET', 'path' => '/items/{{itemId}}'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        // Validator and executor agree: the templated path is valid and resolvable.
        $validation = $this->validator->validate($dsl);
        $this->assertTrue($validation->isValid, implode('; ', $validation->errors));

        $this->fakeClient->respondWith(200, ['items' => [['id' => 'a-1'], ['id' => 'b-2'], ['id' => 'c-3']]]);
        $this->fakeClient->respondWith(200, ['id' => 'b-2']);

        $result = $this->executor->execute($dsl, 'http://benchmark:8000');

        $this->assertTrue($result->isSuccess, (string) $result->errorMessage);
        $this->assertSame('b-2', $result->variables['itemId']);
        $sent = $this->fakeClient->sent();
        $this->assertCount(2, $sent);
        $this->assertSame('http://benchmark:8000/items/b-2', $sent[1]->url);
    }

    public function test_extract_path_variable_extracted_earlier_in_sequence_is_interpolated(): void
    {
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Extract path uses variable extracted in a previous step',
            'endpoint' => ['method' => 'GET', 'path' => '/config'],
            'technique' => 'SEQUENCE',
            'source' => 'HYBRID',
            'steps' => [
                [
                    'name' => 'Step 1: Read default index',
                    'request' => ['method' => 'GET', 'path' => '/config'],
                    'extract' => [['var' => 'pos', 'from' => 'body', 'path' => '$.default_index']],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
                [
                    'name' => 'Step 2: Pick entry by extracted index',
                    'request' => ['method' => 'GET', 'path' => '/entries'],
                    'extract' => [['var' => 'entryName', 'from' => 'body', 'path' => '$.entries[{{pos}}].name']],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $this->assertTrue($this->validator->validate($dsl)->isValid);

        $this->fakeClient->respondWith(200, ['default_index' => 2]);
        $this->fakeClient->respondWith(200, ['entries' => [['name' => 'x'], ['name' => 'y'], ['name' => 'z']]]);

        $result = $this->executor->execute($dsl, 'http://benchmark:8000');

        $this->assertTrue($result->isSuccess, (string) $result->errorMessage);
        $this->assertSame('z', $result->variables['entryName']);
    }

    public function test_sequence_halts_immediately_on_extraction_failure_without_partial_commit(): void
    {
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Halt sequence on extraction failure',
            'endpoint' => ['method' => 'POST', 'path' => '/orders'],
            'technique' => 'SEQUENCE',
            'source' => 'HYBRID',
            'steps' => [
                [
                    'name' => 'Step 1: Create Order',
                    'request' => ['method' => 'POST', 'path' => '/orders'],
                    'extract' => [
                        ['var' => 'orderId', 'from' => 'body', 'path' => '$.id'],
                        ['var' => 'missingProp', 'from' => 'body', 'path' => '$.non_existent_field'],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
                [
                    'name' => 'Step 2: Should not be executed',
                    'request' => ['method' => 'POST', 'path' => '/orders/{{orderId}}/pay'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];

        $this->fakeClient->respondWith(200, ['id' => 777]); // Missing non_existent_field

        $result = $this->executor->execute($dsl, 'http://benchmark:8000');

        $this->assertFalse($result->isSuccess);
        $this->assertCount(1, $result->stepResults);
        $this->assertStringContainsString('Không trích xuất được', (string) $result->errorMessage);

        // Ensure orderId was not committed or published when step failed
        $this->assertArrayNotHasKey('orderId', $result->finalVariables);

        // Step 2 was never dispatched
        $this->assertCount(1, $this->fakeClient->sent());
    }

    public function test_sequence_halts_on_transport_failure(): void
    {
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Halt on transport failure',
            'endpoint' => ['method' => 'POST', 'path' => '/orders'],
            'technique' => 'SEQUENCE',
            'source' => 'HYBRID',
            'steps' => [
                [
                    'name' => 'Step 1: Success',
                    'request' => ['method' => 'POST', 'path' => '/orders'],
                    'extract' => [['var' => 'orderId', 'from' => 'body', 'path' => '$.id']],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
                [
                    'name' => 'Step 2: Network failure',
                    'request' => ['method' => 'GET', 'path' => '/orders/{{orderId}}'],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
                [
                    'name' => 'Step 3: Never runs',
                    'request' => ['method' => 'DELETE', 'path' => '/orders/{{orderId}}'],
                    'assertions' => [['type' => 'status', 'equals' => 204]],
                ],
            ],
        ];

        $client = new class implements TargetHttpClient
        {
            public int $callCount = 0;

            public function send(TargetRequest $request): TargetResponse
            {
                $this->callCount++;
                if ($this->callCount === 1) {
                    return new TargetResponse(200, [], '{"id": 123}', 5);
                }

                throw new \RuntimeException('Connection refused by peer');
            }
        };

        $executor = new TestExecutor($client, $this->allowlistService, $this->assertionEngine);
        $result = $executor->execute($dsl, 'http://benchmark:8000');

        $this->assertFalse($result->isSuccess);
        $this->assertCount(2, $result->stepResults);
        $this->assertSame(2, $client->callCount);
        $this->assertStringContainsString('Connection refused by peer', (string) $result->stepResults[1]->errorMessage);
    }

    public function test_sequence_verdict_is_fail_when_step_assertion_fails(): void
    {
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Sequence with failing assertion in step 2',
            'endpoint' => ['method' => 'POST', 'path' => '/orders'],
            'technique' => 'SEQUENCE',
            'source' => 'HYBRID',
            'steps' => [
                [
                    'name' => 'Step 1: Create Order',
                    'request' => ['method' => 'POST', 'path' => '/orders'],
                    'extract' => [['var' => 'orderId', 'from' => 'body', 'path' => '$.id']],
                    'assertions' => [['type' => 'status', 'equals' => 201]],
                ],
                [
                    'name' => 'Step 2: Verify Status (Assertion Fails)',
                    'request' => ['method' => 'GET', 'path' => '/orders/{{orderId}}'],
                    'assertions' => [
                        ['type' => 'status', 'equals' => 200],
                        ['type' => 'json_path', 'path' => '$.status', 'op' => 'eq', 'value' => 'COMPLETED'], // Actual will be PENDING
                    ],
                ],
            ],
        ];

        $this->fakeClient->respondWith(201, ['id' => 888]);
        $this->fakeClient->respondWith(200, ['id' => 888, 'status' => 'PENDING']);

        $result = $this->executor->execute($dsl, 'http://benchmark:8000');

        $this->assertFalse($result->isSuccess);
        $this->assertTrue($result->stepResults[0]->isSuccess);
        $this->assertFalse($result->stepResults[1]->isSuccess);
        $this->assertStringContainsString('assertion không đạt', (string) $result->errorMessage);

        $assertionResults = $result->stepResults[1]->assertionResults;
        $this->assertCount(2, $assertionResults);
        $this->assertTrue($assertionResults[0]->passed); // status 200 passed
        $this->assertFalse($assertionResults[1]->passed); // status eq COMPLETED failed
    }

    public function test_sequence_interpolates_complex_objects_and_arrays_in_steps(): void
    {
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Interpolate complex structured object into subsequent step body',
            'endpoint' => ['method' => 'POST', 'path' => '/profiles'],
            'technique' => 'SEQUENCE',
            'source' => 'HYBRID',
            'steps' => [
                [
                    'name' => 'Step 1: Extract nested object',
                    'request' => ['method' => 'GET', 'path' => '/templates/default'],
                    'extract' => [
                        ['var' => 'configObject', 'from' => 'body', 'path' => '$.settings'],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
                [
                    'name' => 'Step 2: Send extracted object in body',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/profiles',
                        'body' => [
                            'name' => 'Custom Profile',
                            'settings' => '{{configObject}}',
                        ],
                    ],
                    'assertions' => [['type' => 'status', 'equals' => 201]],
                ],
            ],
        ];

        $this->fakeClient->respondWith(200, [
            'settings' => ['theme' => 'dark', 'notifications' => ['email' => true, 'sms' => false]],
        ]);
        $this->fakeClient->respondWith(201, ['id' => 1]);

        $result = $this->executor->execute($dsl, 'http://benchmark:8000');

        $this->assertTrue($result->isSuccess);
        $sent = $this->fakeClient->sent();
        $this->assertCount(2, $sent);

        // Body in Step 2 preserved the exact object structure without stringifying to '[object Object]' or 'Array'
        $expectedBody = [
            'name' => 'Custom Profile',
            'settings' => (object) ['theme' => 'dark', 'notifications' => (object) ['email' => true, 'sms' => false]],
        ];
        $this->assertEquals($expectedBody, $sent[1]->body);
    }
}
