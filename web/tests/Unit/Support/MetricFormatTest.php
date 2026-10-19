<?php

namespace Tests\Unit\Support;

use App\Enums\ExperimentStatus;
use App\Enums\GenerationStrategy;
use App\Models\Benchmark;
use App\Models\Experiment;
use App\Services\Experiments\ExperimentSummaryBuilder;
use App\Support\MetricFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * QA S14-06: M15 (number_format) and summary.md / the report (sprintf on the binary float) rounded the
 * same stored value differently in 4 cells (24,2 vs 24,1 …). One half-up rule now serves both.
 */
class MetricFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_rates_round_half_up_on_their_decimal_value(): void
    {
        $this->assertSame('24,2', MetricFormat::percent(0.2415));
        $this->assertSame('82,1', MetricFormat::percent(0.8205));
        $this->assertSame('81,3', MetricFormat::percent(0.8125));
        $this->assertSame('1,3', MetricFormat::percent(0.0125));
        $this->assertSame('43,3', MetricFormat::percent(0.4333));
        $this->assertSame('100,0', MetricFormat::percent(1));
        $this->assertSame('0,0', MetricFormat::percent(0));
        $this->assertSame('24.2', MetricFormat::percent(0.2415, 1, '.', ''));
        $this->assertSame('263.3', MetricFormat::number(263333.3333 / 1000, 1, '.', ''));
        $this->assertSame('1.234,57', MetricFormat::number(1234.5678, 2));
        $this->assertSame('0,0', MetricFormat::number(-0.00001));
    }

    public function test_m15_and_summary_md_show_the_same_value(): void
    {
        $this->assertSame('24,2%', trim(strip_tags(Blade::render('<x-metric-value :mean="0.2415" />'))));

        $experiment = Experiment::factory()->create([
            'benchmark_id' => Benchmark::factory()->create(['slug' => 'simple-store'])->id,
            'strategy' => GenerationStrategy::Llm,
            'status' => ExperimentStatus::Completed,
            'config' => ['model' => 'qwen2.5-coder:7b', 'seed' => 42, 'temperature' => 0.2, 'repetitions' => 3],
            // The repetitions of the official simple-store LLM experiment: FPR mean 0.241533… → 0.2415.
            'metrics' => ['bugs_total' => 10, 'repetitions' => [
                ['false_positive_rate' => 0.1739, 'clean_pass' => 19, 'clean_fail' => 4, 'generated' => 23],
                ['false_positive_rate' => 0.3333, 'clean_pass' => 16, 'clean_fail' => 8, 'generated' => 24],
                ['false_positive_rate' => 0.2174, 'clean_pass' => 18, 'clean_fail' => 5, 'generated' => 23],
            ]],
        ]);
        $experiment->load('benchmark');

        $builder = app(ExperimentSummaryBuilder::class);
        $this->assertSame(0.2415, $builder->rows([$experiment])[0]['metrics']['false_positive_rate']['mean'] ?? null);
        $this->assertStringContainsString('| 24.2 ± 8.2 |', $builder->toMarkdown([$experiment], '2026-10-14 00:00:00'));
    }
}
