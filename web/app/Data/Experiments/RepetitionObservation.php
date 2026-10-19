<?php

namespace App\Data\Experiments;

/**
 * Raw counts observed in one repetition of an experiment (docs/DESIGN.md §11).
 *
 * Everything the metric formulas need is a plain counter here, so the formulas can be
 * unit-tested without a database. Counts of clean verdicts are taken over the VALID cases
 * that were executed on the clean variant.
 */
final readonly class RepetitionObservation
{
    public function __construct(
        public int $repetition = 1,
        public ?int $seed = null,
        public int $generated = 0,
        public int $valid = 0,
        public int $duplicate = 0,
        public int $cleanPass = 0,
        public int $cleanFail = 0,
        public int $cleanError = 0,
        public int $totalBugs = 0,
        public int $detectedBugs = 0,
        public int $totalMutants = 0,
        public int $killedMutants = 0,
        public int $totalEndpoints = 0,
        public int $coveredEndpoints = 0,
        public int $totalStatusPairs = 0,
        public int $assertedStatusPairs = 0,
        public int $generationTimeMs = 0,
        public int $executionTimeMs = 0,
        public int $promptTokens = 0,
        public int $completionTokens = 0,
    ) {}

    /**
     * Cases with a verdict PASS or FAIL on the clean variant (no transport ERROR).
     */
    public function executed(): int
    {
        return $this->cleanPass + $this->cleanFail;
    }
}
