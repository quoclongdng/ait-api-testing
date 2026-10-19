<?php

namespace Tests\Feature\Runs;

use App\Contracts\BenchmarkSandbox;
use App\Contracts\TargetHttpClient;
use App\Data\TargetRequest;
use App\Data\TargetResponse;
use App\Enums\ErrorCode;
use App\Enums\TestCaseStatus;
use App\Enums\TestRunStatus;
use App\Enums\TestVerdict;
use App\Exceptions\ApiException;
use App\Jobs\ExecuteTestRunJob;
use App\Models\Benchmark;
use App\Models\BenchmarkBug;
use App\Models\Project;
use App\Models\TestCase;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase as FeatureTestCase;

/**
 * QA S13-03 (ADR-0006 addendum 2026-10-13): the clean benchmarks are shared, mutable state. An M12 run
 * on a benchmark sandbox resets it before every case, so re-running an unchanged suite gives the same
 * verdicts; any other target is never reset.
 */
class BenchmarkResetRunTest extends FeatureTestCase
{
    use RefreshDatabase;

    private StatefulBenchmark $benchmark;

    private User $user;

    private TestSuite $suite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->benchmark = new StatefulBenchmark;
        $this->app->instance(TargetHttpClient::class, $this->benchmark);
        $this->app->instance(BenchmarkSandbox::class, $this->benchmark);

        $this->user = User::factory()->create();
        $project = Project::factory()->for($this->user, 'owner')->create();
        $this->suite = TestSuite::factory()->for($project)->for($this->user, 'creator')->create();

        // Same shape as the RULE output that failed on re-run: fixed SKU create + delete of seeded ID 1.
        $this->makeCase('POST', '/products', 201, ['sku' => 'SKU_249']);
        $this->makeCase('GET', '/products/1', 200);
        $this->makeCase('DELETE', '/products/1', 204);
    }

    public function test_rerunning_an_unchanged_suite_on_a_benchmark_gives_the_same_verdicts(): void
    {
        $first = $this->runJob('http://benchmark:8000');
        $second = $this->runJob('http://benchmark:8000');

        $this->assertSame(TestRunStatus::Completed, $first->status);
        $this->assertEquals(['total' => 3, 'pass' => 3, 'fail' => 0, 'error' => 0, 'skipped' => 0], $first->totals);
        $this->assertEquals($first->totals, $second->totals, 'the second run must not see the first run\'s data');
        $this->assertSame($this->verdicts($first), $this->verdicts($second));

        // One reset per case (the first included), at the benchmark origin.
        $this->assertSame(array_fill(0, 6, 'http://benchmark:8000'), $this->benchmark->resets);
    }

    public function test_reset_uses_the_benchmark_origin_when_the_target_has_a_base_path(): void
    {
        $this->runJob('http://benchmark:8000/api/v1');

        $this->assertSame(array_fill(0, 3, 'http://benchmark:8000'), $this->benchmark->resets);
    }

    public function test_without_reset_the_second_run_reports_the_false_fails_from_the_qa_report(): void
    {
        // Documents the defect the reset prevents: nothing configured → the shared state leaks.
        config(['ait.benchmark.m12_reset_hosts' => []]);

        $first = $this->runJob('http://benchmark:8000');
        $second = $this->runJob('http://benchmark:8000');

        $this->assertEquals(['total' => 3, 'pass' => 3, 'fail' => 0, 'error' => 0, 'skipped' => 0], $first->totals);
        // 409 duplicate SKU, then 404 on the deleted seeded ID 1 (QA runs #35 → #37).
        $this->assertEquals(['total' => 3, 'pass' => 0, 'fail' => 3, 'error' => 0, 'skipped' => 0], $second->totals);
        $this->assertSame([], $this->benchmark->resets);
    }

    public function test_a_target_that_is_not_a_benchmark_is_never_reset(): void
    {
        $this->runJob('http://localhost:8000');

        $this->assertSame([], $this->benchmark->resets);
    }

    public function test_a_failed_reset_stores_the_case_as_error_without_sending_it(): void
    {
        $this->benchmark->failResets = true;

        $run = $this->runJob('http://benchmark:8000');

        $this->assertSame(TestRunStatus::Completed, $run->status);
        $this->assertEquals(['total' => 3, 'pass' => 0, 'fail' => 0, 'error' => 3, 'skipped' => 0], $run->totals);
        $this->assertSame([], $this->benchmark->sent, 'no case may run on dirty sandbox data');

        $result = TestResult::query()->where('test_run_id', $run->id)->orderBy('id')->firstOrFail();
        $this->assertSame(TestVerdict::Error, $result->verdict);
        $this->assertStringContainsString('Không đặt lại được dữ liệu benchmark', (string) $result->error_message);
        $this->assertStringContainsString('HTTP 500', (string) $result->error_message);
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function makeCase(string $method, string $path, int $expectedStatus, ?array $body = null): void
    {
        $request = ['method' => $method, 'path' => $path];
        if ($body !== null) {
            $request['body'] = $body;
        }

        TestCase::factory()->for($this->suite, 'suite')->create([
            'status' => TestCaseStatus::Valid,
            'dsl' => [
                'dsl_version' => '1.0',
                'title' => "{$method} {$path}",
                'endpoint' => ['method' => $method, 'path' => $path === '/products/1' ? '/products/{id}' : $path],
                'technique' => 'HAPPY_PATH',
                'source' => 'RULE',
                'steps' => [[
                    'name' => 'Call',
                    'request' => $request,
                    'assertions' => [['type' => 'status', 'equals' => $expectedStatus]],
                ]],
            ],
        ]);
    }

    private function runJob(string $target): TestRun
    {
        $run = TestRun::factory()->for($this->suite)->for($this->user, 'triggerer')->create([
            'target_base_url' => $target,
            'status' => TestRunStatus::Queued,
            'started_at' => null,
            'finished_at' => null,
            'totals' => null,
        ]);

        $this->app->call([new ExecuteTestRunJob($run->id), 'handle']);

        return $run->refresh();
    }

    /**
     * @return list<string>
     */
    private function verdicts(TestRun $run): array
    {
        return array_values(TestResult::query()->where('test_run_id', $run->id)->orderBy('test_case_id')->get()
            ->map(fn (TestResult $r): string => $r->verdict->value)->all());
    }
}

