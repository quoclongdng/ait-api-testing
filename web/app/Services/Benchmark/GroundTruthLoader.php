<?php

namespace App\Services\Benchmark;

use App\Data\Experiments\AuthFixture;
use App\Data\Experiments\GroundTruthBenchmark;
use App\Data\Experiments\GroundTruthBug;
use App\Enums\BenchmarkBugCategory;
use App\Enums\ErrorCode;
use App\Enums\HttpMethod;
use App\Exceptions\ApiException;
use App\Models\Benchmark;
use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the benchmark directory (D13): `<root>/<slug>/ground-truth.yaml` and `openapi.yaml`.
 *
 * YAML is parsed with Symfony's default flags: PHP objects and custom tags are rejected,
 * so a ground-truth file can only yield plain arrays and scalars (security checklist §9).
 */
class GroundTruthLoader
{
    private const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public function __construct(private readonly string $rootPath) {}

    public function rootPath(): string
    {
        return $this->rootPath;
    }

    /**
     * Slugs of every benchmark directory that ships a ground-truth file, sorted.
     *
     * @return list<string>
     */
    public function availableSlugs(): array
    {
        $files = glob(rtrim($this->rootPath, '/').'/*/ground-truth.yaml') ?: [];
        $slugs = [];
        foreach ($files as $file) {
            $slug = basename(dirname($file));
            if (preg_match(self::SLUG_PATTERN, $slug) === 1) {
                $slugs[] = $slug;
            }
        }
        sort($slugs);

        return $slugs;
    }

    /**
     * @throws ApiException
     */
    public function load(string $slug): GroundTruthBenchmark
    {
        $path = $this->pathFor($slug, 'ground-truth.yaml');
        if ($path === null) {
            throw new ApiException(ErrorCode::NotFound, "Không tìm thấy ground-truth.yaml cho benchmark '{$slug}'.", ['slug' => $slug]);
        }

        return $this->parse((string) file_get_contents($path), $slug);
    }

    /**
     * Raw OpenAPI document of a benchmark, or null when the file does not exist.
     */
    public function openApiContent(string $slug): ?string
    {
        $path = $this->pathFor($slug, 'openapi.yaml');

        return $path === null ? null : (string) file_get_contents($path);
    }

    /**
     * @throws ApiException
     */
    public function parse(string $content, string $expectedSlug): GroundTruthBenchmark
    {
        try {
            $data = Yaml::parse($content);
        } catch (ParseException $e) {
            throw $this->invalid($expectedSlug, 'YAML không hợp lệ: '.$e->getMessage());
        }

        if (! is_array($data)) {
            throw $this->invalid($expectedSlug, 'Tệp ground truth phải là một object YAML.');
        }

        $slug = is_string($data['benchmark'] ?? null) ? $data['benchmark'] : $expectedSlug;
        if ($slug !== $expectedSlug) {
            throw $this->invalid($expectedSlug, "Trường benchmark '{$slug}' không khớp thư mục '{$expectedSlug}'.");
        }

        $baseUrl = $data['base_url'] ?? null;
        if (! is_string($baseUrl) || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw $this->invalid($expectedSlug, 'Thiếu hoặc sai base_url.');
        }

        $version = Benchmark::normalizeVersion(is_scalar($data['version'] ?? null) ? (string) $data['version'] : '1.0');

        $rawBugs = $data['bugs'] ?? [];
        if (! is_array($rawBugs)) {
            throw $this->invalid($expectedSlug, 'Trường bugs phải là danh sách.');
        }

        $bugs = [];
        foreach (array_values($rawBugs) as $index => $raw) {
            $bugs[] = $this->parseBug($raw, $index, $expectedSlug);
        }

        $auth = null;
        if (array_key_exists('auth', $data) && $data['auth'] !== null) {
            try {
                // Snapshot form: every role must carry its synthetic sandbox token (ADR-0006).
                $auth = AuthFixture::fromArray($data['auth'], requireTokens: true);
            } catch (InvalidArgumentException $e) {
                throw $this->invalid($expectedSlug, $e->getMessage());
            }
        }

        return new GroundTruthBenchmark(
            slug: $slug,
            name: is_string($data['name'] ?? null) && $data['name'] !== '' ? mb_substr($data['name'], 0, 255) : $slug,
            description: is_string($data['description'] ?? null) ? $data['description'] : null,
            baseUrl: rtrim($baseUrl, '/'),
            version: mb_substr($version, 0, 20),
            bugs: $bugs,
            auth: $auth,
            businessRules: $this->parseBusinessRules($data['business_rules'] ?? [], $expectedSlug),
        );
    }

