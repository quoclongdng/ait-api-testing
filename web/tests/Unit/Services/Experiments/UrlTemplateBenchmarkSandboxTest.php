<?php

namespace Tests\Unit\Services\Experiments;

use App\Data\TargetRequest;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Benchmark;
use App\Models\BenchmarkBug;
use App\Services\Benchmark\FakeBenchmarkSandbox;
use App\Services\Benchmark\UrlTemplateBenchmarkSandbox;
use App\Services\Execution\TargetAllowlistValidator;
use App\Services\Target\FakeTargetHostResolver;
use App\Services\Target\FakeTargetHttpClient;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class UrlTemplateBenchmarkSandboxTest extends TestCase
{
    private function benchmark(): Benchmark
    {
        return (new Benchmark)->forceFill(['id' => 1, 'slug' => 'simple-store', 'version' => 'v1.0', 'base_url' => 'http://benchmark:8000/']);
    }

    private function bug(): BenchmarkBug
    {
        return (new BenchmarkBug)->forceFill(['code' => 'BUG-001', 'toggle_flag' => 'BUG_001']);
    }

    public function test_clean_variant_is_the_benchmark_base_url(): void
    {
        $sandbox = new UrlTemplateBenchmarkSandbox('');

        $this->assertSame('http://benchmark:8000', $sandbox->variantBaseUrl($this->benchmark(), null));
    }

    public function test_clean_variant_url_is_built_from_the_clean_template(): void
    {
        $sandbox = new UrlTemplateBenchmarkSandbox('', 'http://{slug}:8000/');

        $this->assertSame('http://simple-store:8000', $sandbox->variantBaseUrl($this->benchmark(), null));
        $this->assertSame(
            'http://benchmark:8000/v1.0',
            (new UrlTemplateBenchmarkSandbox('', '{base_url}/{version}'))->variantBaseUrl($this->benchmark(), null),
        );
    }

    public function test_clean_template_is_the_default_config(): void
    {
        $this->assertSame('http://{slug}:8000', config('ait.benchmark.clean_url_template'));
    }

    private function resettable(FakeTargetHttpClient $client): UrlTemplateBenchmarkSandbox
    {
        return new UrlTemplateBenchmarkSandbox(
            template: 'http://{slug}-{toggle}:8000',
            cleanTemplate: 'http://{slug}:8000',
            client: $client,
            allowlist: new TargetAllowlistValidator(
                ['simple-store', 'simple-store-bug_001'],
                new FakeTargetHostResolver(['simple-store' => ['172.18.0.5'], 'simple-store-bug_001' => ['172.18.0.6'], 'evil.example.com' => ['10.0.0.9']]),
                fn (string $host): array => [],
            ),
            resetTimeoutMs: 5000,
        );
    }

    public function test_reset_posts_to_the_admin_endpoint_of_the_variant(): void
    {
        $client = (new FakeTargetHttpClient)->respondWith(200, ['status' => 'reset']);
        $sandbox = $this->resettable($client);

        $sandbox->reset($sandbox->variantBaseUrl($this->benchmark(), $this->bug()));

        $sent = $client->sent();
        $this->assertCount(1, $sent);
        $this->assertInstanceOf(TargetRequest::class, $sent[0]);
        $this->assertSame('POST', $sent[0]->method);
        $this->assertSame('http://simple-store-bug_001:8000/__admin/reset', $sent[0]->url);
        $this->assertSame(5000, $sent[0]->timeoutMs);
        $this->assertFalse($sent[0]->hasBody);
    }

    public function test_reset_tolerates_a_sandbox_without_reset_endpoint_and_logs_a_warning(): void
    {
        Log::shouldReceive('warning')->once()->withArgs(
            fn (string $message, array $context): bool => str_contains($message, 'no reset endpoint')
                && $context['url'] === 'http://simple-store:8000/__admin/reset',
        );
        $client = (new FakeTargetHttpClient)->respondWith(404, ['detail' => 'Not Found']);

        $this->resettable($client)->reset('http://simple-store:8000/');

        $this->assertCount(1, $client->sent());
    }

    public function test_reset_failure_other_than_404_throws(): void
    {
        $client = (new FakeTargetHttpClient)->respondWith(500, ['detail' => 'boom']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 500');
        $this->resettable($client)->reset('http://simple-store:8000');
    }

    public function test_reset_outside_the_allowlist_is_refused_before_sending(): void
    {
        $client = new FakeTargetHttpClient;

        try {
            $this->resettable($client)->reset('http://evil.example.com:8000');
            $this->fail('Expected TARGET_NOT_ALLOWED.');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::TargetNotAllowed, $e->errorCode);
        }
        $this->assertSame([], $client->sent());
    }

    public function test_reset_of_unresolvable_host_is_refused(): void
    {
        $client = new FakeTargetHttpClient;

        $this->expectException(ApiException::class);
        $this->resettable($client)->reset('http://simple-store-bug_404:8000');
    }

    public function test_fake_sandbox_records_resets_with_the_active_variant(): void
    {
        $sandbox = new FakeBenchmarkSandbox;
        $sandbox->reset($sandbox->variantBaseUrl($this->benchmark(), $this->bug()));
        $sandbox->reset($sandbox->variantBaseUrl($this->benchmark(), null).'/');

        $this->assertSame([
            ['url' => 'http://benchmark:8000', 'bug' => 'BUG-001'],
            ['url' => 'http://benchmark:8000', 'bug' => null],
        ], $sandbox->resets);

        $sandbox->failResetWith = 'down';
        $this->expectException(RuntimeException::class);
        $sandbox->reset('http://benchmark:8000');
    }

    public function test_bug_variant_url_is_built_from_the_template(): void
    {
        $sandbox = new UrlTemplateBenchmarkSandbox('http://{slug}-{toggle}:8000/');

        $this->assertSame('http://simple-store-bug_001:8000', $sandbox->variantBaseUrl($this->benchmark(), $this->bug()));
        $this->assertSame(
            'http://benchmark:8000/BUG-001/bug-001/v1.0',
            (new UrlTemplateBenchmarkSandbox('{base_url}/{code}/{code_lower}/{version}'))->variantBaseUrl($this->benchmark(), $this->bug()),
        );
    }

    public function test_missing_template_makes_bug_variants_unavailable(): void
    {
        $sandbox = new UrlTemplateBenchmarkSandbox('  ');

        $this->expectException(ApiException::class);
        $sandbox->variantBaseUrl($this->benchmark(), $this->bug());
    }

    public function test_fake_sandbox_records_the_active_bug(): void
    {
        $sandbox = new FakeBenchmarkSandbox;

        $this->assertSame('http://benchmark:8000', $sandbox->variantBaseUrl($this->benchmark(), $this->bug()));
        $this->assertSame('BUG-001', $sandbox->activeBugCode);
        $sandbox->variantBaseUrl($this->benchmark(), null);
        $this->assertNull($sandbox->activeBugCode);
        $this->assertSame(['BUG-001', null], $sandbox->history);
    }
}
