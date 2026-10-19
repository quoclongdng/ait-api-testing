<?php

namespace App\Services\Benchmark;

use App\Data\Generation\RecordedLlmJob;
use App\Enums\GenerationStrategy;
use App\Services\Support\PgDumpReader;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Rebuilds the ai-service response of one LLM/HYBRID generation job of the official experiment
 * (`benchmark/results/benchmark-v1.0/<slug>/db.sql.gz`, S11-02) so the demo can show real LLM
 * cases and their verbatim llm_call_logs without calling an LLM (S13-03).
 *
 * The response is the one ai-service returned (cases with their ai-service status, repair count and
 * validation errors, every GENERATE/REPAIR call, stats): it goes through the same web validation,
 * dedup and persistence as a live job (D04), only the LLM call is replayed.
 */
class RecordedLlmJobLoader
{
    public const RESULTS_DIR = 'results/benchmark-v1.0';

    public function __construct(
        private readonly GroundTruthLoader $groundTruth,
        private readonly PgDumpReader $reader,
    ) {}

    public function dumpPath(string $slug): string
    {
        return rtrim($this->groundTruth->rootPath(), '/').'/'.self::RESULTS_DIR.'/'.$slug.'/db.sql.gz';
    }

    /**
     * The first (lowest id) finished job of `$strategy` that ran with `$seed`.
     *
     * @throws RuntimeException When the dump is missing or holds no such job.
     */
    public function load(string $slug, GenerationStrategy $strategy, int $seed): RecordedLlmJob
    {
        $record = $this->record($slug, $strategy, $seed);

        try {
            return RecordedLlmJob::fromRecord(
                $record['source_job_id'],
                $strategy,
                $record['params'],
                json_encode($record['response'], JSON_THROW_ON_ERROR),
            );
        } catch (JsonException|InvalidArgumentException $e) {
            throw new RuntimeException("Job {$record['source_job_id']} trong {$this->dumpPath($slug)} không đọc được: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * JSON-ready record of that job: its params and the ai-service response it received. DSLs and
     * stats are decoded as objects so `{}` stays an object when the record is encoded again (the
     * showcase fixture, php artisan ait:demo:build-fixture, S14-05).
     *
     * @return array{source_job_id: int, strategy: string, params: array<string, mixed>, response: array{test_cases: list<array<string, mixed>>, llm_calls: list<array<string, mixed>>, stats: mixed}}
     *
     * @throws RuntimeException When the dump is missing or holds no such job.
     */
    public function record(string $slug, GenerationStrategy $strategy, int $seed): array
    {
        $path = $this->dumpPath($slug);
        $tables = $this->reader->tables($path, ['generation_jobs', 'test_cases', 'llm_call_logs']);

        $job = null;
        foreach ($tables['generation_jobs'] as $row) {
            $params = $this->json($row['params']);
            if ($row['strategy'] === $strategy->value
                && in_array($row['status'], ['SUCCEEDED', 'PARTIAL'], true)
                && is_array($params) && ($params['seed'] ?? null) === $seed
                && ($job === null || (int) $row['id'] < (int) $job['id'])) {
                $job = $row;
            }
        }
        if ($job === null) {
            throw new RuntimeException("Dump {$path} không có job {$strategy->value} seed {$seed} đã hoàn thành.");
        }

        $jobId = (string) $job['id'];
        $cases = $this->rowsOfJob($tables['test_cases'], $jobId);
        $calls = $this->rowsOfJob($tables['llm_call_logs'], $jobId);
        $params = $this->json($job['params']);
        $params = is_array($params) ? $params : [];

        return [
            'source_job_id' => (int) $jobId,
            'strategy' => $strategy->value,
            // Only what the replay needs; `auth` is the public form (role names, never tokens).
            'params' => array_intersect_key($params, array_flip(['model', 'temperature', 'seed', 'max_cases_per_endpoint', 'auth'])),
            'response' => [
                'test_cases' => array_map(fn (array $row): array => [
                    'dsl' => $this->json($row['dsl'], false),
                    'status' => $row['status'],
                    'validation_errors' => $this->json($row['validation_errors']) ?? [],
                    'repair_attempts' => (int) $row['repair_attempts'],
                    'fingerprint' => $row['status'] === 'INVALID' ? null : $row['fingerprint'],
                ], $cases),
                'llm_calls' => array_map(fn (array $row): array => [
                    'purpose' => $row['purpose'],
                    'provider' => $row['provider'],
                    'model' => $row['model'],
                    'prompt' => $this->json($row['prompt']),
                    'response_raw' => (string) $row['response_raw'],
                    'temperature' => (float) $row['temperature'],
                    'seed' => $row['seed'] === null ? null : (int) $row['seed'],
                    'prompt_tokens' => $row['prompt_tokens'] === null ? null : (int) $row['prompt_tokens'],
                    'completion_tokens' => $row['completion_tokens'] === null ? null : (int) $row['completion_tokens'],
                    'latency_ms' => (int) $row['latency_ms'],
                    'error' => $row['error'],
                ], $calls),
                'stats' => $this->json($job['stats'], false),
            ],
        ];
    }

    /**
     * @param  list<array<string, string|null>>  $rows
     * @return list<array<string, string|null>> Rows of the job in id order (creation order).
     */
    private function rowsOfJob(array $rows, string $jobId): array
    {
        $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['generation_job_id'] === $jobId));
        usort($rows, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

        return $rows;
    }

    private function json(?string $text, bool $associative = true): mixed
    {
        if ($text === null) {
            return null;
        }

        try {
            return json_decode($text, $associative, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Giá trị jsonb trong dump không hợp lệ: '.$e->getMessage(), 0, $e);
        }
    }
}