    /**
     * ADR-0006 business rules: expected clean behaviour a tester can read in the spec/README.
     *
     * @return list<array{code: string, description: string, method: string|null, path: string|null}>
     */
    private function parseBusinessRules(mixed $raw, string $slug): array
    {
        if ($raw === null) {
            return [];
        }
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw $this->invalid($slug, 'Trường business_rules phải là danh sách.');
        }

        $rules = [];
        $codes = [];
        foreach ($raw as $index => $rule) {
            $code = is_array($rule) ? ($rule['code'] ?? null) : null;
            $description = is_array($rule) ? ($rule['description'] ?? null) : null;
            if (! is_string($code) || $code === '' || mb_strlen($code) > 50
                || ! is_string($description) || $description === '' || mb_strlen($description) > 5000) {
                throw $this->invalid($slug, "business_rules[{$index}] thiếu code (≤ 50 ký tự) hoặc description (≤ 5000 ký tự).");
            }
            if (isset($codes[$code])) {
                throw $this->invalid($slug, "business_rules[{$index}]: mã {$code} bị trùng.");
            }
            $codes[$code] = true;

            $method = is_string($rule['method'] ?? null) ? HttpMethod::tryFrom(strtoupper($rule['method'])) : null;
            $path = $rule['path'] ?? null;
            $hasOperation = array_key_exists('method', $rule) || array_key_exists('path', $rule);
            if ($hasOperation && ($method === null || ! is_string($path) || ! str_starts_with($path, '/'))) {
                throw $this->invalid($slug, "business_rules[{$index}] có method/path không hợp lệ.");
            }

            $rules[] = [
                'code' => $code,
                'description' => $description,
                'method' => $method?->value,
                'path' => $hasOperation ? $path : null,
            ];
        }

        return $rules;
    }

    private function parseBug(mixed $raw, int $index, string $slug): GroundTruthBug
    {
        if (! is_array($raw)) {
            throw $this->invalid($slug, "Bug thứ {$index} không phải object.");
        }

        $code = $raw['code'] ?? null;
        $method = is_string($raw['method'] ?? null) ? HttpMethod::tryFrom(strtoupper($raw['method'])) : null;
        $category = is_string($raw['category'] ?? null) ? BenchmarkBugCategory::tryFrom($raw['category']) : null;
        $path = $raw['path'] ?? null;
        $toggle = $raw['toggle_env'] ?? ($raw['toggle_flag'] ?? null);

        if (! is_string($code) || $code === '' || $method === null || $category === null
            || ! is_string($path) || ! str_starts_with($path, '/') || ! is_string($toggle) || $toggle === '') {
            throw $this->invalid($slug, "Bug thứ {$index} thiếu code/method/path/category/toggle_env hợp lệ.");
        }

        return new GroundTruthBug(
            code: mb_substr($code, 0, 50),
            method: $method,
            path: mb_substr($path, 0, 500),
            category: $category,
            description: is_string($raw['description'] ?? null) ? $raw['description'] : $code,
            toggleFlag: mb_substr($toggle, 0, 100),
        );
    }

    private function pathFor(string $slug, string $file): ?string
    {
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            return null;
        }

        $path = rtrim($this->rootPath, '/').'/'.$slug.'/'.$file;

        return is_file($path) ? $path : null;
    }

    private function invalid(string $slug, string $message): ApiException
    {
        return new ApiException(ErrorCode::ValidationFailed, "Ground truth của '{$slug}' không hợp lệ: {$message}", ['slug' => $slug]);
    }
}
