<?php

namespace Tests\Unit\Services\Experiments;

use App\Services\Benchmark\BenchmarkTargetReset;
use App\Services\Benchmark\FakeBenchmarkSandbox;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * QA S13-03 (ADR-0006 addendum 2026-10-13): only the platform's benchmark sandboxes are reset before
 * M12 cases, at their origin.
 */
class BenchmarkTargetResetTest extends TestCase
{
    private const HOSTS = ['benchmark', 'benchmark-order', 'benchmark-booking', 'simple-store', 'ecommerce-order', 'appointment-booking'];

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function targets(): array
    {
        return [
            'service name' => ['http://benchmark:8000', 'http://benchmark:8000'],
            'ground-truth alias' => ['http://simple-store:8000', 'http://simple-store:8000'],
            'trailing slash' => ['http://benchmark-order:8000/', 'http://benchmark-order:8000'],
            'base path ignored' => ['http://appointment-booking:8000/api/v1', 'http://appointment-booking:8000'],
            'upper-case host' => ['HTTP://Ecommerce-Order:8000', 'http://ecommerce-order:8000'],
            'no port' => ['https://benchmark-booking', 'https://benchmark-booking'],
            'user target' => ['http://localhost:8000', null],
            'look-alike host' => ['http://simple-store.example.com:8000', null],
            'single-bug sandbox' => ['http://simple-store-bug-001:8000', null],
            'not a URL' => ['simple-store:8000', null],
            'other scheme' => ['ftp://benchmark:8000', null],
        ];
    }

    #[DataProvider('targets')]
    public function test_only_configured_benchmark_hosts_are_reset_at_their_origin(string $target, ?string $origin): void
    {
        $reset = new BenchmarkTargetReset(new FakeBenchmarkSandbox, self::HOSTS);

        $this->assertSame($origin, $reset->sandboxOrigin($target));
        $this->assertSame($origin === null, $reset->resetterFor($target) === null);
    }

    public function test_resetter_resets_the_sandbox_origin(): void
    {
        $sandbox = new FakeBenchmarkSandbox;
        $resetter = (new BenchmarkTargetReset($sandbox, [' Simple-Store ']))->resetterFor('http://simple-store:8000/x');

        $this->assertNotNull($resetter);
        $resetter();
        $resetter();

        $this->assertSame([
            ['url' => 'http://simple-store:8000', 'bug' => null],
            ['url' => 'http://simple-store:8000', 'bug' => null],
        ], $sandbox->resets);
    }

    public function test_an_empty_host_list_disables_the_reset(): void
    {
        $reset = new BenchmarkTargetReset(new FakeBenchmarkSandbox, ['', '  ']);

        $this->assertNull($reset->resetterFor('http://benchmark:8000'));
        $this->assertNull($reset->resetterFor(null));
    }
}