/**
 * Minimal stateful benchmark: seeded product 1, unique SKUs, and `POST /__admin/reset` semantics
 * through the BenchmarkSandbox contract.
 */
final class StatefulBenchmark implements BenchmarkSandbox, TargetHttpClient
{
    /** @var array<int, string> */
    private array $products = [1 => 'SKU_SEED'];

    /** @var list<string> */
    public array $resets = [];

    /** @var list<TargetRequest> */
    public array $sent = [];

    public bool $failResets = false;

    public function ensureAvailable(Benchmark $benchmark): void {}

    public function variantBaseUrl(Benchmark $benchmark, ?BenchmarkBug $bug): string
    {
        return $benchmark->base_url;
    }

    public function reset(string $baseUrl): void
    {
        if ($this->failResets) {
            throw new ApiException(ErrorCode::InternalError, "Reset sandbox {$baseUrl}/__admin/reset thất bại (HTTP 500).");
        }
        $this->resets[] = $baseUrl;
        $this->products = [1 => 'SKU_SEED'];
    }

    public function send(TargetRequest $request): TargetResponse
    {
        $this->sent[] = $request;
        $path = (string) parse_url($request->url, PHP_URL_PATH);

        if ($request->method === 'POST' && $path === '/products') {
            $body = is_string($request->body)
                ? json_decode($request->body, true)
                : json_decode((string) json_encode($request->body), true);
            $sku = is_array($body) && is_string($body['sku'] ?? null) ? $body['sku'] : '';
            if (in_array($sku, $this->products, true)) {
                return $this->json(409, ['detail' => "Product with SKU {$sku} already exists"]);
            }
            $id = max(array_keys($this->products) ?: [0]) + 1;
            $this->products[$id] = $sku;

            return $this->json(201, ['id' => $id, 'sku' => $sku]);
        }

        if (preg_match('#^/products/(\d+)$#', $path, $m) === 1) {
            $id = (int) $m[1];
            if (! isset($this->products[$id])) {
                return $this->json(404, ['detail' => "Product with ID {$id} not found"]);
            }
            if ($request->method === 'DELETE') {
                unset($this->products[$id]);

                return new TargetResponse(status: 204, headers: [], body: '', durationMs: 1);
            }

            return $this->json(200, ['id' => $id, 'sku' => $this->products[$id]]);
        }

        return $this->json(404, ['detail' => 'Not Found']);
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function json(int $status, array $json): TargetResponse
    {
        return new TargetResponse(
            status: $status,
            headers: ['Content-Type' => ['application/json']],
            body: (string) json_encode($json),
            durationMs: 1,
        );
    }
}
