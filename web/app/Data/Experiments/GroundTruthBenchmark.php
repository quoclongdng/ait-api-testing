<?php

namespace App\Data\Experiments;

/**
 * Content of one `benchmark/<slug>/ground-truth.yaml` (experiment-design.md §7, D13).
 */
final readonly class GroundTruthBenchmark
{
    /**
     * @param  list<GroundTruthBug>  $bugs
     * @param  list<array{code: string, description: string, method: string|null, path: string|null}>  $businessRules  ADR-0006
     */
    public function __construct(
        public string $slug,
        public string $name,
        public ?string $description,
        public string $baseUrl,
        public string $version,
        public array $bugs,
        public ?AuthFixture $auth = null,
        public array $businessRules = [],
    ) {}
}
