<?php

namespace App\Actions\Runs;

use App\Data\RunTargetOption;
use App\Models\AllowedTarget;

/**
 * Builds the target suggestions for screen M12 from ALLOWED_TARGET_HOSTS ∪ allowed_targets (D07).
 *
 * The suggestions only pre-fill an editable URL field: a host-only allowlist entry allows any
 * port, so the user may add a port or a base path. Benchmark hosts (name starting with
 * "benchmark") without a port also get a suggestion on the port the benchmark containers
 * listen on. The server re-checks the final URL when the run is created.
 */
class ListRunTargetOptionsAction
{
    /** Port every benchmark API listens on inside docker compose. */
    public const BENCHMARK_PORT = 8000;

    /**
     * @return list<RunTargetOption>
     */
    public function execute(): array
    {
        /** @var array<string, RunTargetOption> $options */
        $options = [];

        $configured = config('ait.allowed_target_hosts', []);
        foreach (is_array($configured) ? $configured : [] as $hostPort) {
            if (! is_string($hostPort) || trim($hostPort) === '') {
                continue;
            }
            [$host, $port] = $this->splitHostPort(trim($hostPort));
            $this->add($options, $host, $port, 'cấu hình hệ thống');
        }

        foreach (AllowedTarget::query()->orderBy('host')->orderBy('port')->get() as $target) {
            $note = $target->note !== null && $target->note !== '' ? $target->note : null;
            $this->add($options, $target->host, $target->port, $note);
        }

        return array_values($options);
    }

    /**
     * @param  array<string, RunTargetOption>  $options
     */
    private function add(array &$options, string $host, ?int $port, ?string $note): void
    {
        $suffix = $note !== null ? ' — '.$note : '';

        if ($port !== null) {
            $url = 'http://'.$this->formatHost($host).':'.$port;
            $options[$url] ??= new RunTargetOption($url, $host.':'.$port.$suffix);

            return;
        }

        if (str_starts_with(strtolower($host), 'benchmark')) {
            $url = 'http://'.$this->formatHost($host).':'.self::BENCHMARK_PORT;
            $options[$url] ??= new RunTargetOption($url, $host.' (benchmark, cổng '.self::BENCHMARK_PORT.')'.$suffix);
        }

        $url = 'http://'.$this->formatHost($host);
        $options[$url] ??= new RunTargetOption($url, $host.' (mọi cổng, có thể thêm :cổng)'.$suffix);
    }

    /**
     * Splits a "host", "host:port", "[v6]:port" or bare IPv6 allowlist entry.
     *
     * @return array{0: string, 1: int|null}
     */
    private function splitHostPort(string $hostPort): array
    {
        if (preg_match('/^\[(.+)\]:(\d+)$/', $hostPort, $m) === 1) {
            return [$m[1], (int) $m[2]];
        }
        if (substr_count($hostPort, ':') === 1) {
            [$host, $port] = explode(':', $hostPort);
            if (ctype_digit($port)) {
                return [$host, (int) $port];
            }
        }

        return [trim($hostPort, '[]'), null];
    }

    private function formatHost(string $host): string
    {
        // Bare IPv6 literals must be bracketed inside a URL.
        if (substr_count($host, ':') > 1 && ! str_starts_with($host, '[')) {
            return '['.$host.']';
        }

        return $host;
    }
}
