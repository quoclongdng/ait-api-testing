<?php

namespace Tests\Unit\Services\Execution;

use App\Data\Experiments\AuthFixture;
use App\Enums\HttpMethod;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Services\Execution\CredentialInjector;
use stdClass;
use Tests\TestCase;

/**
 * ADR-0006 §3: one default-credential rule for every strategy.
 */
class CredentialInjectorTest extends TestCase
{
    private function auth(): AuthFixture
    {
        return AuthFixture::fromArray([
            'scheme' => 'bearer',
            'default_role' => 'customer',
            'roles' => ['customer' => ['token' => 't1'], 'admin' => ['token' => 't2']],
            'operations' => [['method' => 'GET', 'path' => '/admin/orders', 'role' => 'admin']],
        ], requireTokens: true);
    }

    private function spec(): ApiSpec
    {
        $secured = [['bearerAuth' => []]];
        $spec = new ApiSpec;
        $spec->setRelation('endpoints', collect([
            new Endpoint(['method' => HttpMethod::Get, 'path' => '/products', 'security' => []]),
            new Endpoint(['method' => HttpMethod::Get, 'path' => '/orders/{order_id}', 'security' => $secured]),
            new Endpoint(['method' => HttpMethod::Post, 'path' => '/orders', 'security' => $secured]),
            new Endpoint(['method' => HttpMethod::Get, 'path' => '/admin/orders', 'security' => $secured]),
            new Endpoint(['method' => HttpMethod::Get, 'path' => '/optional', 'security' => [[], ['bearerAuth' => []]]]),
        ]));

        return $spec;
    }

    /**
     * @param  list<array<string, mixed>>  $requests
     * @return array<string, mixed>
     */
    private function dsl(string $technique, array $requests): array
    {
        return [
            'dsl_version' => '1.0',
            'title' => 'case',
            'endpoint' => ['method' => $requests[0]['method'], 'path' => $requests[0]['path']],
            'technique' => $technique,
            'source' => 'LLM',
            'steps' => array_map(fn (array $request): array => ['name' => 's', 'request' => $request, 'assertions' => [['type' => 'status', 'equals' => 200]]], $requests),
        ];
    }

    public function test_adds_the_role_of_each_secured_operation_and_leaves_public_ones(): void
    {
        $dsl = $this->dsl('SEQUENCE', [
            ['method' => 'POST', 'path' => '/orders', 'headers' => ['Content-Type' => 'application/json'], 'body' => []],
            ['method' => 'GET', 'path' => '/orders/{order_id}', 'path_params' => ['order_id' => '{{id}}']],
            ['method' => 'GET', 'path' => '/admin/orders'],
            ['method' => 'GET', 'path' => '/products'],
            ['method' => 'GET', 'path' => '/optional'],
            ['method' => 'GET', 'path' => '/orders/902'],
        ]);

        $result = (new CredentialInjector)->apply($dsl, $this->auth(), $this->spec());
        $this->assertIsArray($result);
        $headers = array_map(fn (array $step): array => $step['request']['headers'] ?? [], $result['steps']);

        $this->assertSame(['Content-Type' => 'application/json', 'Authorization' => 'Bearer {{auth_token_customer}}'], $headers[0]);
        $this->assertSame(['Authorization' => 'Bearer {{auth_token_customer}}'], $headers[1]);
        $this->assertSame(['Authorization' => 'Bearer {{auth_token_admin}}'], $headers[2]);
        $this->assertSame([], $headers[3]);
        $this->assertSame([], $headers[4], 'authentication is optional ({} requirement)');
        $this->assertSame(['Authorization' => 'Bearer {{auth_token_customer}}'], $headers[5], 'concrete path matches its template');
        $this->assertArrayNotHasKey('headers', $dsl['steps'][1]['request'], 'input is not modified');
    }

