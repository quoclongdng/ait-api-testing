<?php

namespace Tests\Unit\Services;

use App\Services\Execution\TargetAllowlistValidator;
use App\Services\Target\FakeTargetHostResolver;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TargetAllowlistValidatorTest extends TestCase
{
    public function test_host_authorization_is_required_after_successful_resolution(): void
    {
        $resolver = new FakeTargetHostResolver(['allowed.test' => ['192.0.2.1'], 'evil.test' => ['192.0.2.1']]);
        $validator = new TargetAllowlistValidator(['allowed.test:8443'], $resolver);
        $this->assertSame(['192.0.2.1'], $validator->resolveAllowedAddresses('https://allowed.test:8443/items'));
        $this->assertFalse($validator->isAllowed('https://evil.test:8443/items'));
        $this->assertFalse($validator->isAllowed('https://allowed.test/items'));
    }

    public function test_invalid_or_empty_dns_results_fail_closed(): void
    {
        foreach ([[], ['not-an-ip'], ['127.0.0.1', 'not-an-ip']] as $addresses) {
            $validator = new TargetAllowlistValidator(['allowed.test'], new FakeTargetHostResolver(['allowed.test' => $addresses]));
            $this->assertFalse($validator->isAllowed('http://allowed.test/data'));
        }
    }

    public function test_database_hostname_is_resolved_and_pinned_at_its_authorized_port(): void
    {
        config([
            'ait.allowed_target_hosts' => [],
            'database.default' => 'allowlist_test',
            'database.connections.allowlist_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);
        DB::statement('CREATE TABLE allowed_targets (id INTEGER PRIMARY KEY, host TEXT NOT NULL, port INTEGER)');
        DB::table('allowed_targets')->insert(['host' => 'store.test', 'port' => 8000]);
        $validator = new TargetAllowlistValidator(null, new FakeTargetHostResolver(['store.test' => ['10.0.0.5']]));
        $this->assertSame(['10.0.0.5'], $validator->resolveAllowedAddresses('http://store.test:8000/items'));
        $this->assertFalse($validator->isAllowed('http://store.test:8001/items'));
    }

    public function test_ipv6_database_entry_matches_bracketed_url_and_enforces_port(): void
    {
        config([
            'ait.allowed_target_hosts' => [],
            'database.default' => 'allowlist_test',
            'database.connections.allowlist_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);
        DB::statement('CREATE TABLE allowed_targets (id INTEGER PRIMARY KEY, host TEXT NOT NULL, port INTEGER)');
        DB::table('allowed_targets')->insert(['host' => '::1', 'port' => 8000]);

        $validator = new TargetAllowlistValidator;
        $this->assertTrue($validator->isAllowed('http://[::1]:8000/items'));
        $this->assertFalse($validator->isAllowed('http://[::1]:8001/items'));
        $this->assertFalse($validator->isAllowed('http://[::2]:8000/items'));

        DB::table('allowed_targets')->where('host', '::1')->update(['port' => null]);
        $this->assertTrue($validator->isAllowed('http://[::1]:8001/items'));
    }
}
