<?php

namespace App\Actions\Benchmarks;

use App\Contracts\BenchmarkSandbox;
use App\Exceptions\ApiException;
use App\Models\AllowedTarget;
use App\Models\Benchmark;
use App\Models\BenchmarkBug;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Adds the hosts of the clean and of every single-bug variant of benchmarks to `allowed_targets`
 * (S11-02), so experiments can reach the sandboxes derived from BENCHMARK_CLEAN_URL_TEMPLATE and
 * BENCHMARK_VARIANT_URL_TEMPLATE. Each row is an exact host:port (no wildcard), visible and
 * removable on M04. D07 is unchanged: the executor still resolves the host before checking it
 * and pins the resolved addresses.
 *
 * Idempotent: a host already listed for that port (or for any port) is skipped.
 */
class AllowBenchmarkTargetsAction
{
    public function __construct(private readonly BenchmarkSandbox $sandbox) {}

    /**
     * @param  list<int>|null  $benchmarkIds  null = every benchmark
     * @return list<array{benchmark: string, host: string, port: int, created: bool}>
     */
    public function execute(User $by, ?array $benchmarkIds = null): array
    {
        $benchmarks = Benchmark::query()
            ->with('bugs')
            ->when($benchmarkIds !== null, fn ($query) => $query->whereIn('id', $benchmarkIds ?? []))
            ->orderBy('slug')
            ->orderBy('id')
            ->get();

        $rows = [];
        foreach ($benchmarks as $benchmark) {
            foreach ($this->variantUrls($benchmark) as $url) {
                $target = $this->hostAndPort($url);
                if ($target === null) {
                    continue;
                }
                [$host, $port] = $target;
                $key = "{$benchmark->slug}|{$host}|{$port}";
                if (isset($rows[$key])) {
                    continue;
                }

                $exists = AllowedTarget::query()
                    ->whereRaw('LOWER(host) = ?', [$host])
                    ->where(fn ($query) => $query->whereNull('port')->orWhere('port', $port))
                    ->exists();
                if (! $exists) {
                    AllowedTarget::query()->firstOrCreate(['host' => $host, 'port' => $port], [
                        'note' => mb_substr("Sandbox benchmark {$benchmark->slug} (tự thêm cho thực nghiệm)", 0, 255),
                        'created_by' => $by->id,
                    ]);
                }

                $rows[$key] = ['benchmark' => $benchmark->slug, 'host' => $host, 'port' => $port, 'created' => ! $exists];
            }
        }

        return array_values($rows);
    }

    /**
     * @return list<string>
     */
    private function variantUrls(Benchmark $benchmark): array
    {
        $urls = [$this->sandbox->variantBaseUrl($benchmark, null)];

        /** @var Collection<int, BenchmarkBug> $bugs */
        $bugs = $benchmark->bugs->sortBy('code');
        foreach ($bugs as $bug) {
            try {
                $urls[] = $this->sandbox->variantBaseUrl($benchmark, $bug);
            } catch (ApiException) {
                break; // no variant template configured: only the clean variant is reachable
            }
        }

        return $urls;
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    private function hostAndPort(string $url): ?array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host'], $parts['scheme'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === '' || strlen($host) > 255) {
            return null;
        }

        return [$host, $parts['port'] ?? ($scheme === 'https' ? 443 : 80)];
    }
}
