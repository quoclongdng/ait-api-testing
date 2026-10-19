<?php

namespace Tests\Unit\Services\Execution;

use App\Data\TargetResponse;
use App\Services\Execution\AssertionEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regressions found by the S10-01 audit of the web engine.
 */
class AssertionEngineAuditRegressionTest extends TestCase
{
    /**
     * @param  array<string, list<string>>  $headers
     */
    private function response(string $body, array $headers = ['Content-Type' => ['application/json']]): TargetResponse
    {
        return new TargetResponse(200, $headers, $body, 5);
    }

    /** @return array<string, array{0: string}> */
    public static function orderingOperators(): array
    {
        return ['gt' => ['gt'], 'gte' => ['gte'], 'lt' => ['lt'], 'lte' => ['lte']];
    }

    /**
     * A numeric string in the response is a schema error, not a number.
     */
    #[DataProvider('orderingOperators')]
    public function test_ordering_operators_reject_numeric_strings_in_the_response(string $op): void
    {
        $value = in_array($op, ['gt', 'gte'], true) ? 0 : 100;
        $result = (new AssertionEngine)->assert(
            ['type' => 'json_path', 'path' => '$.price', 'op' => $op, 'value' => $value],
            $this->response('{"price":"10"}'),
        );

        $this->assertFalse($result->passed);
    }

    #[DataProvider('orderingOperators')]
    public function test_ordering_operators_still_compare_json_numbers_and_interpolated_strings(string $op): void
    {
        $engine = new AssertionEngine;
        $value = in_array($op, ['gt', 'gte'], true) ? 0 : 100;

        $this->assertTrue($engine->assert(
            ['type' => 'json_path', 'path' => '$.price', 'op' => $op, 'value' => $value],
            $this->response('{"price":10.5}'),
        )->passed);
        $this->assertTrue($engine->assert(
            ['type' => 'json_path', 'path' => '$.price', 'op' => $op, 'value' => '{{limit}}'],
            $this->response('{"price":10}'),
            variables: ['limit' => (string) $value],
        )->passed);
    }

    /**
     * `items: {}` decoded associatively becomes `items: []`, which is not a valid schema.
     */
    public function test_empty_items_schema_from_associative_decoding_means_any_item(): void
    {
        $schema = json_decode('{"type":"object","properties":{"a":{"type":"array","items":{}}}}', true);

        $result = (new AssertionEngine)->assert(
            ['type' => 'json_schema', 'schema' => $schema],
            $this->response('{"a":[1,"x",{"b":null}]}'),
        );

        $this->assertTrue($result->passed, (string) $result->message);
    }

    public function test_tuple_items_list_is_still_preserved(): void
    {
        $converted = (new AssertionEngine)->convertOpenApiSchemaArray(['type' => 'array', 'items' => [['type' => 'string']]]);

        $this->assertIsArray($converted['items']);
        $this->assertCount(1, $converted['items']);
    }

    public function test_header_assertion_on_credential_header_masks_values(): void
    {
        $engine = new AssertionEngine;
        $response = $this->response('{}', ['Set-Cookie' => ['session=abc-secret; HttpOnly']]);

        $passed = $engine->assert(['type' => 'header', 'name' => 'set-cookie', 'op' => 'contains', 'value' => 'session='], $response);
        $this->assertTrue($passed->passed);
        $this->assertSame('***', $passed->actual);

        $failed = $engine->assert(['type' => 'header', 'name' => 'Set-Cookie', 'op' => 'eq', 'value' => 'session=other'], $response);
        $this->assertFalse($failed->passed);
        $this->assertSame('***', $failed->actual);
        $this->assertSame('***', $failed->expected);
        $this->assertStringNotContainsString('abc-secret', (string) json_encode($failed->toArray()));

        $plain = $engine->assert(['type' => 'header', 'name' => 'Content-Type', 'op' => 'eq', 'value' => 'text/plain'], $this->response('{}'));
        $this->assertSame('application/json', $plain->actual);
    }
}
