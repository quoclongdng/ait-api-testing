<?php

namespace App\Services\Demo;

use App\Data\Generation\RecordedLlmJob;
use App\Enums\GenerationStrategy;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use stdClass;

/**
 * Compact fixture of the official results `benchmark-v1.0` for the demo showcase (S14-05):
 * `web/database/demo/benchmark-v1.0.json.gz`, generated from benchmark/results/benchmark-v1.0/<slug>/
 * (db.sql.gz + experiments.json) by `php artisan ait:demo:build-fixture`. The production image ships
 * this file (a few hundred KB) instead of the database dumps.
 *
 * Per benchmark it holds:
 * - `recorded_jobs`: the LLM and HYBRID jobs seed 42 (ai-service response with verbatim llm_calls);
 * - `experiments`: the 3 official experiments (config, metrics, per-repetition clean/bug run totals,
 *   D14 detections by bug code).
 *
 * The file is decoded with objects so every `{}` of a DSL stays an object when it is encoded again.
 */
class DemoShowcaseFixture
{
    public const FORMAT_VERSION = 1;

    private ?stdClass $document = null;

    public function __construct(private readonly string $path) {}

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Recorded LLM/HYBRID jobs of a benchmark, keyed by strategy value.
     *
     * @return array<string, RecordedLlmJob>
     *
     * @throws RuntimeException When the fixture is missing, unreadable or invalid.
     */
    public function recordedJobs(string $slug): array
    {
        $jobs = [];
        foreach ($this->list($this->benchmark($slug), 'recorded_jobs') as $node) {
            $strategy = GenerationStrategy::tryFrom(is_string($node->strategy ?? null) ? $node->strategy : '');
            if ($strategy === null || ! is_int($node->source_job_id ?? null) || ! ($node->response ?? null) instanceof stdClass) {
                throw new RuntimeException("Fixture {$this->path}: job bản ghi của {$slug} không hợp lệ.");
            }

            try {
                $jobs[$strategy->value] = RecordedLlmJob::fromRecord(
                    $node->source_job_id,
                    $strategy,
                    $this->toArray($node->params ?? new stdClass),
                    json_encode($node->response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                );
            } catch (JsonException|InvalidArgumentException $e) {
                throw new RuntimeException("Fixture {$this->path}: job {$node->source_job_id} của {$slug} không đọc được: {$e->getMessage()}", 0, $e);
            }
        }

        return $jobs;
    }

    /**
     * The official experiments of a benchmark (plain arrays, ordered by source id).
     *
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException
     */
    public function experiments(string $slug): array
    {
        return array_map(fn (mixed $node): array => $this->toArray($node), $this->list($this->benchmark($slug), 'experiments'));
    }

    /**
     * @return list<string>
     *
     * @throws RuntimeException
     */
    public function slugs(): array
    {
        return array_keys($this->toArray($this->document()->benchmarks ?? new stdClass));
    }

    private function benchmark(string $slug): stdClass
    {
        $benchmarks = $this->document()->benchmarks ?? null;
        $node = $benchmarks instanceof stdClass ? ($benchmarks->{$slug} ?? null) : null;
        if (! $node instanceof stdClass) {
            throw new RuntimeException("Fixture {$this->path} không có benchmark {$slug}.");
        }

        return $node;
    }

    /**
     * @return list<mixed>
     */
    private function list(stdClass $node, string $key): array
    {
        $value = $node->{$key} ?? [];
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException("Fixture {$this->path}: `{$key}` phải là danh sách.");
        }

        return $value;
    }

    private function document(): stdClass
    {
        if ($this->document instanceof stdClass) {
            return $this->document;
        }

        $raw = is_file($this->path) ? @file_get_contents($this->path) : false;
        $json = is_string($raw) ? @gzdecode($raw) : false;
        if (! is_string($json)) {
            throw new RuntimeException("Không đọc được fixture demo {$this->path} (chạy php artisan ait:demo:build-fixture).");
        }

        try {
            $document = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Fixture demo {$this->path} không phải JSON hợp lệ: {$e->getMessage()}", 0, $e);
        }
        if (! $document instanceof stdClass || ($document->format_version ?? null) !== self::FORMAT_VERSION) {
            throw new RuntimeException("Fixture demo {$this->path} không đúng định dạng phiên bản ".self::FORMAT_VERSION.'.');
        }

        return $this->document = $document;
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(mixed $node): array
    {
        $array = json_decode((string) json_encode($node), true);

        return is_array($array) ? $array : [];
    }
}
