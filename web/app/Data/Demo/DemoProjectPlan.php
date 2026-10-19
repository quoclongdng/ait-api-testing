<?php

namespace App\Data\Demo;

use App\Data\Generation\RecordedLlmJob;
use App\Enums\GenerationStrategy;
use Closure;

/**
 * What SeedBenchmarkDemoProjectAction builds for one benchmark: the DemoSeeder project of
 * `make demo` (S14-01) or one project of the public showcase (S14-05).
 */
final readonly class DemoProjectPlan
{
    /**
     * @param  list<GenerationStrategy>  $recordedStrategies  Strategies of the recorded jobs replayed into the recorded suite.
     * @param  Closure(): array<string, RecordedLlmJob>  $recordedJobs  Loads them (keyed by strategy value); called only
     *                                                                  when one of them is still missing from the suite.
     * @param  list<string>  $legacySuiteDescriptions  Earlier canonical descriptions of the two suites: an existing suite
     *                                                 still carrying one gets the current description (a description
     *                                                 a visitor wrote is kept).
     */
    public function __construct(
        public string $benchmarkSlug,
        public string $projectName,
        public string $projectDescription,
        public string $specVersion,
        public string $ruleSuiteName,
        public string $ruleSuiteDescription,
        public int $ruleMaxCasesPerEndpoint,
        public bool $ruleRunResetPerCase,
        public string $recordedSuiteName,
        public string $recordedSuiteDescription,
        public array $recordedStrategies,
        public Closure $recordedJobs,
        public bool $recordedRunUsesAuthFixture,
        public string $retryHint = '',
        public array $legacySuiteDescriptions = [],
    ) {}
}
