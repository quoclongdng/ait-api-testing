<?php

namespace App\Services\Benchmark;

use App\Data\Experiments\AuthFixture;
use Throwable;

/**
 * Credentials of an ordinary M12 run on one of the platform's benchmark sandboxes (QA S14-06, ADR-0006
 * addendum 2026-10-14).
 *
 * A run whose target host is a platform benchmark (the same host list as BenchmarkTargetReset,
 * RUN_RESET_BENCHMARK_HOSTS) gets that benchmark's public synthetic auth fixture from its ground truth,
 * exactly like an experiment run: the `{{auth_token_<role>}}` variables are defined and secured operations
 * get the default Authorization header (ADR-0006 §3). The benchmark is matched by host: the host of the
 * ground-truth `base_url` (`http://<slug>:8000`), the slug itself, or the Docker Compose service name of the
 * benchmark in docker/docker-compose.yml. Any other target (a user's own system, a one-bug sandbox) never
 * gets a fixture.
 */
final class BenchmarkRunAuthResolver
{
    /** Docker Compose service names of the three platform benchmarks (docker/docker-compose.yml) by slug. */
    private const COMPOSE_SERVICES = [
        'benchmark' => 'simple-store',
        'benchmark-order' => 'ecommerce-order',
        'benchmark-booking' => 'appointment-booking',
    ];

    public function __construct(
        private readonly BenchmarkTargetReset $benchmarkHosts,
        private readonly GroundTruthLoader $loader,
    ) {}

    /**
     * The auth fixture (with its public sandbox tokens) of the benchmark behind $targetBaseUrl, or null when
     * the target is not a platform benchmark or the benchmark declares no fixture.
     */
    public function fixtureFor(?string $targetBaseUrl): ?AuthFixture
    {
        $origin = $this->benchmarkHosts->sandboxOrigin($targetBaseUrl);
        $host = $origin === null ? null : parse_url($origin, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        foreach ($this->loader->availableSlugs() as $slug) {
            try {
                $benchmark = $this->loader->load($slug);
            } catch (Throwable) {
                continue; // an unreadable ground truth never blocks a run, it only gets no credentials
            }

            $baseHost = parse_url($benchmark->baseUrl, PHP_URL_HOST);
            $matches = (is_string($baseHost) && strtolower($baseHost) === $host)
                || $slug === $host
                || (self::COMPOSE_SERVICES[$host] ?? null) === $slug;
            if ($matches) {
                return $benchmark->auth;
            }
        }

        return null;
    }
}
