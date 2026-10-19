<?php

namespace Tests\Unit\Services;

use App\Enums\GenerationStrategy;
use App\Enums\LlmPurpose;
use App\Services\Benchmark\GroundTruthLoader;
use App\Services\Benchmark\RecordedLlmJobLoader;
use App\Services\Support\PgDumpReader;
use RuntimeException;
use Tests\TestCase;

/**
 * S13-03: the demo replays a recorded LLM job of the official experiment from its pg_dump.
 */
class RecordedLlmJobLoaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/ait-dump-'.bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tmpDir);
        parent::tearDown();
    }

    public function test_the_reader_decodes_copy_text_format_of_the_requested_tables_only(): void
    {
        $path = $this->gz("SET x = 1;\n"
            ."COPY public.things (id, name, note) FROM stdin;\n"
            ."1\tTab\\there\t\\N\n"
            ."2\tline\\nbreak \\\\ slash\t{\"a\": \"\\\\u0041\"}\n"
            ."\\.\n"
            ."COPY public.other (id) FROM stdin;\n"
            ."9\n"
            ."\\.\n");

        $tables = (new PgDumpReader)->tables($path, ['things', 'missing']);

        $this->assertSame([
            ['id' => '1', 'name' => "Tab\there", 'note' => null],
            ['id' => '2', 'name' => "line\nbreak \\ slash", 'note' => '{"a": "\\u0041"}'],
        ], $tables['things']);
        $this->assertSame([], $tables['missing']);
        $this->assertArrayNotHasKey('other', $tables);
    }

    public function test_the_reader_rejects_a_missing_dump(): void
    {
        $this->expectException(RuntimeException::class);

        (new PgDumpReader)->tables($this->tmpDir.'/none.sql.gz', ['things']);
    }

    public function test_it_rebuilds_the_ai_service_response_of_the_official_llm_job_seed_42(): void
    {
        if (! is_file(app(RecordedLlmJobLoader::class)->dumpPath('simple-store'))) {
            $this->markTestSkipped('The database dumps of benchmark/results/benchmark-v1.0 are not part of this release.');
        }
        $loader = app(RecordedLlmJobLoader::class);
        $this->assertFileExists($loader->dumpPath('simple-store'));

        $job = $loader->load('simple-store', GenerationStrategy::Llm, 42);

        $this->assertSame(GenerationStrategy::Llm, $job->strategy);
        $this->assertSame(['model' => 'qwen2.5-coder:7b', 'temperature' => 0.2, 'seed' => 42, 'max_cases_per_endpoint' => 10, 'auth' => null], $job->params);
        $this->assertSame(24, $job->response->stats->generated);
        $this->assertCount(24, $job->response->testCases);
        $this->assertCount(15, $job->response->llmCalls);
        $this->assertSame(LlmPurpose::Generate, $job->response->llmCalls[0]->purpose);
        $this->assertSame('system', $job->response->llmCalls[0]->prompt[0]['role']);
        $this->assertSame(['VALID' => 23, 'INVALID' => 1], array_count_values(array_map(fn ($case): string => $case->status, $job->response->testCases)));
        $this->assertSame('Health check returns 200 with valid data', $job->response->testCases[0]->dsl['title'] ?? null);
    }

    public function test_it_fails_clearly_when_the_dump_has_no_such_job(): void
    {
        if (! is_file(app(RecordedLlmJobLoader::class)->dumpPath('simple-store'))) {
            $this->markTestSkipped('The database dumps of benchmark/results/benchmark-v1.0 are not part of this release.');
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('seed 7');

        app(RecordedLlmJobLoader::class)->load('simple-store', GenerationStrategy::Llm, 7);
    }

    public function test_the_dump_path_is_under_the_benchmark_directory(): void
    {
        $loader = new RecordedLlmJobLoader(new GroundTruthLoader('/srv/benchmark/'), new PgDumpReader);

        $this->assertSame('/srv/benchmark/results/benchmark-v1.0/ecommerce-order/db.sql.gz', $loader->dumpPath('ecommerce-order'));
    }

    private function gz(string $content): string
    {
        $path = $this->tmpDir.'/dump.sql.gz';
        file_put_contents($path, gzencode($content));

        return $path;
    }
}
