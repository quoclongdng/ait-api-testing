<?php

namespace Tests\Unit\Services\Generation;

use App\Services\Generation\RuleGenerator;
use PHPUnit\Framework\TestCase;

/**
 * S11-01: fields that look unique (code, slug, sku, email) get a fresh value only on operations
 * that write them (POST declaring 201/2XX, PUT, PATCH). Lookup operations honour the declared
 * `example` / `examples`; a declared `enum` is honoured everywhere.
 */
class RuleGeneratorUniqueFieldExamplesTest extends TestCase
{
    /**
     * @param  array<int|string, mixed>  $responses
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function endpoint(string $method, string $path, array $responses, array $properties): array
    {
        return [
            'method' => $method,
            'path' => $path,
            'parameters' => [],
            'request_body' => [
                'required' => true,
                'content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'required' => array_keys($properties),
                    'properties' => $properties,
                ]]],
            ],
            'responses' => $responses,
        ];
    }

    /**
     * @param  array<string, mixed>  $endpoint
     * @return array<string, mixed>
     */
    private function happyBody(array $endpoint): array
    {
        $cases = (new RuleGenerator)->generateHappyPath($endpoint);
        $this->assertNotEmpty($cases);

        return $cases[0]['steps'][0]['request']['body'];
    }

    public function test_lookup_post_uses_the_declared_example_of_a_code_field(): void
    {
        $body = $this->happyBody($this->endpoint('POST', '/coupons/apply', ['200' => ['description' => 'ok'], '404' => ['description' => 'nf']], [
            'code' => ['type' => 'string', 'pattern' => '^[A-Z0-9]+$', 'examples' => ['SAVE10']],
            'email' => ['type' => 'string', 'format' => 'email', 'example' => 'alice@example.test'],
        ]));

        $this->assertSame('SAVE10', $body['code']);
        $this->assertSame('alice@example.test', $body['email']);
    }

    public function test_create_post_keeps_fresh_values_for_unique_fields(): void
    {
        $body = $this->happyBody($this->endpoint('POST', '/categories', ['201' => ['description' => 'created'], '409' => ['description' => 'dup']], [
            'slug' => ['type' => 'string', 'pattern' => '^[a-z0-9-]+$', 'examples' => ['electronics']],
            'code' => ['type' => 'string', 'example' => 'EXISTING'],
        ]));

        $this->assertNotSame('electronics', $body['slug']);
        $this->assertNotSame('EXISTING', $body['code']);
    }

    public function test_update_operations_keep_fresh_values_for_unique_fields(): void
    {
        foreach (['PUT', 'PATCH'] as $method) {
            $body = $this->happyBody($this->endpoint($method, '/products', ['200' => ['description' => 'ok']], [
                'sku' => ['type' => 'string', 'pattern' => '^[A-Z0-9_-]+$', 'examples' => ['PROD-001']],
            ]));

            $this->assertNotSame('PROD-001', $body['sku'], $method);
        }
    }

    public function test_enum_is_honoured_even_on_create_operations(): void
    {
        $body = $this->happyBody($this->endpoint('POST', '/vouchers', ['201' => ['description' => 'created']], [
            'code' => ['type' => 'string', 'enum' => ['SAVE10']],
        ]));

        $this->assertSame('SAVE10', $body['code']);
    }

    public function test_write_detection(): void
    {
        $generator = new RuleGenerator;

        $this->assertTrue($generator->operationWritesUniqueFields(['method' => 'POST', 'responses' => ['201' => []]]));
        $this->assertTrue($generator->operationWritesUniqueFields(['method' => 'POST', 'responses' => ['2XX' => []]]));
        $this->assertTrue($generator->operationWritesUniqueFields(['method' => 'PUT', 'responses' => ['200' => []]]));
        $this->assertTrue($generator->operationWritesUniqueFields(['method' => 'PATCH', 'responses' => []]));
        $this->assertFalse($generator->operationWritesUniqueFields(['method' => 'POST', 'responses' => ['200' => []]]));
        $this->assertFalse($generator->operationWritesUniqueFields(['method' => 'GET', 'responses' => ['200' => []]]));
        $this->assertFalse($generator->operationWritesUniqueFields(['method' => 'DELETE', 'responses' => ['204' => []]]));
    }
}
