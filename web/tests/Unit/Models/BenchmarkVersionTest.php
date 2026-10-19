<?php

namespace Tests\Unit\Models;

use App\Models\Benchmark;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * S14-06: one spelling of a benchmark version for ground-truth.yaml ("1.0") and M14/API input.
 */
class BenchmarkVersionTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function versions(): array
    {
        return [
            'bare number' => ['1.0', 'v1.0'],
            'already canonical' => ['v1.0', 'v1.0'],
            'uppercase prefix' => ['V1.0', 'v1.0'],
            'surrounding spaces' => [' 1.0 ', 'v1.0'],
            'single number' => ['2', 'v2'],
            'suffix kept' => ['1.0-docker', 'v1.0-docker'],
            'only one prefix removed' => ['vv1', 'vv1'],
            'prefix alone kept' => ['V', 'V'],
            'free text kept, not "vbeta"' => [' beta ', 'beta'],
            'free text after prefix kept' => ['vNext', 'vNext'],
        ];
    }

    #[DataProvider('versions')]
    public function test_normalize_version(string $input, string $expected): void
    {
        $this->assertSame($expected, Benchmark::normalizeVersion($input));
    }

    public function test_normalisation_is_idempotent(): void
    {
        foreach (array_column(self::versions(), 1) as $canonical) {
            $this->assertSame($canonical, Benchmark::normalizeVersion($canonical));
        }
    }
}