    public function test_literal_path_wins_over_a_templated_path_listed_first(): void
    {
        $secured = [['bearerAuth' => []]];
        $spec = new ApiSpec;
        // The templated operations come first in the spec: they must not capture the literal paths.
        $spec->setRelation('endpoints', collect([
            new Endpoint(['method' => HttpMethod::Get, 'path' => '/orders/{order_id}', 'security' => $secured]),
            new Endpoint(['method' => HttpMethod::Get, 'path' => '/{scope}/{order_id}', 'security' => $secured]),
            new Endpoint(['method' => HttpMethod::Get, 'path' => '/orders/mine', 'security' => $secured]),
            new Endpoint(['method' => HttpMethod::Get, 'path' => '/admin/orders', 'security' => $secured]),
        ]));
        $auth = AuthFixture::fromArray([
            'scheme' => 'bearer',
            'default_role' => 'customer',
            'roles' => ['customer' => ['token' => 't1'], 'admin' => ['token' => 't2'], 'auditor' => ['token' => 't3']],
            'operations' => [
                ['method' => 'GET', 'path' => '/orders/mine', 'role' => 'auditor'],
                ['method' => 'GET', 'path' => '/{scope}/{order_id}', 'role' => 'admin'],
            ],
        ], requireTokens: true);
        $dsl = $this->dsl('SEQUENCE', [
            ['method' => 'GET', 'path' => '/orders/mine'],
            ['method' => 'GET', 'path' => '/orders/{order_id}', 'path_params' => ['order_id' => 7]],
            ['method' => 'GET', 'path' => '/orders/7'],
            ['method' => 'GET', 'path' => '/admin/orders'],
            ['method' => 'GET', 'path' => '/carts/7'],
        ]);

        $result = (new CredentialInjector)->apply($dsl, $auth, $spec);
        $this->assertIsArray($result);
        $headers = array_map(fn (array $step): string => $step['request']['headers']['Authorization'] ?? '', $result['steps']);

        $this->assertSame([
            'Bearer {{auth_token_auditor}}',   // literal /orders/mine, although /orders/{order_id} is listed first
            'Bearer {{auth_token_customer}}',  // the template itself
            'Bearer {{auth_token_customer}}',  // /orders/{order_id} (1 parameter) beats /{scope}/{order_id} (2)
            'Bearer {{auth_token_customer}}',  // literal /admin/orders (no role in this fixture: default)
            'Bearer {{auth_token_admin}}',     // only /{scope}/{order_id} matches
        ], $headers);
    }

    public function test_explicit_header_wins_case_insensitively(): void
    {
        $dsl = $this->dsl('SEQUENCE', [
            ['method' => 'GET', 'path' => '/orders/{order_id}', 'path_params' => ['order_id' => 1], 'headers' => ['authorization' => 'Bearer {{auth_token_admin}}']],
        ]);

        $result = (new CredentialInjector)->apply($dsl, $this->auth(), $this->spec());

        $this->assertSame($dsl, $result);
    }

    public function test_auth_cases_are_never_modified(): void
    {
        $dsl = $this->dsl('AUTH', [['method' => 'GET', 'path' => '/admin/orders']]);

        $this->assertSame($dsl, (new CredentialInjector)->apply($dsl, $this->auth(), $this->spec()));
    }

    public function test_object_dsl_keeps_empty_objects_and_is_copied(): void
    {
        $dsl = json_decode((string) json_encode($this->dsl('HAPPY_PATH', [
            ['method' => 'POST', 'path' => '/orders', 'body' => new stdClass, 'query' => new stdClass],
        ])), false);

        $result = (new CredentialInjector)->apply($dsl, $this->auth(), $this->spec());

        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertSame('{}', json_encode($result->steps[0]->request->body));
        $this->assertSame('Bearer {{auth_token_customer}}', $result->steps[0]->request->headers->Authorization);
        $this->assertFalse(isset($dsl->steps[0]->request->headers), 'input object is not modified');
    }

    public function test_spec_without_secured_operation_changes_nothing(): void
    {
        $spec = new ApiSpec;
        $spec->setRelation('endpoints', collect([new Endpoint(['method' => HttpMethod::Get, 'path' => '/products', 'security' => null])]));
        $dsl = $this->dsl('HAPPY_PATH', [['method' => 'GET', 'path' => '/products']]);

        $this->assertSame($dsl, (new CredentialInjector)->apply($dsl, $this->auth(), $spec));
        $this->assertSame($dsl, (new CredentialInjector)->apply($dsl, $this->auth(), null));
    }
}
