<?php

namespace Tests\Feature\Experiments;

use App\Data\Experiments\RepetitionObservation;
use App\Enums\ExperimentStatus;
use App\Enums\GenerationStrategy;
use App\Models\Benchmark;
use App\Models\Experiment;
use App\Services\Experiments\ExperimentMetricsCalculator;
use App\Services\Experiments\ExperimentSummaryBuilder;
use App\Services\Reports\RunReportExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * S12-02: result folder of `make experiment` (ait:experiments:export --dir) and the §11 summary
 * table (mean ± std per benchmark × strategy).
 */
class ExperimentSummaryExportTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/experiment-bundle-'.bin2hex(random_bytes(4)));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function command(string $name, array $parameters = []): PendingCommand
    {
        $command = $this->artisan($name, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    /**
     * @param  list<array{generated: int, valid: int, pass: int, fail: int, detected: int}>  $repetitions
     */
    private function experiment(Benchmark $benchmark, GenerationStrategy $strategy, array $repetitions, ExperimentStatus $status = ExperimentStatus::Completed): Experiment
    {
        $observations = [];
        foreach ($repetitions as $index => $r) {
            $observations[] = new RepetitionObservation(
                repetition: $index + 1, seed: [42, 1024, 2026][$index] ?? 2027, generated: $r['generated'], valid: $r['valid'],
                cleanPass: $r['pass'], cleanFail: $r['fail'], totalBugs: 10, detectedBugs: $r['detected'],
                totalMutants: 10, killedMutants: $r['detected'], generationTimeMs: 2000, promptTokens: 100 * ($index + 1),
            );
        }

        return Experiment::factory()->create([
            'benchmark_id' => $benchmark->id,
            'strategy' => $strategy,
            'status' => $status,
            'config' => ['model' => 'qwen2.5-coder:7b', 'seed' => 42, 'seeds' => [42, 1024, 2026], 'temperature' => 0.2, 'repetitions' => max(3, count($repetitions))],
            'metrics' => $status === ExperimentStatus::Completed ? app(ExperimentMetricsCalculator::class)->aggregate($observations) : null,
        ]);
    }

    /**
     * @return list<array{generated: int, valid: int, pass: int, fail: int, detected: int}>
     */
    private function ruleRepetitions(): array
    {
        return [
            ['generated' => 10, 'valid' => 10, 'pass' => 8, 'fail' => 2, 'detected' => 4],
            ['generated' => 10, 'valid' => 10, 'pass' => 6, 'fail' => 4, 'detected' => 6],
            ['generated' => 10, 'valid' => 10, 'pass' => 7, 'fail' => 3, 'detected' => 5],
        ];
    }

    public function test_summary_rows_report_mean_and_sample_stddev_per_benchmark_and_strategy(): void
    {
        $store = Benchmark::factory()->create(['slug' => 'simple-store']);
        $order = Benchmark::factory()->create(['slug' => 'ecommerce-order']);
        $rule = $this->experiment($store, GenerationStrategy::Rule, $this->ruleRepetitions());
        // Every LLM generation produced nothing: FPR and Redundancy are not applicable (no perfect 0).
        $empty = ['generated' => 0, 'valid' => 0, 'pass' => 0, 'fail' => 0, 'detected' => 0];
        $llm = $this->experiment($store, GenerationStrategy::Llm, [$empty, $empty, $empty]);
        $failed = $this->experiment($order, GenerationStrategy::Hybrid, [], ExperimentStatus::Failed);

        $rows = app(ExperimentSummaryBuilder::class)->rows([$failed, $llm, $rule]);

        $this->assertSame(
            ['ecommerce-order/HYBRID', 'simple-store/RULE', 'simple-store/LLM'],
            array_map(fn (array $row): string => "{$row['benchmark']}/{$row['strategy']}", $rows),
        );

        $this->assertSame(0, $rows[0]['repetitions']);
        $this->assertSame(0, $rows[0]['experiments_completed']);
        $this->assertSame(1, $rows[0]['experiments']);
        $this->assertNull($rows[0]['metrics']['bug_detection_rate']);

        $ruleRow = $rows[1];
        $this->assertSame([$rule->id], $ruleRow['experiment_ids']);
        $this->assertSame(3, $ruleRow['repetitions']);
        $this->assertSame(10, $ruleRow['bugs_total']);
        $this->assertEquals(['mean' => 0.5, 'std' => 0.1], $ruleRow['metrics']['bug_detection_rate']);
        $this->assertEquals(['mean' => 0.3, 'std' => 0.1], $ruleRow['metrics']['false_positive_rate']);
        $this->assertEquals(['mean' => 1.0, 'std' => 0.0], $ruleRow['metrics']['validity_rate']);
        $this->assertEquals(['mean' => 5.0, 'std' => 1.0], $ruleRow['metrics']['bugs_detected']);
        $this->assertEquals(['mean' => 10.0, 'std' => 0.0], $ruleRow['metrics']['generated']);
        $this->assertEquals(['mean' => 200.0, 'std' => 100.0], $ruleRow['metrics']['prompt_tokens']);

        $llmRow = $rows[2];
        $this->assertNull($llmRow['metrics']['false_positive_rate']);
        $this->assertNull($llmRow['metrics']['redundancy']);
        $this->assertEquals(['mean' => 0.0, 'std' => 0.0], $llmRow['metrics']['validity_rate']);
    }

    public function test_markdown_and_csv_summaries_are_readable_and_csv_injection_safe(): void
    {
        $benchmark = Benchmark::factory()->create(['slug' => 'simple-store']);
        $experiment = $this->experiment($benchmark, GenerationStrategy::Rule, $this->ruleRepetitions());
        $experiment->update(['config' => [...$experiment->config, 'model' => '=cmd|x']]);
        $experiment->refresh();
        $builder = app(ExperimentSummaryBuilder::class);

        $markdown = $builder->toMarkdown([$experiment], '2026-10-11 00:00:00');
        $this->assertStringContainsString('Xuất lúc (UTC): 2026-10-11 00:00:00', $markdown);
        $this->assertStringContainsString('| Benchmark | Chiến lược | Model | T | Lần lặp | VR | ER | FPR | BDR |', $markdown);
        $this->assertStringContainsString('| simple-store | RULE | =cmd\|x | 0.2 | 3 | 100.0 ± 0.0 | 100.0 ± 0.0 | 30.0 ± 10.0 | 50.0 ± 10.0 |', $markdown);
        $this->assertStringContainsString('| 2.0 ± 0.0 |', $markdown); // generation time in seconds
        $this->assertStringContainsString('| 10.0 ± 0.0 | 5.0 ± 1.0 / 10 |', $markdown);

        $csv = $builder->toCsv([$experiment]);
        $this->assertStringStartsWith(RunReportExporter::UTF8_BOM, $csv);
        $lines = array_map(
            fn (string $line): array => str_getcsv($line, ',', '"', ''),
            explode("\r\n", trim(substr($csv, strlen(RunReportExporter::UTF8_BOM)))),
        );
        $this->assertCount(2, $lines);
        $row = array_combine(array_map(fn (?string $cell): string => (string) $cell, $lines[0]), $lines[1]);
        $this->assertSame('simple-store', $row['benchmark']);
        $this->assertSame("'=cmd|x", $row['model']);
        $this->assertSame('3', $row['repetitions']);
        $this->assertSame('0.5', $row['bug_detection_rate_mean']);
        $this->assertSame('0.1', $row['bug_detection_rate_std']);

        // No experiment: header only.
        $this->assertCount(1, explode("\r\n", trim($builder->toCsv([]))));
        $this->assertStringContainsString('Không có thực nghiệm nào', $builder->toMarkdown([], 'x'));
    }

    public function test_export_command_writes_the_result_folder(): void
    {
        $benchmark = Benchmark::factory()->create(['slug' => 'simple-store']);
        $a = $this->experiment($benchmark, GenerationStrategy::Rule, $this->ruleRepetitions());
        $b = $this->experiment($benchmark, GenerationStrategy::Hybrid, [], ExperimentStatus::Failed);
        $ignored = $this->experiment($benchmark, GenerationStrategy::Llm, [], ExperimentStatus::Failed);

        $this->command('ait:experiments:export', ['ids' => ["{$a->id},{$b->id}"], '--dir' => $this->dir, '--summary' => true])
            ->expectsOutputToContain("Đã xuất 8 tệp (2 thực nghiệm) vào {$this->dir}.")
            ->expectsOutputToContain('| simple-store | RULE |')
            ->assertSuccessful();

        $files = array_map(fn (\SplFileInfo $f): string => $f->getFilename(), File::files($this->dir));
        sort($files);
        $this->assertSame([
            "experiment-{$a->id}.csv", "experiment-{$a->id}.json", "experiment-{$b->id}.csv", "experiment-{$b->id}.json",
            'experiments.csv', 'experiments.json', 'summary.csv', 'summary.md',
        ], $files);

        $all = json_decode((string) file_get_contents("{$this->dir}/experiments.json"), true);
        $this->assertIsArray($all);
        $this->assertSame([$a->id, $b->id], array_column($all['experiments'], 'id'));
        $this->assertNotContains($ignored->id, array_column($all['experiments'], 'id'));
        $single = json_decode((string) file_get_contents("{$this->dir}/experiment-{$a->id}.json"), true);
        $this->assertIsArray($single);
        $this->assertSame([$a->id], array_column($single['experiments'], 'id'));

        $summary = (string) file_get_contents("{$this->dir}/summary.md");
        $this->assertStringContainsString('| simple-store | HYBRID |', $summary);
        $this->assertStringContainsString('0 (0/1 thực nghiệm COMPLETED)', $summary);
    }

    public function test_export_command_rejects_invalid_ids_in_folder_mode(): void
    {
        $this->command('ait:experiments:export', ['ids' => ['abc'], '--dir' => $this->dir])
            ->expectsOutputToContain('ID thực nghiệm không hợp lệ: abc')
            ->assertFailed();
        $this->assertDirectoryDoesNotExist($this->dir);
    }
}
