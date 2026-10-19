<?php

namespace App\Services\Experiments;

/**
 * Chooses the LLM seed of every repetition (experiment-design.md §4.2: Seed 1 = 42,
 * Seed 2 = 1024, Seed 3 = 2026), configurable through EXPERIMENT_SEEDS (config ait.experiments.seeds).
 *
 * Rules, deterministic so an experiment can be replayed:
 * - explicit seeds (config.seeds) are used as given;
 * - otherwise the protocol list is used; a first seed different from the protocol's first seed
 *   (config.seed) replaces repetition 1 and the remaining protocol seeds follow;
 * - when more repetitions than seeds are requested the list continues with last + 1, last + 2, …
 *   skipping values already used. Duplicates are removed.
 */
final class ExperimentSeedPlanner
{
    public const DEFAULT_SEEDS = [42, 1024, 2026];

    /**
     * @param  list<int>|null  $protocolSeeds  null reads config('ait.experiments.seeds')
     */
    public function __construct(private readonly ?array $protocolSeeds = null) {}

    /**
     * @param  list<int>|null  $explicitSeeds
     * @return non-empty-list<int> exactly $repetitions seeds (at least one)
     */
    public function plan(int $repetitions, ?int $firstSeed = null, ?array $explicitSeeds = null): array
    {
        $repetitions = max(1, $repetitions);

        if ($explicitSeeds !== null && $explicitSeeds !== []) {
            $seeds = $explicitSeeds;
        } else {
            $seeds = $this->protocol();
            if ($firstSeed !== null && $firstSeed !== $seeds[0]) {
                $seeds = [$firstSeed, ...array_values(array_filter($seeds, static fn (int $s): bool => $s !== $firstSeed))];
            }
        }

        /** @var non-empty-list<int> $seeds */
        $seeds = array_values(array_unique(array_map('intval', $seeds)));
        while (count($seeds) < $repetitions) {
            $next = $seeds[count($seeds) - 1] + 1;
            while (in_array($next, $seeds, true)) {
                $next++;
            }
            $seeds[] = $next;
        }

        /** @var non-empty-list<int> */
        return array_slice($seeds, 0, $repetitions);
    }

    /**
     * @return non-empty-list<int>
     */
    public function protocol(): array
    {
        $seeds = $this->protocolSeeds;
        if ($seeds === null) {
            $configured = config('ait.experiments.seeds');
            $seeds = is_array($configured) ? array_values(array_map('intval', $configured)) : [];
        }

        return $seeds === [] ? self::DEFAULT_SEEDS : $seeds;
    }
}
