<?php

namespace Tests\Unit\Services\Experiments;

use App\Data\Experiments\RepetitionObservation;
use App\Services\Experiments\ExperimentMetricsCalculator;
use PHPUnit\Framework\TestCase;

class ExperimentMetricsCalculatorTest extends TestCase
{
    private ExperimentMetricsCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new ExperimentMetricsCalculator;
    }

    public function test_validity_rate_is_valid_over_generated(): void
    {
        $this->assertSame(0.75, $this->calculator->validityRate(15, 20));
        $this->assertSame(1.0, $this->calculator->validityRate(4, 4));
    }

    public function test_validity_rate_with_nothing_generated_is_zero(): void
    {
        $this->assertSame(0.0, $this->calculator->validityRate(0, 0));
    }

    public function test_executable_rate_is_executed_over_valid(): void
    {
        $this->assertSame(0.9, $this->calculator->executableRate(9, 10));
        $this->assertSame(0.0, $this->calculator->executableRate(0, 0));
    }

    public function test_false_positive_rate_is_clean_failures_over_executed(): void
    {
        $this->assertSame(0.25, $this->calculator->falsePositiveRate(2, 8));
        $this->assertSame(0.0, $this->calculator->falsePositiveRate(0, 0));
    }

    public function test_bug_detection_rate_is_detected_over_seeded(): void
    {
        $this->assertSame(0.7, $this->calculator->bugDetectionRate(7, 10));
        $this->assertSame(0.0, $this->calculator->bugDetectionRate(0, 0));
    }

    public function test_mutation_score_is_killed_over_evaluated_mutants(): void
    {
        $this->assertSame(0.6667, $this->calculator->mutationScore(2, 3));
        $this->assertSame(0.0, $this->calculator->mutationScore(5, 0));
    }

    public function test_endpoint_coverage_is_covered_over_total_endpoints(): void
    {
        $this->assertSame(0.8, $this->calculator->endpointCoverage(4, 5));
        $this->assertSame(0.0, $this->calculator->endpointCoverage(0, 0));
    }

    public function test_status_code_coverage_is_asserted_pairs_over_declared_pairs(): void
    {
        $this->assertSame(0.5, $this->calculator->statusCodeCoverage(6, 12));
        $this->assertSame(0.0, $this->calculator->statusCodeCoverage(3, 0));
    }

    public function test_redundancy_is_duplicates_over_generated(): void
    {
        $this->assertSame(0.1, $this->calculator->redundancy(2, 20));
        $this->assertSame(0.0, $this->calculator->redundancy(0, 0));
    }

    public function test_negative_numerators_are_clamped_to_zero(): void
    {
        $this->assertSame(0.0, $this->calculator->validityRate(-3, 10));
    }

    public function test_for_repetition_computes_every_metric_from_counts(): void
    {
        $row = $this->calculator->forRepetition(new RepetitionObservation(
            repetition: 2,
            seed: 43,
            generated: 20,
            valid: 16,
            duplicate: 2,
            cleanPass: 10,
            cleanFail: 2,
            cleanError: 4,
            totalBugs: 10,
            detectedBugs: 5,
            totalMutants: 10,
            killedMutants: 5,
            totalEndpoints: 5,
            coveredEndpoints: 4,
            totalStatusPairs: 10,
            assertedStatusPairs: 3,
            generationTimeMs: 1200,
            executionTimeMs: 3400,
            promptTokens: 1000,
            completionTokens: 300,
        ));

        $this->assertSame(2, $row['repetition']);
        $this->assertSame(43, $row['seed']);
        $this->assertSame(0.8, $row['validity_rate']);
        $this->assertSame(0.75, $row['executable_rate']);
        $this->assertSame(0.1667, $row['false_positive_rate']);
        $this->assertSame(0.5, $row['bug_detection_rate']);
        $this->assertSame(0.5, $row['mutation_score']);
        $this->assertSame(0.8, $row['endpoint_coverage']);
        $this->assertSame(0.3, $row['status_code_coverage']);
        $this->assertSame(0.1, $row['redundancy']);
        $this->assertSame(1200, $row['generation_time_ms']);
        $this->assertSame(3400, $row['execution_time_ms']);
        $this->assertSame(1000, $row['prompt_tokens']);
        $this->assertSame(300, $row['completion_tokens']);
    }

    public function test_empty_repetition_produces_zero_metrics_without_division_errors(): void
    {
        $row = $this->calculator->forRepetition(new RepetitionObservation);

        foreach (ExperimentMetricsCalculator::RATE_KEYS as $key) {
            $this->assertSame(0.0, $row[$key], $key);
        }
    }

    public function test_aggregate_reports_mean_and_sample_stddev(): void
    {
        $metrics = $this->calculator->aggregate([
            new RepetitionObservation(repetition: 1, generated: 10, valid: 10, totalBugs: 10, detectedBugs: 4, generationTimeMs: 100, promptTokens: 10),
            new RepetitionObservation(repetition: 2, generated: 10, valid: 8, totalBugs: 10, detectedBugs: 6, generationTimeMs: 200, promptTokens: 20),
            new RepetitionObservation(repetition: 3, generated: 10, valid: 6, totalBugs: 10, detectedBugs: 5, generationTimeMs: 301, promptTokens: 30),
        ]);

        $this->assertSame(0.8, $metrics['validity_rate']);
        $this->assertSame(0.2, $metrics['stddev']['validity_rate']);
        $this->assertSame(0.5, $metrics['bug_detection_rate']);
        $this->assertSame(0.1, $metrics['stddev']['bug_detection_rate']);
        $this->assertSame(200, $metrics['generation_time_ms']);
        $this->assertSame(20, $metrics['prompt_tokens']);
        $this->assertSame(10, $metrics['bugs_total']);
        $this->assertSame(5.0, $metrics['bugs_detected_mean']);
        $this->assertCount(3, $metrics['repetitions']);
    }

    public function test_aggregate_of_single_repetition_has_zero_stddev(): void
    {
        $metrics = $this->calculator->aggregate([
            new RepetitionObservation(generated: 4, valid: 3),
        ]);

        $this->assertSame(0.75, $metrics['validity_rate']);
        $this->assertSame(0.0, $metrics['stddev']['validity_rate']);
    }

    /**
     * Regression (S10-01): a repetition whose generation failed (generated = 0, executed = 0) used
     * to contribute FPR = 0.0 and Redundancy = 0.0, the best values, to the strategy means.
     */
    public function test_failed_repetitions_do_not_improve_lower_is_better_means(): void
    {
        $metrics = $this->calculator->aggregate([
            new RepetitionObservation(repetition: 1, generated: 10, valid: 10, duplicate: 4, cleanPass: 6, cleanFail: 4, totalBugs: 2),
            new RepetitionObservation(repetition: 2, totalBugs: 2), // LLM generation failed
        ]);

        $this->assertSame(0.4, $metrics['false_positive_rate']);
        $this->assertSame(0.0, $metrics['stddev']['false_positive_rate']);
        $this->assertSame(0.4, $metrics['redundancy']);
        // Higher-is-better rates keep the failed repetition as 0 (it is penalised, not hidden).
        $this->assertSame(0.5, $metrics['validity_rate']);
        // The per-repetition rows stay numeric (contract), with the raw counters that explain them.
        $this->assertSame(0.0, $metrics['repetitions'][1]['false_positive_rate']);
        $this->assertSame(0, $metrics['repetitions'][1]['generated']);
    }

    public function test_lower_is_better_rates_are_omitted_when_never_applicable(): void
    {
        $metrics = $this->calculator->aggregate([new RepetitionObservation(repetition: 1, totalBugs: 2)]);

        $this->assertArrayNotHasKey('false_positive_rate', $metrics);
        $this->assertArrayNotHasKey('redundancy', $metrics);
        $this->assertArrayNotHasKey('false_positive_rate', $metrics['stddev']);
        $this->assertSame(0.0, $metrics['validity_rate']);
    }

    public function test_aggregate_of_no_repetitions_is_all_zero(): void
    {
        $metrics = $this->calculator->aggregate([]);

        $this->assertSame(0.0, $metrics['validity_rate']);
        $this->assertSame(0, $metrics['generation_time_ms']);
        $this->assertSame(0, $metrics['bugs_total']);
        $this->assertSame([], $metrics['repetitions']);
    }

    public function test_sample_stddev(): void
    {
        $this->assertEqualsWithDelta(2.1381, $this->calculator->sampleStddev([2, 4, 4, 4, 5, 5, 7, 9]), 0.0001);
        $this->assertSame(0.0, $this->calculator->sampleStddev([]));
        $this->assertSame(0.0, $this->calculator->sampleStddev([3.0]));
    }
}
