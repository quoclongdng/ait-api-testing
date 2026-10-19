<?php

namespace App\Services\Benchmark;

use App\Contracts\BenchmarkSandbox;
use Closure;

/**
 * Case isolation for ordinary M12 runs against the platform's own benchmark sandboxes
 * (QA S13-03, ADR-0006 addendum 2026-10-13).
 *
 * The clean benchmarks are shared, mutable state: RULE cases create fixed SKUs/slugs and delete
 * seeded resources, so without a reset a second run of an unchanged suite (by anyone) gets
 * 409/404 and reports false FAILs. When the run target's host is in
 * `ait.runs.reset_benchmark_hosts` (RUN_RESET_BENCHMARK_HOSTS), the sandbox is restored to its
 * seed data through `POST <origin>/__admin/reset` before every case, the same protocol as an
 * experiment run with reset_per_case. Any other target (a user's own system) is never reset.
 *
 * The reset goes through BenchmarkSandbox, i.e. TargetHttpClient after the D07 allowlist check.
 */
final class BenchmarkTargetReset
{
    /** @var list<string> */
    private array $hosts;

    /**
     * @param  list<string>  $hosts  Benchmark host names (lower-cased, no port) whose sandbox may be reset.
     */
    public function __construct(
        private readonly BenchmarkSandbox $sandbox,
        array $hosts,
    ) {
        $this->hosts = array_values(array_unique(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            $hosts,
        ), static fn (string $host): bool => $host !== '')));
    }

    /**
     * Benchmark host names reset before every M12 case (shown on M12).
     *
     * @return list<string>
     */
    public function hosts(): array
    {
        return $this->hosts;
    }

    /**
     * Origin (scheme://host[:port]) of the benchmark sandbox behind $targetBaseUrl, or null when the
     * target is not one of the configured benchmark hosts.
     */
    public function sandboxOrigin(?string $targetBaseUrl): ?string
    {
        if ($targetBaseUrl === null || $this->hosts === []) {
            return null;
        }

        $parts = parse_url(trim($targetBaseUrl));
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower(rtrim($parts['host'], '.'));
        if (! in_array($scheme, ['http', 'https'], true) || ! in_array($host, $this->hosts, true)) {
            return null;
        }

        // Benchmarks serve their API and /__admin/reset at the root: a base path on the target is ignored.
        return $scheme.'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * Callback resetting the benchmark sandbox behind $targetBaseUrl, or null when the target is
     * not a configured benchmark (nothing is reset).
     *
     * @return (Closure(): void)|null
     */
    public function resetterFor(?string $targetBaseUrl): ?Closure
    {
        $origin = $this->sandboxOrigin($targetBaseUrl);
        if ($origin === null) {
            return null;
        }

        return function () use ($origin): void {
            $this->sandbox->reset($origin);
        };
    }
}
