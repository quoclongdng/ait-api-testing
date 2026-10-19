<?php

namespace App\Services\Experiments;

use App\Data\Experiments\RepetitionObservation;

/**
 * Formulas of docs/DESIGN.md §11 / docs/architecture/experiment-design.md §5.
 *
 * Rates are fractions in [0, 1] rounded to 4 decimals. A zero denominator yields 0.0 per
 * repetition (nothing was produced, so nothing can be counted as valid/covered/detected); this
 * keeps the stored per-repetition metrics numeric as required by the ExperimentMetrics contract.
 *
 * Aggregation over repetitions reports the arithmetic mean and the sample standard deviation
 * (n - 1; 0.0 when there is a single repetition). For the lower-is-better rates (False Positive
 * Rate, Redundancy) 0.0 is the best value, so a repetition whose denominator is 0 (generation
 * failed, every clean case ERROR) is not applicable and is left out of the mean/stddev; when no
 * repetition is applicable the aggregate key is omitted instead of reporting a perfect 0.
 */
final class ExperimentMetricsCalculator
{
    /** Rate metrics (fractions). */
    public const RATE_KEYS = [
        'validity_rate',
        'executable_rate',
        'false_positive_rate',
        'bug_detection_rate',
        'mutation_score',
        'endpoint_coverage',
        'status_code_coverage',
        'redundancy',
    ];

    /** Lower-is-better rates whose aggregate skips repetitions with a zero denominator. */
    public const NOT_APPLICABLE_WHEN_EMPTY = ['false_positive_rate', 'redundancy'];

    /** Cost metrics (integers per repetition). */
    public const COST_KEYS = [
        'generation_time_ms',
        'execution_time_ms',
        'prompt_tokens',
        'completion_tokens',
    ];

    /** Validity Rate = VALID / generated. */
    public function validityRate(int $valid, int $generated): float
    {
        return $this->ratio($valid, $generated);
    }

    /** Executable Rate = cases without ERROR on the clean variant / VALID. */
    public function executableRate(int $executed, int $valid): float
    {
        return $this->ratio($executed, $valid);
    }

    /** False Positive Rate = FAIL on the clean variant / executed (PASS + FAIL). */
    public function falsePositiveRate(int $failOnClean, int $executed): float
    {
        return $this->ratio($failOnClean, $executed);
    }

    /** Bug Detection Rate = bugs detected per D14 / seeded bugs. */
    public function bugDetectionRate(int $detectedBugs, int $totalBugs): float
    {
        return $this->ratio($detectedBugs, $totalBugs);
    }

    /** Mutation Score = killed bug variants / evaluated bug variants. */
    public function mutationScore(int $killedMutants, int $totalMutants): float
    {
        return $this->ratio($killedMutants, $totalMutants);
    }

    /** Endpoint Coverage = endpoints with >= 1 VALID case / endpoints of the spec. */
    public function endpointCoverage(int $coveredEndpoints, int $totalEndpoints): float
    {
        return $this->ratio($coveredEndpoints, $totalEndpoints);
    }

    /** Status Code Coverage = asserted (endpoint, status) pairs / pairs declared in the spec. */
    public function statusCodeCoverage(int $assertedPairs, int $totalPairs): float
    {
        return $this->ratio($assertedPairs, $totalPairs);
    }

    /** Redundancy = DUPLICATE / generated. */
    public function redundancy(int $duplicate, int $generated): float
    {
        return $this->ratio($duplicate, $generated);
    }

