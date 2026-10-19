<?php

namespace Tests\Unit\Services\Experiments;

use App\Data\Experiments\AuthFixture;
use App\Exceptions\ApiException;
use App\Services\Benchmark\GroundTruthLoader;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0006: auth fixture of ground-truth.yaml (snapshot with tokens, public form without).
 */
class AuthFixtureTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function raw(): array
    {
        return [
            'scheme' => 'bearer',
            'default_role' => 'customer',
            'roles' => [
                'customer' => ['token' => 'sandbox-a', 'description' => 'CUSTOMER alice'],
                'admin' => ['token' => 'sandbox-b', 'description' => 'ADMIN'],
            ],
            'operations' => [
                ['method' => 'get', 'path' => '/admin/orders', 'role' => 'admin', 'forbidden_roles' => ['customer']],
                ['method' => 'POST', 'path' => '/cart/items', 'forbidden_roles' => ['admin']],
            ],
        ];
    }

    public function test_parses_snapshot_form_and_exposes_roles_variables_and_operations(): void
    {
        $auth = AuthFixture::fromArray($this->raw(), requireTokens: true);

        $this->assertSame(['auth_token_customer', 'auth_token_admin'], $auth->variableNames());
        $this->assertSame(['auth_token_customer' => 'sandbox-a', 'auth_token_admin' => 'sandbox-b'], $auth->variables());
        $this->assertSame('admin', $auth->roleFor('GET', '/admin/orders'));
        $this->assertSame('customer', $auth->roleFor('POST', '/cart/items'));
        $this->assertSame('customer', $auth->roleFor('GET', '/unknown'));
        $this->assertSame(['customer'], $auth->forbiddenRolesFor('get', '/admin/orders'));
        $this->assertSame(['admin'], $auth->forbiddenRolesFor('POST', '/cart/items'));
        $this->assertSame('Bearer {{auth_token_admin}}', AuthFixture::headerValue('admin'));
        $this->assertTrue($auth->hasTokens());
        $this->assertSame('GET', $auth->toArray()['operations'][0]['method']);
    }

    public function test_public_form_has_no_token_and_round_trips(): void
    {
        $public = AuthFixture::fromArray($this->raw())->toPublicArray();

        $this->assertStringNotContainsString('sandbox-', (string) json_encode($public));
        $this->assertSame([['name' => 'customer', 'description' => 'CUSTOMER alice'], ['name' => 'admin', 'description' => 'ADMIN']], $public['roles']);

        $again = AuthFixture::fromArray($public);
        $this->assertFalse($again->hasTokens());
        $this->assertSame([], $again->variables());
        $this->assertSame(['auth_token_customer', 'auth_token_admin'], $again->variableNames());
        $this->assertSame($public, $again->toPublicArray());

        $this->expectException(InvalidArgumentException::class);
        AuthFixture::fromArray($public, requireTokens: true);
    }

    /**
     * @return array<string, array{0: callable(array<string, mixed>): array<string, mixed>}>
     */
    public static function invalidFixtures(): array
    {
        return [
            'unsupported scheme' => [fn (array $r): array => ['scheme' => 'basic'] + $r],
            'unknown default role' => [fn (array $r): array => ['default_role' => 'ghost'] + $r],
            'bad role name' => [function (array $r): array {
                $r['roles']['Bad-Role'] = ['token' => 'x'];

                return $r;
            }],
            'no roles' => [fn (array $r): array => ['roles' => []] + $r],
            'unknown operation role' => [function (array $r): array {
                $r['operations'][0]['role'] = 'ghost';

                return $r;
            }],
            'forbidden role is the operation role' => [function (array $r): array {
                $r['operations'][0]['forbidden_roles'] = ['admin'];

                return $r;
            }],
            'operation without path' => [function (array $r): array {
                $r['operations'][] = ['method' => 'GET'];

                return $r;
            }],
        ];
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    #[DataProvider('invalidFixtures')]
    public function test_rejects_inconsistent_fixtures(callable $mutate): void
    {
        $this->assertNull(AuthFixture::tryFrom($mutate($this->raw())));
        $this->expectException(InvalidArgumentException::class);
        AuthFixture::fromArray($mutate($this->raw()));
    }

    public function test_try_from_null_means_no_credentials(): void
    {
        $this->assertNull(AuthFixture::tryFrom(null));
    }

    public function test_real_ground_truth_files_declare_auth_and_business_rules(): void
    {
        $loader = new GroundTruthLoader(dirname(__DIR__, 5).'/benchmark');

        $order = $loader->load('ecommerce-order');
        $this->assertNotNull($order->auth);
        $this->assertSame('customer', $order->auth->defaultRole);
        $this->assertSame('customer-alice-token', $order->auth->variables()['auth_token_customer']);
        $this->assertSame(['customer'], $order->auth->forbiddenRolesFor('GET', '/admin/orders'));
        $this->assertSame('BR-EO-001', $order->businessRules[0]['code']);
        $this->assertSame('GET', $order->businessRules[0]['method']);
        $this->assertCount(12, $order->businessRules);

        $booking = $loader->load('appointment-booking');
        $this->assertSame('patient', $booking->auth?->defaultRole);
        $this->assertCount(4, $booking->auth->roles);

        $store = $loader->load('simple-store');
        $this->assertNull($store->auth);
        $this->assertCount(8, $store->businessRules);

        foreach ([$order, $booking, $store] as $groundTruth) {
            foreach ($groundTruth->businessRules as $rule) {
                $this->assertDoesNotMatchRegularExpression('/\bBUG[-_]?\d{3}\b/i', $rule['description']);
            }
        }
    }

    public function test_loader_rejects_invalid_auth_and_business_rules(): void
    {
        $loader = new GroundTruthLoader('/nonexistent');
        $base = "benchmark: demo\nbase_url: http://demo:8000\nbugs: []\n";

        foreach ([
            "auth:\n  scheme: bearer\n  default_role: a\n  roles:\n    a: { description: no token }\n" => 'thiếu token',
            "business_rules:\n  - { code: BR-1 }\n" => 'business_rules[0]',
            "business_rules:\n  - { code: BR-1, description: x }\n  - { code: BR-1, description: y }\n" => 'bị trùng',
            "business_rules:\n  - { code: BR-1, description: x, method: FETCH, path: /a }\n" => 'method/path',
        ] as $yaml => $message) {
            try {
                $loader->parse($base.$yaml, 'demo');
                $this->fail("Accepted invalid fixture: {$yaml}");
            } catch (ApiException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        $parsed = $loader->parse($base."business_rules:\n  - { code: BR-1, description: global rule }\n", 'demo');
        $this->assertSame([['code' => 'BR-1', 'description' => 'global rule', 'method' => null, 'path' => null]], $parsed->businessRules);
        $this->assertNull($parsed->auth);
    }
}
