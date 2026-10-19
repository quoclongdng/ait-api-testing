<?php

namespace App\Services\Benchmark;

use App\Contracts\BenchmarkSandbox;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Benchmark;
use App\Models\BenchmarkBug;
use RuntimeException;

/**
 * In-memory sandbox for tests and CI: every variant uses the benchmark base_url and the active
 * bug code is exposed so a fake target client can simulate the seeded behaviour.
 */
final class FakeBenchmarkSandbox implements BenchmarkSandbox
{
    public ?string $activeBugCode = null;

    /** @var list<string|null> */
    public array $history = [];

    /**
     * Every reset, in order, with the bug variant that was active when it was requested.
     *
     * @var list<array{url: string, bug: string|null}>
     */
    public array $resets = [];

    /** When set, reset() throws this message (simulates a broken sandbox). */
    public ?string $failResetWith = null;

    /** Bug code ('clean' for the clean variant) whose sandbox is unreachable: its reset() throws. */
    public ?string $unreachable = null;

    public function ensureAvailable(Benchmark $benchmark): void {}

    public function variantBaseUrl(Benchmark $benchmark, ?BenchmarkBug $bug): string
    {
        $this->activeBugCode = $bug?->code;
        $this->history[] = $this->activeBugCode;

        return rtrim($benchmark->base_url, '/');
    }

    public function reset(string $baseUrl): void
    {
        if ($this->failResetWith !== null) {
            throw new ApiException(ErrorCode::InternalError, $this->failResetWith);
        }
        $variant = $this->activeBugCode ?? 'clean';
        if ($variant === $this->unreachable) {
            throw new RuntimeException("Không đặt lại được sandbox của biến thể {$variant}.");
        }

        $this->resets[] = ['url' => rtrim($baseUrl, '/'), 'bug' => $this->activeBugCode];
    }
}
