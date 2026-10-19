<?php

namespace Tests\Unit\Services\Generation;

use App\Data\Experiments\AuthFixture;
use App\Data\OpenApi\ParsedOpenApi;
use App\Enums\ApiSpecFormat;
use App\Services\Dsl\DslValidator;
use App\Services\Generation\RuleGenerator;
use App\Services\OpenApi\OpenApiParser;
use PHPUnit\Framework\TestCase;

/**
 * Regression (S10-01 audit r3): RuleGenerator ignored OpenAPI security, so every RULE case of a
 * bearer-protected endpoint asserted 2xx/4xx and received 401 on the clean variant (FAIL),
 * inflating the False Positive Rate and making D14 detection impossible on those endpoints.
 */
class RuleGeneratorSecurityTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $document
     */
    private function parse(array $document): ParsedOpenApi
    {
        $parsed = (new OpenApiParser)->parse(json_encode($document + [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Security regression', 'version' => '1'],
        ], JSON_THROW_ON_ERROR), ApiSpecFormat::Json);
        $this->assertTrue($parsed->isValid, implode('; ', $parsed->errors));

        return $parsed;
    }

    /**
     * @param  list<array<string, list<string>>>|null  $security
     * @return array<string, mixed>
     */
    private function itemsPath(?array $security, bool $with401 = true): array
    {
        $responses = [
            '201' => ['description' => 'Created'],
            '422' => ['description' => 'Invalid'],
        ];
        if ($with401) {
            $responses['401'] = ['description' => 'Missing or invalid bearer token'];
        }
        $operation = [
            'requestBody' => [
                'required' => true,
                'content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'required' => ['name'],
                    'properties' => ['name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20]],
                ]]],
            ],
            'responses' => $responses,
        ];
        if ($security !== null) {
            $operation['security'] = $security;
        }

        return ['/items' => ['post' => $operation]];
    }

    /**
     * @return array<string, mixed>
     */
    private function components(): array
    {
        return ['securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer']]];
    }

    public function test_secured_endpoint_only_gets_a_missing_credentials_case_expecting_401(): void
    {
        $parsed = $this->parse([
            'paths' => $this->itemsPath([['bearerAuth' => []]]),
            'components' => $this->components(),
        ]);

        $cases = (new RuleGenerator)->generateForSpec($parsed);

        $this->assertCount(1, $cases);
        $case = $cases[0];
        $this->assertSame('AUTH', $case['technique']);
        $this->assertSame('RULE', $case['source']);
        $this->assertSame([['type' => 'status', 'equals' => 401]], $case['steps'][0]['assertions']);
        $headers = array_change_key_case($case['steps'][0]['request']['headers'] ?? [], CASE_LOWER);
        $this->assertArrayNotHasKey('authorization', $headers);
        // The request itself is valid, so only the missing credential can explain a rejection.
        $this->assertSame(['name' => $case['steps'][0]['request']['body']['name']], $case['steps'][0]['request']['body']);
        $result = (new DslValidator)->validate($case, $parsed);
        $this->assertTrue($result->isValid, implode('; ', $result->errors));
    }

    public function test_global_security_is_inherited_and_operation_security_empty_list_opts_out(): void
    {
        $paths = $this->itemsPath(null);
        $paths['/public'] = ['get' => ['security' => [], 'responses' => ['200' => ['description' => 'OK']]]];
        $parsed = $this->parse([
            'security' => [['bearerAuth' => []]],
            'paths' => $paths,
            'components' => $this->components(),
        ]);

        $generator = new RuleGenerator;
        $byPath = [];
        foreach ($parsed->endpoints as $endpoint) {
            $byPath[$endpoint->path] = array_column($generator->generate($endpoint), 'technique');
        }

        $this->assertSame(['AUTH'], $byPath['/items']);
        $this->assertSame(['HAPPY_PATH'], $byPath['/public']);
    }

    public function test_optional_security_keeps_the_regular_rule_techniques(): void
    {
        $parsed = $this->parse([
            'paths' => $this->itemsPath([[], ['bearerAuth' => []]]),
            'components' => $this->components(),
        ]);

        $techniques = array_unique(array_column((new RuleGenerator)->generateForSpec($parsed), 'technique'));

        $this->assertContains('HAPPY_PATH', $techniques);
        $this->assertContains('MISSING_REQUIRED', $techniques);
        $this->assertNotContains('AUTH', $techniques);
    }

    public function test_secured_endpoint_without_a_documented_401_gets_no_case(): void
    {
        $parsed = $this->parse([
            'paths' => $this->itemsPath([['bearerAuth' => []]], with401: false),
            'components' => $this->components(),
        ]);

        $this->assertSame([], (new RuleGenerator)->generateForSpec($parsed));
    }

    public function test_benchmark_secured_endpoints_get_only_valid_401_oracles(): void
    {
        $file = dirname(__DIR__, 5).'/benchmark/ecommerce-order/openapi.yaml';
        $this->assertFileExists($file);
        $parsed = (new OpenApiParser)->parse((string) file_get_contents($file), ApiSpecFormat::Yaml);
        $this->assertTrue($parsed->isValid, implode('; ', $parsed->errors));

        $generator = new RuleGenerator;
        $validator = new DslValidator;
        $securedEndpoints = 0;
        foreach ($parsed->endpoints as $endpoint) {
            if ($endpoint->security === null || $endpoint->security === []) {
                continue;
            }
            $securedEndpoints++;
            $cases = $generator->generate($endpoint);
            $this->assertCount(1, $cases, "{$endpoint->method->value} {$endpoint->path}");
            $this->assertSame('AUTH', $cases[0]['technique']);
            $this->assertSame(401, $cases[0]['steps'][0]['assertions'][0]['equals']);
            $result = $validator->validate($cases[0], $parsed);
            $this->assertTrue($result->isValid, implode('; ', $result->errors));
        }
        $this->assertGreaterThan(0, $securedEndpoints);
    }

    /**
     * ADR-0006: with the experiment's auth fixture, secured endpoints get every RULE technique (the
     * executor sends the default credential) plus AUTH cases: no credential and an invalid token
     * (401), and each forbidden role of the operation (403, through the run variable).
     */
    public function test_with_auth_fixture_secured_endpoints_get_every_technique_and_auth_cases(): void
    {
        $file = dirname(__DIR__, 5).'/benchmark/ecommerce-order/openapi.yaml';
        $parsed = (new OpenApiParser)->parse((string) file_get_contents($file), ApiSpecFormat::Yaml);
        $auth = AuthFixture::fromArray([
            'scheme' => 'bearer',
            'default_role' => 'customer',
            'roles' => [['name' => 'customer'], ['name' => 'admin']],
            'operations' => [
                ['method' => 'GET', 'path' => '/admin/orders', 'role' => 'admin', 'forbidden_roles' => ['customer']],
                ['method' => 'POST', 'path' => '/cart/items', 'forbidden_roles' => ['admin']],
            ],
        ]);
        $generator = new RuleGenerator;
        $validator = new DslValidator;

        $byOperation = [];
        foreach ($parsed->endpoints as $endpoint) {
            $cases = $generator->generate($endpoint, [], [], $auth);
            foreach ($cases as $case) {
                $result = $validator->validate($case, $parsed, $auth->variableNames());
                $this->assertTrue($result->isValid, $case['title'].': '.implode('; ', $result->errors));
                if ($case['technique'] !== 'AUTH') {
                    $this->assertArrayNotHasKey('Authorization', $case['steps'][0]['request']['headers'] ?? []);
                }
            }
            $byOperation["{$endpoint->method->value} {$endpoint->path}"] = $cases;
        }

        $cart = $byOperation['POST /cart/items'];
        $techniques = array_unique(array_column($cart, 'technique'));
        $this->assertContains('HAPPY_PATH', $techniques);
        $this->assertContains('BOUNDARY', $techniques);
        $auths = array_values(array_filter($cart, fn (array $c): bool => $c['technique'] === 'AUTH'));
        $this->assertSame([401, 401, 403], array_map(fn (array $c): int => $c['steps'][0]['assertions'][0]['equals'], $auths));
        $this->assertArrayNotHasKey('Authorization', $auths[0]['steps'][0]['request']['headers'] ?? []);
        $this->assertSame(RuleGenerator::INVALID_BEARER, $auths[1]['steps'][0]['request']['headers']['Authorization']);
        $this->assertSame('Bearer {{auth_token_admin}}', $auths[2]['steps'][0]['request']['headers']['Authorization']);
        $this->assertStringContainsString("'admin'", $auths[2]['title']);

        $admin = array_values(array_filter($byOperation['GET /admin/orders'], fn (array $c): bool => $c['technique'] === 'AUTH'));
        $this->assertSame('Bearer {{auth_token_customer}}', $admin[2]['steps'][0]['request']['headers']['Authorization']);

        // The forbidden-role case needs the run variable: plain validation rejects it.
        $this->assertFalse($validator->validate($auths[2], $parsed)->isValid);

        // Public endpoints are unchanged by the fixture.
        $this->assertNotContains('AUTH', array_column($byOperation['GET /products'], 'technique'));
    }
}
