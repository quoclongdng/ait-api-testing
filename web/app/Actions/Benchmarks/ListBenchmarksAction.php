<?php

namespace App\Actions\Benchmarks;

use App\Models\Benchmark;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class ListBenchmarksAction
{
    /**
     * @return LengthAwarePaginator<int, Benchmark>
     */
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return Benchmark::query()->withCount('bugs')->orderBy('slug')->orderBy('version')->paginate($perPage);
    }

    /**
     * Every benchmark with its seeded bugs (M14, M15 selector), in the canonical benchmark order
     * (Benchmark::CANONICAL_NUMBERS), then any other benchmark by slug; versions in order.
     * `experiments_count` tells M14 which benchmarks can be deleted (S14-06).
     *
     * @return Collection<int, Benchmark>
     */
    public function all(): Collection
    {
        return Benchmark::query()
            ->with(['bugs' => fn ($query) => $query->orderBy('code')])
            ->withCount(['bugs', 'experiments'])
            ->get()
            ->sort(fn (Benchmark $a, Benchmark $b): int => [Benchmark::CANONICAL_NUMBERS[$a->slug] ?? PHP_INT_MAX, $a->slug, $a->version]
                <=> [Benchmark::CANONICAL_NUMBERS[$b->slug] ?? PHP_INT_MAX, $b->slug, $b->version])
            ->values();
    }

    /**
     * "Benchmark #n" of every listed benchmark, keyed by id: the canonical number of the three
     * benchmarks of the thesis (simple-store #1, ecommerce-order #2, appointment-booking #3), then
     * the next numbers for any other slug in slug order. Versions of one slug share its number.
     *
     * @param  Collection<int, Benchmark>  $benchmarks
     * @return array<int, int>
     */
    public function numbers(Collection $benchmarks): array
    {
        $bySlug = Benchmark::CANONICAL_NUMBERS;
        $next = count($bySlug) + 1;
        $others = $benchmarks->pluck('slug')->unique()->sort()->values();
        foreach ($others as $slug) {
            if (! isset($bySlug[$slug])) {
                $bySlug[$slug] = $next++;
            }
        }

        $numbers = [];
        foreach ($benchmarks as $benchmark) {
            $numbers[$benchmark->id] = $bySlug[$benchmark->slug];
        }

        return $numbers;
    }
}
