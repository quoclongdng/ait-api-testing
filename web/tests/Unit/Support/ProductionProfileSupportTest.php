<?php

namespace Tests\Unit\Support;

use App\Support\ProductionHardening;
use App\Support\TargetNetworkPolicy;
use App\Support\TrustedProxyList;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0007 production profile helpers: config hardening, target network ranges, TRUSTED_PROXIES.
 */
class ProductionProfileSupportTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function config(array $overrides = []): Repository
    {
        return new Repository(array_replace_recursive([
            'app' => ['debug' => false],
            'session' => ['secure' => null, 'http_only' => true, 'same_site' => 'lax'],
            'ait' => ['allowed_target_hosts' => ['benchmark', 'simple-store']],
        ], $overrides));
    }

    public function test_production_forces_debug_off_and_reports_it(): void
    {
        $config = $this->config(['app' => ['debug' => true]]);

        $warnings = ProductionHardening::apply($config, 'production');

        $this->assertFalse($config->get('app.debug'));
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('APP_DEBUG=true', $warnings[0]);
    }

    public function test_production_defaults_to_secure_http_only_lax_cookies(): void
    {
        $config = $this->config(['session' => ['http_only' => false, 'same_site' => 'none']]);

        $warnings = ProductionHardening::apply($config, 'production');

        $this->assertTrue($config->get('session.secure'));
        $this->assertTrue($config->get('session.http_only'));
        $this->assertSame('lax', $config->get('session.same_site'));
        $this->assertCount(1, $warnings);
    }

    public function test_production_drops_loopback_targets_from_the_allowlist(): void
    {
        $config = $this->config(['ait' => ['allowed_target_hosts' => ['localhost', '127.0.0.1', 'benchmark', '[::1]:8000', 'localhost:8002', 'benchmark-order']]]);

        $warnings = ProductionHardening::apply($config, 'production');

        $this->assertSame(['benchmark', 'benchmark-order'], $config->get('ait.allowed_target_hosts'));
        $this->assertCount(1, $warnings);
    }

    public function test_safe_production_config_produces_no_warning(): void
    {
        $config = $this->config(['session' => ['secure' => true]]);

        $this->assertSame([], ProductionHardening::apply($config, 'production'));
    }

    public function test_other_environments_are_untouched(): void
    {
        $config = $this->config(['app' => ['debug' => true], 'ait' => ['allowed_target_hosts' => ['localhost']]]);

        $this->assertSame([], ProductionHardening::apply($config, 'local'));
        $this->assertTrue($config->get('app.debug'));
        $this->assertSame(['localhost', 'simple-store'], $config->get('ait.allowed_target_hosts'));
        $this->assertNull($config->get('session.secure'));
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool}>
     */
    public static function addresses(): array
    {
        return [
            'metadata' => ['169.254.169.254', true, true],
            'link-local v6' => ['fe80::1', true, true],
            'mapped metadata' => ['::ffff:169.254.169.254', true, true],
            'this network' => ['0.0.0.0', true, true],
            'multicast' => ['224.0.0.1', true, true],
            'loopback' => ['127.0.0.1', false, true],
            'mapped loopback' => ['::ffff:7f00:1', false, true],
            'rfc1918' => ['10.1.2.3', false, true],
            'docker' => ['172.20.0.5', false, true],
            'ula' => ['fd12::1', false, true],
            'public' => ['93.184.216.34', false, false],
            'public v6' => ['2606:4700::1111', false, false],
        ];
    }

    #[DataProvider('addresses')]
    public function test_target_network_policy_ranges(string $ip, bool $alwaysBlocked, bool $blockedWhenPrivateBlocked): void
    {
        $this->assertSame($alwaysBlocked, TargetNetworkPolicy::isAlwaysBlocked($ip));
        $this->assertSame($alwaysBlocked, TargetNetworkPolicy::isBlocked($ip, false));
        $this->assertSame($blockedWhenPrivateBlocked, TargetNetworkPolicy::isBlocked($ip, true));
    }

    public function test_loopback_names_and_normalization(): void
    {
        $this->assertTrue(TargetNetworkPolicy::isLoopbackName('localhost'));
        $this->assertTrue(TargetNetworkPolicy::isLoopbackName('API.localhost.'));
        $this->assertFalse(TargetNetworkPolicy::isLoopbackName('localhost.example.test'));
        $this->assertSame('127.0.0.1', TargetNetworkPolicy::normalize('::ffff:127.0.0.1'));
        $this->assertSame('::1', TargetNetworkPolicy::normalize('[::1]'));
        $this->assertNull(TargetNetworkPolicy::normalize('benchmark'));
    }

    public function test_trusted_proxy_list_parsing(): void
    {
        $this->assertNull(TrustedProxyList::parse(null));
        $this->assertNull(TrustedProxyList::parse(' , '));
        $this->assertSame('*', TrustedProxyList::parse('*'));
        $this->assertSame('*', TrustedProxyList::parse('10.0.0.1, *'));
        $this->assertSame(['10.0.0.1', '172.16.0.0/12'], TrustedProxyList::parse(' 10.0.0.1 ,172.16.0.0/12 '));

        $withCloudflare = TrustedProxyList::parse('Cloudflare,172.16.0.0/12');
        $this->assertIsArray($withCloudflare);
        $this->assertContains('173.245.48.0/20', $withCloudflare);
        $this->assertContains('2606:4700::/32', $withCloudflare);
        $this->assertContains('172.16.0.0/12', $withCloudflare);
    }
}