    /**
     * Every §11 metric of one repetition, plus the raw counters it was computed from.
     *
     * @return array<string, int|float|null>
     */
    public function forRepetition(RepetitionObservation $o): array
    {
        return [
            'repetition' => $o->repetition,
            'seed' => $o->seed,
            'validity_rate' => $this->validityRate($o->valid, $o->generated),
            'executable_rate' => $this->executableRate($o->executed(), $o->valid),
            'false_positive_rate' => $this->falsePositiveRate($o->cleanFail, $o->executed()),
            'bug_detection_rate' => $this->bugDetectionRate($o->detectedBugs, $o->totalBugs),
            'mutation_score' => $this->mutationScore($o->killedMutants, $o->totalMutants),
            'endpoint_coverage' => $this->endpointCoverage($o->coveredEndpoints, $o->totalEndpoints),
            'status_code_coverage' => $this->statusCodeCoverage($o->assertedStatusPairs, $o->totalStatusPairs),
            'redundancy' => $this->redundancy($o->duplicate, $o->generated),
            'generation_time_ms' => max(0, $o->generationTimeMs),
            'execution_time_ms' => max(0, $o->executionTimeMs),
            'prompt_tokens' => max(0, $o->promptTokens),
            'completion_tokens' => max(0, $o->completionTokens),
            'generated' => $o->generated,
            'valid' => $o->valid,
            'duplicate' => $o->duplicate,
            'clean_pass' => $o->cleanPass,
            'clean_fail' => $o->cleanFail,
            'clean_error' => $o->cleanError,
            'bugs_total' => $o->totalBugs,
            'bugs_detected' => $o->detectedBugs,
        ];
    }

    /**
     * Mean and sample standard deviation of every metric over the repetitions.
     *
     * The returned array matches the ExperimentMetrics contract: the mean of each rate and cost
     * metric at top level, `stddev` per metric, `repetitions` with the per-repetition values.
     *
     * @param  list<RepetitionObservation>  $observations
     * @return array<string, mixed>
     */
    public function aggregate(array $observations): array
    {
        $perRepetition = array_map(fn (RepetitionObservation $o): array => $this->forRepetition($o), $observations);

        $metrics = [];
        $stddev = [];
        foreach ([...self::RATE_KEYS, ...self::COST_KEYS] as $key) {
            $values = [];
            foreach ($observations as $index => $observation) {
                if (in_array($key, self::NOT_APPLICABLE_WHEN_EMPTY, true) && $this->denominator($key, $observation) <= 0) {
                    continue;
                }
                $values[] = (float) ($perRepetition[$index][$key] ?? 0);
            }
            if ($values === [] && $observations !== [] && in_array($key, self::NOT_APPLICABLE_WHEN_EMPTY, true)) {
                continue; // not applicable in any repetition: no value rather than a perfect 0.0
            }
            $mean = $this->mean($values);
            $metrics[$key] = in_array($key, self::COST_KEYS, true) ? (int) round($mean) : round($mean, 4);
            $stddev[$key] = round($this->sampleStddev($values), 4);
        }

        $detected = array_map(static fn (RepetitionObservation $o): float => (float) $o->detectedBugs, $observations);
        $metrics['bugs_total'] = $observations === [] ? 0 : max(array_map(static fn (RepetitionObservation $o): int => $o->totalBugs, $observations));
        $metrics['bugs_detected_mean'] = round($this->mean($detected), 4);
        $metrics['stddev'] = $stddev;
        $metrics['repetitions'] = $perRepetition;

        return $metrics;
    }

    /**
     * @param  list<float>  $values
     */
    public function mean(array $values): float
    {
        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }

    /**
     * Sample standard deviation (Bessel's correction). Fewer than two values → 0.0.
     *
     * @param  list<float>  $values
     */
    public function sampleStddev(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }

        $mean = $this->mean($values);
        $sumSquares = 0.0;
        foreach ($values as $value) {
            $sumSquares += ($value - $mean) ** 2;
        }

        return sqrt($sumSquares / ($n - 1));
    }

    private function denominator(string $key, RepetitionObservation $observation): int
    {
        return match ($key) {
            'false_positive_rate' => $observation->executed(),
            'redundancy' => $observation->generated,
            default => 1,
        };
    }

    private function ratio(int $numerator, int $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return round(max(0, $numerator) / $denominator, 4);
    }
}
