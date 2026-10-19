<?php

namespace App\Data\Demo;

use App\Models\Project;
use App\Models\TestSuite;

/**
 * Outcome of SeedBenchmarkDemoProjectAction: the project, its two suites and the warnings of the
 * parts that were skipped (printed by the caller, never logged).
 */
final readonly class DemoProjectResult
{
    /**
     * @param  list<TestSuite>  $suites
     * @param  list<string>  $warnings
     */
    public function __construct(
        public Project $project,
        public array $suites,
        public array $warnings,
    ) {}
}
