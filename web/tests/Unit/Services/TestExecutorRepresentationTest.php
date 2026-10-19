<?php

namespace Tests\Unit\Services;

use App\Data\TargetRequest;
use App\Services\Execution\TargetAllowlistValidator;
use App\Services\Execution\TestExecutor;
use App\Services\Target\FakeTargetHostResolver;
use App\Services\Target\FakeTargetHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TestExecutorRepresentationTest extends TestCase
{
    private function allowlist(): TargetAllowlistValidator
    {
        return new TargetAllowlistValidator(['localhost'], new FakeTargetHostResolver(['localhost' => ['127.0.0.1']]));
    }

    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    private function executeAndGetSentHeaders(array $variables, array $headers): array
    {
        $client = new FakeTargetHttpClient;
        $client->respondWith(200, ['status' => 'ok']);

        $executor = new TestExecutor($client, $this->allowlist());

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Header interpolation test',
            'variables' => $variables,
            'steps' => [
                [
                    'name' => 'Step 1',
                    'request' => [
                        'method' => 'GET',
                        'path' => '/api/test',
                        'headers' => $headers,
                    ],
                ],
            ],
        ];

        $result = $executor->execute($dsl, 'http://localhost');
        $this->assertTrue($result->isSuccess, 'Expected execution to succeed');
        $this->assertCount(1, $client->sent());

        /** @var TargetRequest $sentRequest */
        $sentRequest = $client->sent()[0];

        return $sentRequest->headers;
    }

    public function test_exact_placeholder_header_with_object_serializes_to_json(): void
    {
        $headers = $this->executeAndGetSentHeaders(
            variables: ['context' => (object) ['tenant' => 'a']],
            headers: ['X-Context' => '{{context}}']
        );

        $this->assertSame('{"tenant":"a"}', $headers['X-Context']);
    }

    public function test_exact_placeholder_header_with_empty_object_serializes_to_json(): void
    {
        $headers = $this->executeAndGetSentHeaders(
            variables: ['context' => (object) []],
            headers: ['X-Context' => '{{context}}']
        );

        $this->assertSame('{}', $headers['X-Context']);
    }

    public function test_exact_placeholder_header_with_numeric_keys_object_serializes_to_json(): void
    {
        $headers = $this->executeAndGetSentHeaders(
            variables: ['context' => (object) ['0' => 'a', '1' => 'b']],
            headers: ['X-Context' => '{{context}}']
        );

        $this->assertSame('{"0":"a","1":"b"}', $headers['X-Context']);
    }

    public function test_exact_placeholder_header_with_array_serializes_to_json(): void
    {
        $headers = $this->executeAndGetSentHeaders(
            variables: ['context' => ['a', 'b']],
            headers: ['X-Context' => '{{context}}']
        );

        $this->assertSame('["a","b"]', $headers['X-Context']);
    }

    public function test_header_serialization_failure_is_reported_as_failed_step(): void
    {
        $context = (object) ['tenant' => "\xB1"];
        $client = new FakeTargetHttpClient;
        $executor = new TestExecutor($client, $this->allowlist());

        $result = $executor->execute([
            'dsl_version' => '1.0',
            'title' => 'Unserializable header value',
            'variables' => ['context' => $context],
            'steps' => [[
                'name' => 'Build request',
                'request' => [
                    'method' => 'GET',
                    'path' => '/api/test',
                    'headers' => ['X-Context' => '{{context}}'],
                ],
            ]],
        ], 'http://localhost');

        $this->assertFalse($result->isSuccess);
        $this->assertCount(1, $result->stepResults);
        $this->assertStringContainsString('Malformed UTF-8', (string) $result->stepResults[0]->errorMessage);
        $this->assertCount(0, $client->sent(), 'A request with an unserializable header must not be sent.');
    }

    public function test_exact_placeholder_header_with_scalar_and_null_values(): void
    {
        $headers = $this->executeAndGetSentHeaders(
            variables: [
                'tenant' => 'tenant-123',
                'id' => 42,
                'opt' => null,
            ],
            headers: [
                'X-Tenant' => '{{tenant}}',
                'X-Id' => '{{id}}',
                'X-Opt' => '{{opt}}',
            ]
        );

        $this->assertSame('tenant-123', $headers['X-Tenant']);
        $this->assertSame('42', $headers['X-Id']);
        $this->assertSame('null', $headers['X-Opt']);
    }

    public function test_embedded_placeholder_header_serializes_object_and_array_to_json(): void
    {
        $headers = $this->executeAndGetSentHeaders(
            variables: [
                'context' => (object) ['tenant' => 'a'],
                'tags' => ['prod', 'v1'],
            ],
            headers: [
                'X-Context' => 'Context {{context}}',
                'X-Tags' => 'Tags: {{tags}}',
            ]
        );

        $this->assertSame('Context {"tenant":"a"}', $headers['X-Context']);
        $this->assertSame('Tags: ["prod","v1"]', $headers['X-Tags']);
    }

    public function test_sequence_extraction_into_header_with_object_and_array(): void
    {
        $client = new FakeTargetHttpClient;
        // Step 1 response returns JSON containing an object and an array
        $client->respondWith(200, [
            'context' => ['tenant' => 'alpha'],
            'tags' => ['tag1', 'tag2'],
        ]);
        // Step 2 response
        $client->respondWith(200, ['status' => 'verified']);

        $executor = new TestExecutor($client, $this->allowlist());

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Sequence Header Test',
            'steps' => [
                [
                    'name' => 'Fetch context',
                    'request' => ['method' => 'GET', 'path' => '/bootstrap'],
                    'extract' => [
                        ['var' => 'ctx', 'from' => 'body', 'path' => '$.context'],
                        ['var' => 'tags', 'from' => 'body', 'path' => '$.tags'],
                    ],
                ],
                [
                    'name' => 'Use context in header',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/action',
                        'headers' => [
                            'X-Context' => '{{ctx}}',
                            'X-Tags' => '{{tags}}',
                        ],
                    ],
                ],
            ],
        ];

        $result = $executor->execute($dsl, 'http://localhost');

        $this->assertTrue($result->isSuccess);
        $this->assertCount(2, $client->sent());

        /** @var TargetRequest $secondRequest */
        $secondRequest = $client->sent()[1];

        $this->assertSame('{"tenant":"alpha"}', $secondRequest->headers['X-Context']);
        $this->assertSame('["tag1","tag2"]', $secondRequest->headers['X-Tags']);
    }

    public function test_array_dsl_with_stdclass_variables_resolves_body_and_final_variables(): void
    {
        $client = new FakeTargetHttpClient;
        $client->respondWith(200, ['success' => true]);

        $executor = new TestExecutor($client, $this->allowlist());

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Array DSL with stdClass variables container',
            'variables' => (object) [
                'context' => (object) ['tenant' => 'a'],
            ],
            'steps' => [
                [
                    'name' => 'Create resource',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/items',
                        'body' => '{{context}}',
                    ],
                ],
            ],
        ];

        $result = $executor->execute($dsl, 'http://localhost');

        $this->assertTrue($result->isSuccess);
        $this->assertCount(1, $client->sent());

        /** @var TargetRequest $sentRequest */
        $sentRequest = $client->sent()[0];

        // Ensure body is interpolated as typed stdClass, not string literal '{{context}}'
        $this->assertInstanceOf(\stdClass::class, $sentRequest->body);
        $this->assertSame('a', $sentRequest->body->tenant);

        // Ensure final variables retains the variable
        $this->assertArrayHasKey('context', $result->finalVariables);
        $this->assertInstanceOf(\stdClass::class, $result->finalVariables['context']);
        $this->assertSame('a', $result->finalVariables['context']->tenant);
    }

    public function test_body_preserves_typed_object_and_array_without_unwanted_stringification(): void
    {
        $client = new FakeTargetHttpClient;
        $client->respondWith(200, ['ok' => true]);
        $client->respondWith(200, ['ok' => true]);

        $executor = new TestExecutor($client, $this->allowlist());

        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Typed body preservation',
            'variables' => [
                'obj_payload' => (object) ['key' => 'val'],
                'arr_payload' => [1, 2, 3],
            ],
            'steps' => [
                [
                    'name' => 'Send object body',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/obj',
                        'body' => '{{obj_payload}}',
                    ],
                ],
                [
                    'name' => 'Send array body',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/arr',
                        'body' => '{{arr_payload}}',
                    ],
                ],
            ],
        ];

        $result = $executor->execute($dsl, 'http://localhost');

        $this->assertTrue($result->isSuccess);
        $this->assertCount(2, $client->sent());

        $this->assertInstanceOf(\stdClass::class, $client->sent()[0]->body);
        $this->assertSame('val', $client->sent()[0]->body->key);

        $this->assertSame([1, 2, 3], $client->sent()[1]->body);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function dslEquivalenceMatrixCases(): array
    {
        $testCases = [];
        $inputForms = ['array_with_stdclass_vars', 'json_string', 'full_stdclass'];
        $varValues = [
            'object' => (object) ['tenant' => 'a'],
            'empty_object' => (object) [],
            'string' => 'hello',
            'null' => null,
        ];

        foreach ($inputForms as $form) {
            foreach ($varValues as $type => $val) {
                $testCases["{$form}_{$type}"] = [$form, $val];
            }
        }

        return $testCases;
    }

    #[DataProvider('dslEquivalenceMatrixCases')]
    public function test_input_forms_equivalence_matrix(string $inputForm, mixed $contextValue): void
    {
        $client = new FakeTargetHttpClient;
        $client->respondWith(200, ['ok' => true]);

        $executor = new TestExecutor($client, $this->allowlist());

        $baseDslArray = [
            'dsl_version' => '1.0',
            'title' => 'Matrix test',
            'variables' => (object) ['context' => $contextValue],
            'steps' => [
                [
                    'name' => 'Step 1',
                    'request' => [
                        'method' => 'POST',
                        'path' => '/test',
                        'headers' => ['X-Context' => '{{context}}'],
                        'body' => '{{context}}',
                    ],
                ],
            ],
        ];

        $dslInput = match ($inputForm) {
            'array_with_stdclass_vars' => $baseDslArray,
            'json_string' => (string) json_encode($baseDslArray),
            'full_stdclass' => json_decode((string) json_encode($baseDslArray), false),
            default => throw new \InvalidArgumentException("Unknown input form: {$inputForm}"),
        };

        $result = $executor->execute($dslInput, 'http://localhost');

        $this->assertTrue($result->isSuccess);
        $this->assertCount(1, $client->sent());

        /** @var TargetRequest $sentRequest */
        $sentRequest = $client->sent()[0];

        // Final variables must contain 'context'
        $this->assertArrayHasKey('context', $result->finalVariables);

        // Header must be properly stringified/serialized (never throws Error)
        $this->assertArrayHasKey('X-Context', $sentRequest->headers);
        $this->assertNotEmpty($sentRequest->headers['X-Context']);

        // Body must NOT be the literal un-interpolated string '{{context}}'
        $this->assertNotSame('{{context}}', $sentRequest->body);
    }
}
