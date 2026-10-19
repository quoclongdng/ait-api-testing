<?php

namespace Tests\Feature\Experiments;

use App\Data\Experiments\RepetitionObservation;
use App\Enums\BenchmarkBugCategory;
use App\Enums\ExperimentStatus;
use App\Enums\GenerationStrategy;
use App\Models\Benchmark;
use App\Models\BenchmarkBug;
use App\Models\Experiment;
use App\Models\ExperimentBugDetection;
use App\Models\TestRun;
use App\Models\User;
use App\Services\Experiments\ExperimentMetricsCalculator;
use App\Services\Reports\RunReportExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class ExperimentExportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function command(string $name, array $parameters = []): PendingCommand
    {
        $command = $this->artisan($name, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    private function completedExperiment(): Experiment
    {
        $benchmark = Benchmark::factory()->create(['slug' => 'simple-store', 'version' => 'v1.0', 'name' => 'Simple Store']);
        $bug1 = BenchmarkBug::factory()->create(['benchmark_id' => $benchmark->id, 'code' => 'BUG-001', 'category' => BenchmarkBugCategory::ValidationMissing]);
        $bug2 = BenchmarkBug::factory()->create(['benchmark_id' => $benchmark->id, 'code' => 'BUG-002', 'category' => BenchmarkBugCategory::BoundaryError]);
        $clean = TestRun::factory()->create();
        $bugRun = TestRun::factory()->create(['test_suite_id' => $clean->test_suite_id]);

        $experiment = Experiment::factory()->create([
            'name' => '=HYPERLINK("x") thực nghiệm',
            'benchmark_id' => $benchmark->id,
            'strategy' => GenerationStrategy::Llm,
            'status' => ExperimentStatus::Completed,
            'config' => ['model' => 'qwen2.5-coder:7b', 'seed' => 42, 'seeds' => [42, 1024, 2026], 'temperature' => 0.2, 'repetitions' => 3],
            'metrics' => app(ExperimentMetricsCalculator::class)->aggregate([new RepetitionObservation(
                repetition: 1, seed: 42, generated: 10, valid: 9, cleanPass: 9, totalBugs: 2, detectedBugs: 1,
                totalMutants: 2, killedMutants: 1, promptTokens: 100,
            )]),
            'repetitions_data' => [[
                'repetition' => 1, 'seed' => 42, 'generation_job_id' => 11, 'test_suite_id' => $clean->test_suite_id,
                'clean_test_run_id' => $clean->id, 'bug_runs' => ['BUG-001' => $bugRun->id, 'BUG-002' => $bugRun->id],
            ]],
        ]);
        foreach ([[$bug1, true, [5, 6]], [$bug2, false, []]] as [$bug, $detected, $cases]) {
            ExperimentBugDetection::factory()->create([
                'experiment_id' => $experiment->id,
                'benchmark_bug_id' => $bug->id,
                'repetition' => 1,
                'detected' => $detected,
                'clean_test_run_id' => $clean->id,
                'bug_test_run_id' => $bugRun->id,
                'detecting_test_case_ids' => $cases,
            ]);
        }

        return $experiment;
    }

    public function test_json_export_contains_config_repetitions_detections_and_metrics(): void
    {
        $experiment = $this->completedExperiment();
        $tester = User::factory()->tester()->create();

        $response = $this->actingAs($tester)->get("/api/v1/experiments/{$experiment->id}/export?format=json");

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/json; charset=UTF-8')
            ->assertHeader('Content-Disposition', "attachment; filename=\"experiment-{$experiment->id}-export.json\"")
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $doc = json_decode((string) $response->getContent(), true);
        $this->assertSame('1.0', $doc['format_version']);
        $this->assertCount(1, $doc['experiments']);
        $exported = $doc['experiments'][0];
        $this->assertSame($experiment->id, $exported['id']);
        $this->assertSame('LLM', $exported['strategy']);
        $this->assertSame(['id' => $experiment->benchmark_id, 'slug' => 'simple-store', 'name' => 'Simple Store', 'version' => 'v1.0', 'bugs_total' => 2], $exported['benchmark']);
        $this->assertSame(['model' => 'qwen2.5-coder:7b', 'temperature' => 0.2, 'seed' => 42, 'seeds' => [42, 1024, 2026], 'repetitions' => 3, 'auth' => null, 'business_rules' => null, 'reset_per_case' => false], $exported['config']);
        $this->assertEquals(0.9, $exported['metrics']['validity_rate']);
        $this->assertSame(42, $exported['repetitions'][0]['seed']);
        $this->assertSame(10, $exported['repetitions'][0]['metrics']['generated']);
        $this->assertSame(['BUG-001', 'BUG-002'], array_column($exported['bug_detections'], 'bug_code'));
        $this->assertSame([true, false], array_column($exported['bug_detections'], 'detected'));
        $this->assertSame([5, 6], $exported['bug_detections'][0]['detecting_test_case_ids']);
        $this->assertSame('VALIDATION_MISSING', $exported['bug_detections'][0]['category']);
    }

    public function test_json_export_matches_the_openapi_contract(): void
    {
        $experiment = $this->completedExperiment();
        $running = Experiment::factory()->create([
            'status' => ExperimentStatus::Running,
            'metrics' => null,
            'repetitions_data' => [['repetition' => 1, 'seed' => 42, 'generation_job_id' => 3, 'test_suite_id' => 4, 'clean_test_run_id' => null, 'bug_runs' => []]],
        ]);
        $tester = User::factory()->tester()->create();

        $validator = new Validator;
        $validator->resolver()?->registerRaw(
            (string) json_encode(Yaml::parseFile(base_path('../docs/contracts/web-api.openapi.yaml'))),
            'https://ait.local/web-api.json',
        );

        foreach ([$experiment, $running] as $item) {
            $content = (string) $this->actingAs($tester)->get("/api/v1/experiments/{$item->id}/export?format=json")->assertOk()->getContent();
            $result = $validator->validate(json_decode($content), 'https://ait.local/web-api.json#/components/schemas/ExperimentExport');
            $this->assertTrue($result->isValid(), (string) json_encode($result->error() !== null ? (new ErrorFormatter)->format($result->error()) : null));
        }
    }

    public function test_csv_export_is_a_long_table_safe_against_formula_injection(): void
    {
        $experiment = $this->completedExperiment();
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get("/api/v1/experiments/{$experiment->id}/export?format=csv");

        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $content = (string) $response->getContent();
        $this->assertStringStartsWith(RunReportExporter::UTF8_BOM, $content);
        $rows = array_map(fn (string $line): array => str_getcsv($line, ',', '"', ''), array_values(array_filter(explode("\r\n", substr($content, 3)))));

        $this->assertSame([
            'experiment_id', 'experiment_name', 'benchmark', 'benchmark_version', 'strategy', 'status',
            'model', 'temperature', 'repetitions', 'record_type', 'repetition', 'seed', 'bug_code',
            'bug_category', 'key', 'value',
        ], $rows[0]);
        $body = array_slice($rows, 1);
        $this->assertSame("'=HYPERLINK(\"x\") thực nghiệm", $body[0][1]);

        $find = fn (string $type, string $key, string $bug = ''): ?array => collect($body)
            ->first(fn (array $r): bool => $r[9] === $type && $r[14] === $key && $r[12] === $bug);
        $this->assertSame('0.9', $find('metric', 'validity_rate')[15] ?? null);
        $this->assertSame('0', $find('stddev', 'validity_rate')[15] ?? null);
        $this->assertSame(['1', '42', '10'], [$find('repetition', 'generated')[10] ?? null, $find('repetition', 'generated')[11] ?? null, $find('repetition', 'generated')[15] ?? null]);
        $this->assertSame('1', $find('detection', 'detected', 'BUG-001')[15] ?? null);
        $this->assertSame('0', $find('detection', 'detected', 'BUG-002')[15] ?? null);
        $this->assertSame('5;6', $find('detection', 'detecting_test_case_ids', 'BUG-001')[15] ?? null);
        $this->assertSame('qwen2.5-coder:7b', $body[0][6]);
        $this->assertSame('0.2', $body[0][7]);
    }

    public function test_export_rejects_unknown_format_and_guests(): void
    {
        $experiment = $this->completedExperiment();
        $tester = User::factory()->tester()->create();

        $this->actingAs($tester)->getJson("/api/v1/experiments/{$experiment->id}/export?format=html")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(['format'], 'error.details');
        $this->actingAs($tester)->getJson("/api/v1/experiments/{$experiment->id}/export")->assertStatus(422);
        $this->actingAs($tester)->getJson('/api/v1/experiments/999999/export?format=json')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_guest_cannot_export(): void
    {
        $experiment = $this->completedExperiment();

        $this->getJson("/api/v1/experiments/{$experiment->id}/export?format=json")
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_failed_experiment_exports_its_error(): void
    {
        $experiment = Experiment::factory()->create(['status' => ExperimentStatus::Failed, 'metrics' => ['error' => 'sandbox down'], 'repetitions_data' => null]);
        $tester = User::factory()->tester()->create();

        $csv = (string) $this->actingAs($tester)->get("/api/v1/experiments/{$experiment->id}/export?format=csv")->assertOk()->getContent();
        $this->assertStringContainsString('error,,,,,error,"sandbox down"', $csv);

        $json = $this->actingAs($tester)->get("/api/v1/experiments/{$experiment->id}/export?format=json")->assertOk()->json();
        $this->assertSame('sandbox down', $json['experiments'][0]['metrics']['error']);
        $this->assertSame([], $json['experiments'][0]['repetitions']);
    }

    public function test_artisan_export_writes_json_and_csv_files(): void
    {
        $experiment = $this->completedExperiment();
        $other = Experiment::factory()->create();
        $dir = sys_get_temp_dir().'/ait-export-'.uniqid();
        $jsonPath = "{$dir}/nested/all.json";

        $this->command('ait:experiments:export', ['--format' => 'json', '--output' => $jsonPath])
            ->assertSuccessful();
        $doc = json_decode((string) file_get_contents($jsonPath), true);
        $this->assertSame([$experiment->id, $other->id], array_column($doc['experiments'], 'id'));

        $csvPath = "{$dir}/one.csv";
        $this->command('ait:experiments:export', ['ids' => [(string) $experiment->id], '--format' => 'csv', '--output' => $csvPath])
            ->assertSuccessful();
        $csv = (string) file_get_contents($csvPath);
        $this->assertStringContainsString('detecting_test_case_ids,5;6', $csv);
        $dataRows = array_slice(array_values(array_filter(explode("\r\n", $csv))), 1);
        $this->assertNotEmpty($dataRows);
        foreach ($dataRows as $row) {
            $this->assertStringStartsWith("{$experiment->id},", $row);
        }

        $this->command('ait:experiments:export', ['--format' => 'xml'])->assertFailed();
        $this->command('ait:experiments:export', ['ids' => ['abc']])->assertFailed();

        @unlink($jsonPath);
        @unlink($csvPath);
        @rmdir("{$dir}/nested");
        @rmdir($dir);
    }

    public function test_artisan_export_prints_to_stdout_without_output_option(): void
    {
        $experiment = $this->completedExperiment();

        $this->command('ait:experiments:export', ['ids' => [(string) $experiment->id]])
            ->expectsOutputToContain('"format_version": "1.0"')
            ->assertSuccessful();
    }

    /**
     * Regression (S10-01 audit round 3): benchmark.bugs_total counted every benchmark_bugs row,
     * including stale rows no longer in ground-truth.yaml, while metrics.bugs_total (the planned D14
     * denominator) excludes them, so the two numbers of one export disagreed.
     */
    public function test_benchmark_bugs_total_is_the_planned_bug_count_not_the_db_row_count(): void
    {
        $experiment = $this->completedExperiment();
        BenchmarkBug::factory()->create(['benchmark_id' => $experiment->benchmark_id, 'code' => 'BUG-STALE']);
        $tester = User::factory()->tester()->create();

        $exported = $this->actingAs($tester)->get("/api/v1/experiments/{$experiment->id}/export?format=json")->assertOk()->json('experiments.0');
        $this->assertSame(2, $exported['metrics']['bugs_total']);
        $this->assertSame(2, $exported['benchmark']['bugs_total']);

        // Without aggregated metrics (still RUNNING), the bugs that already have detections count.
        $experiment->update(['status' => ExperimentStatus::Running, 'metrics' => null]);
        $exported = $this->actingAs($tester)->get("/api/v1/experiments/{$experiment->id}/export?format=json")->assertOk()->json('experiments.0');
        $this->assertSame(2, $exported['benchmark']['bugs_total']);
    }

    /**
     * Regression (S10-01 audit round 3): the export only carried the last generation job of a
     * repetition, so the RULE seed job of a HYBRID repetition (whose cases the metrics include) could
     * not be traced. The CSV long table and, since ADR-0006, the JSON export list every job.
     */
    public function test_csv_export_lists_every_generation_job_of_a_repetition(): void
    {
        $experiment = $this->completedExperiment();
        $data = $experiment->repetitions_data ?? [];
        $data[0]['generation_job_id'] = 12;
        $data[0]['generation_job_ids'] = [11, 12];
        $experiment->update(['strategy' => GenerationStrategy::Hybrid, 'repetitions_data' => $data]);
        $tester = User::factory()->tester()->create();

        $csv = (string) $this->actingAs($tester)->get("/api/v1/experiments/{$experiment->id}/export?format=csv")->assertOk()->getContent();
        $this->assertStringContainsString('repetition,1,42,,,generation_job_ids,11;12', $csv);
        $this->assertStringContainsString('repetition,1,42,,,generation_job_id,12', $csv);

        $json = (string) $this->actingAs($tester)->get("/api/v1/experiments/{$experiment->id}/export?format=json")->assertOk()->getContent();
        $this->assertSame([11, 12], json_decode($json, true)['experiments'][0]['repetitions'][0]['generation_job_ids']);
        $validator = new Validator;
        $validator->resolver()?->registerRaw(
            (string) json_encode(Yaml::parseFile(base_path('../docs/contracts/web-api.openapi.yaml'))),
            'https://ait.local/web-api.json',
        );
        $result = $validator->validate(json_decode($json), 'https://ait.local/web-api.json#/components/schemas/ExperimentExport');
        $this->assertTrue($result->isValid(), (string) json_encode($result->error() !== null ? (new ErrorFormatter)->format($result->error()) : null));

        // Entries written before generation_job_ids existed fall back to generation_job_id.
        unset($data[0]['generation_job_ids']);
        $experiment->update(['repetitions_data' => $data]);
        $csv = (string) $this->actingAs($tester)->get("/api/v1/experiments/{$experiment->id}/export?format=csv")->assertOk()->getContent();
        $this->assertStringContainsString('repetition,1,42,,,generation_job_ids,12', $csv);
    }
}
