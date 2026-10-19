<?php

namespace Tests\Unit\Services\Target;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Services\Target\FakeTargetHostResolver;
use App\Services\Target\TargetAllowlistService;
use PHPUnit\Framework\TestCase;

class TargetAllowlistServiceTest extends TestCase
{
    private function resolver(): FakeTargetHostResolver
    {
        return new FakeTargetHostResolver(array_fill_keys([
            'localhost', 'benchmark', 'my-service', 'custom-sandbox.internal', 'restricted-target.local',
        ], ['127.0.0.1']));
    }

    public function test_it_allows_default_configured_hosts(): void
    {
        $service = new TargetAllowlistService(['localhost', '127.0.0.1', 'benchmark'], resolver: $this->resolver());

        $this->assertTrue($service->isAllowed('http://localhost'));
        $this->assertTrue($service->isAllowed('http://localhost:8000/api'));
        $this->assertTrue($service->isAllowed('http://127.0.0.1:3000/orders'));
        $this->assertTrue($service->isAllowed('http://benchmark:8002/items'));
        $this->assertTrue($service->isAllowed('benchmark:8002'));
    }

    public function test_it_rejects_hosts_not_in_allowlist(): void
    {
        $service = new TargetAllowlistService(['localhost', '127.0.0.1', 'benchmark'], resolver: $this->resolver());

        $this->assertFalse($service->isAllowed('http://evil.com/api'));
        $this->assertFalse($service->isAllowed('https://google.com'));
        $this->assertFalse($service->isAllowed('http://169.254.169.254/latest/meta-data'));
        $this->assertFalse($service->isAllowed('http://192.168.1.100:8080'));
        $this->assertFalse($service->isAllowed(''));
    }

    public function test_it_respects_explicit_port_in_configuration(): void
    {
        $service = new TargetAllowlistService(['my-service:8080'], resolver: $this->resolver());

        $this->assertTrue($service->isAllowed('http://my-service:8080/test'));
        $this->assertFalse($service->isAllowed('http://my-service:9000/test'));
        $this->assertFalse($service->isAllowed('http://my-service/test'));
    }

    public function test_it_allows_hosts_from_database_allowed_targets_table(): void
    {
        $dbTargets = [
            'custom-sandbox.internal' => [['port' => null]],
            'restricted-target.local' => [['port' => 8443]],
        ];

        $service = new TargetAllowlistService(
            configuredHosts: [],
            dbTargetsResolver: fn (string $host) => $dbTargets[$host] ?? [],
            resolver: $this->resolver(),
        );

        $this->assertTrue($service->isAllowed('http://custom-sandbox.internal:9999/api'));
        $this->assertTrue($service->isAllowed('https://restricted-target.local:8443/ping'));
        $this->assertFalse($service->isAllowed('https://restricted-target.local:8080/ping'));
        $this->assertFalse($service->isAllowed('http://unknown-host.local'));
    }

    public function test_assert_allowed_throws_target_not_allowed_exception(): void
    {
        $service = new TargetAllowlistService(['localhost'], resolver: $this->resolver());

        try {
            $service->assertAllowed('http://untrusted-host.com/admin');
            $this->fail('Expected ApiException was not thrown.');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::TargetNotAllowed, $e->errorCode);
            $this->assertSame('TARGET_NOT_ALLOWED', $e->errorCode->value);
            $this->assertSame(422, $e->errorCode->httpStatus());
            $this->assertStringContainsString('untrusted-host.com', $e->getMessage());
        }
    }

    public function test_it_rejects_non_http_schemes_before_resolving(): void
    {
        $resolver = new FakeTargetHostResolver(['localhost' => ['127.0.0.1']]);
        $service = new TargetAllowlistService(['localhost'], resolver: $resolver);

        foreach (['gopher', 'ftp', 'file'] as $scheme) {
            $this->assertFalse($service->isAllowed($scheme.'://localhost'));
        }

        $this->assertSame([], $resolver->resolvedHosts);
    }

    public function test_it_requires_resolved_addresses_and_exposes_them_for_pinning(): void
    {
        $service = new TargetAllowlistService(['benchmark'], resolver: new FakeTargetHostResolver);
        $this->assertFalse($service->isAllowed('http://benchmark'));

        $service = new TargetAllowlistService(['benchmark'], resolver: $this->resolver());
        $this->assertSame(['127.0.0.1'], $service->resolveAllowedAddresses('http://benchmark:8002/items'));
    }
}
