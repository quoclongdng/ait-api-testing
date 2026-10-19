<?php

namespace Tests\Unit\Services\Experiments;

use App\Enums\BenchmarkBugCategory;
use App\Enums\HttpMethod;
use App\Exceptions\ApiException;
use App\Services\Benchmark\GroundTruthLoader;
use PHPUnit\Framework\TestCase;

class GroundTruthLoaderTest extends TestCase
{
    private function loader(): GroundTruthLoader
    {
        return new GroundTruthLoader(dirname(__DIR__, 5).'/benchmark');
    }

    public function test_lists_benchmarks_shipping_a_ground_truth_file(): void
    {
        $this->assertSame(['appointment-booking', 'ecommerce-order', 'simple-store'], $this->loader()->availableSlugs());
    }

    public function test_loads_real_simple_store_ground_truth(): void
    {
        $doc = $this->loader()->load('simple-store');

        $this->assertSame('simple-store', $doc->slug);
        $this->assertSame('v1.0', $doc->version);
        $this->assertSame('http://simple-store:8000', $doc->baseUrl);
        $this->assertCount(10, $doc->bugs);
        $this->assertSame('BUG-001', $doc->bugs[0]->code);
        $this->assertSame(HttpMethod::Post, $doc->bugs[0]->method);
        $this->assertSame(BenchmarkBugCategory::ValidationMissing, $doc->bugs[0]->category);
        $this->assertSame('BUG_001', $doc->bugs[0]->toggleFlag);
    }

    /**
     * S10-01: base_url is the clean-variant URL used by the worker container, so it must be the
     * internal Docker service (not a host port on localhost) and be on the default allowlist (D07).
     */
    public function test_ground_truth_base_urls_are_reachable_docker_services_on_default_allowlist(): void
    {
        $web = dirname(__DIR__, 4);
        // Default of config/ait.php (env fallback); web/.env.example documents a superset that also
        // lists every per-bug sandbox host (S12-02).
        $configDefault = $this->firstCapture("/env\\('ALLOWED_TARGET_HOSTS', '([^']+)'\\)/", $web.'/config/ait.php');
        $allowlist = array_map('trim', explode(',', $configDefault));
        $envExample = array_map('trim', explode(',', $this->firstCapture('/^ALLOWED_TARGET_HOSTS=(.+)$/m', $web.'/.env.example')));
        $this->assertSame([], array_values(array_diff($allowlist, $envExample)));
        // Clean variant = network alias of the clean Docker service (gen_variant_compose.py).
        $expected = [
            'appointment-booking' => 'http://appointment-booking:8000',
            'ecommerce-order' => 'http://ecommerce-order:8000',
            'simple-store' => 'http://simple-store:8000',
        ];

        $loader = $this->loader();
        foreach ($loader->availableSlugs() as $slug) {
            $baseUrl = $loader->load($slug)->baseUrl;
            $this->assertSame($expected[$slug], $baseUrl, $slug);
            $host = (string) parse_url($baseUrl, PHP_URL_HOST);
            $this->assertNotSame('localhost', $host, $slug);
            $this->assertContains($host, $allowlist, $slug);
        }
    }

    private function firstCapture(string $pattern, string $file): string
    {
        if (preg_match($pattern, (string) file_get_contents($file), $match) !== 1) {
            $this->fail("Pattern {$pattern} not found in {$file}");
        }

        return trim($match[1]);
    }

    public function test_every_shipped_ground_truth_totals_32_bugs(): void
    {
        $loader = $this->loader();
        $total = 0;
        foreach ($loader->availableSlugs() as $slug) {
            $total += count($loader->load($slug)->bugs);
        }

        $this->assertSame(32, $total);
        $this->assertNotNull($loader->openApiContent('simple-store'));
    }

    /**
     * Regression (PR #101 review): the clean variant is the imported base_url, used unchanged by
     * UrlTemplateBenchmarkSandbox. It must be reachable from the web/worker containers, i.e. the
     * clean service's Docker network alias, not http://localhost:800x (nothing listens there
     * inside the worker, so every clean case would ERROR and Bug Detection Rate would be 0).
     */
    public function test_every_shipped_ground_truth_base_url_is_the_docker_clean_alias(): void
    {
        $loader = $this->loader();
        foreach ($loader->availableSlugs() as $slug) {
            $baseUrl = $loader->load($slug)->baseUrl;
            $this->assertSame("http://{$slug}:8000", $baseUrl, $slug);
            $this->assertNotSame('localhost', parse_url($baseUrl, PHP_URL_HOST), $slug);
        }
    }

    public function test_unknown_or_path_traversal_slug_is_not_found(): void
    {
        $this->assertNull($this->loader()->openApiContent('../web'));

        $this->expectException(ApiException::class);
        $this->loader()->load('../../etc');
    }

    public function test_rejects_yaml_object_tags(): void
    {
        $this->expectException(ApiException::class);
        $this->loader()->parse("benchmark: x\nbase_url: !php/object 'O:8:\"stdClass\":0:{}'\nbugs: []\n", 'x');
    }

    public function test_rejects_mismatched_slug_and_invalid_bug(): void
    {
        try {
            $this->loader()->parse("benchmark: other\nbase_url: http://localhost:1\nbugs: []\n", 'x');
            $this->fail('Expected mismatch error');
        } catch (ApiException $e) {
            $this->assertStringContainsString('không khớp', $e->getMessage());
        }

        $this->expectException(ApiException::class);
        $this->loader()->parse("benchmark: x\nbase_url: http://localhost:1\nbugs:\n  - code: BUG-1\n    method: FETCH\n    path: /a\n    category: VALIDATION_MISSING\n    toggle_env: B\n", 'x');
    }
}
