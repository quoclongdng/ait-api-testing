<?php

namespace App\Contracts;

use App\Exceptions\ApiException;
use App\Models\Benchmark;
use App\Models\BenchmarkBug;

/**
 * Gives the experiment runner a base URL for the clean variant or for a single-bug variant of a
 * benchmark (experiment-design.md §4.3, single-fault isolation; D14).
 *
 * Seeded bugs are toggled by environment variables of the benchmark process (D13), which the web
 * worker cannot change, so each implementation decides how a variant is reached.
 * Implementations: UrlTemplateBenchmarkSandbox (real), FakeBenchmarkSandbox (tests).
 * Selected by config('ait.benchmark.sandbox_driver').
 */
interface BenchmarkSandbox
{
    /**
     * Throws VALIDATION_FAILED when bug variants of this benchmark cannot be reached.
     *
     * @throws ApiException
     */
    public function ensureAvailable(Benchmark $benchmark): void;

    /**
     * Base URL to run the suite against: the clean variant when $bug is null, otherwise the variant
     * where only $bug is enabled.
     *
     * @throws ApiException
     */
    public function variantBaseUrl(Benchmark $benchmark, ?BenchmarkBug $bug): string;

    /**
     * Restores the seed data of the sandbox behind $baseUrl through `POST <baseUrl>/__admin/reset`
     * (ADR-0002 §4, experiment-design.md §4.3), called before every clean and bug-variant run.
     * The URL must be on the target allowlist (D07, TARGET_NOT_ALLOWED otherwise). A sandbox
     * without the reset endpoint (HTTP 404) is tolerated with a logged warning; any other
     * failure throws so the experiment does not run on dirty data.
     *
     * @throws ApiException
     * @throws \RuntimeException
     */
    public function reset(string $baseUrl): void;
}
