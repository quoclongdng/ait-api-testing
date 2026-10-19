<?php

namespace Tests\Feature\Runs;

use App\Actions\ApiSpecs\CreateApiSpecAction;
use App\Contracts\TargetHostResolver;
use App\Contracts\TargetHttpClient;
use App\Data\TargetRequest;
use App\Enums\ApiSpecFormat;
use App\Enums\TestCaseStatus;
use App\Enums\TestRunStatus;
use App\Enums\TestVerdict;
use App\Jobs\ExecuteTestRunJob;
use App\Models\Endpoint;
use App\Models\Project;
use App\Models\TestCase;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\Benchmark\BenchmarkRunAuthResolver;
use App\Services\Target\FakeTargetHostResolver;
use App\Services\Target\FakeTargetHttpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase as FeatureTestCase;

/**
 * QA S14-06 (ADR-0006 addendum 2026-10-14): an M12 run on a platform benchmark sandbox uses that
 * benchmark's public synthetic auth fixture, like an experiment run, so re-running the demo
 * "LLM/HYBRID (bản ghi thực nghiệm)" suite no longer ends in ERROR on every secured operation.
 * Any other target never gets the fixture.
 */
class BenchmarkRunAuthFixtureTest extends FeatureTestCase
{
    use RefreshDatabase;

    private FakeTargetHttpClient $client;

    private User $user;

    private TestSuite $suite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new FakeTargetHttpClient;
        $this->app->instance(TargetHttpClient::class, $this->client);
        $this->app->instance(TargetHostResolver::class, new FakeTargetHostResolver([
            'ecommerce-order' => ['127.0.0.1'],
            'benchmark-order' => ['127.0.0.1'],
            'localhost' => ['127.0.0.1'],
        ]));

        $this->user = User::factory()->tester()->create();
        $project = Project::factory()->for($this->user, 'owner')->create();
        $content = (string) file_get_contents(base_path('../benchmark/ecommerce-order/openapi.yaml'));
        $spec = app(CreateApiSpecAction::class)->execute($project, 'v1.0', $content, 'openapi.yaml', ApiSpecFormat::Yaml);
        $this->suite = TestSuite::factory()->for($project)->for($this->user, 'creator')->create(['api_spec_id' => $spec->id]);

        // A recorded LLM case relying on the default header, one with an explicit role token, and an AUTH case.
        $this->makeCase('GET', '/cart', 'HAPPY_PATH', 200);
        $this->makeCase('GET', '/admin/orders', 'HAPPY_PATH', 200, ['Authorization' => 'Bearer {{auth_token_admin}}']);
        $this->makeCase('GET', '/cart', 'AUTH', 401);
    }

    public function test_a_run_on_a_platform_benchmark_sends_the_benchmark_credentials(): void
    {
        $run = $this->runJob('http://ecommerce-order:8000');

        $this->assertSame(TestRunStatus::Completed, $run->status);
        $this->assertSame(0, TestResult::query()->where('test_run_id', $run->id)->where('verdict', TestVerdict::Error->value)->count());
        $this->assertSame([
            '/cart' => 'Bearer customer-alice-token',
            '/admin/orders' => 'Bearer admin-token',
        ], $this->authorizations(array_slice($this->client->sent(), 0, 2)));
        $this->assertNull($this->client->sent()[2]->headers['Authorization'] ?? null, 'an AUTH case controls its own credentials');

        // The stored request log still masks the token (§9).
        $log = TestResult::query()->where('test_run_id', $run->id)->orderBy('id')->firstOrFail()->request_log;
        $this->assertStringNotContainsString('customer-alice-token', (string) json_encode($log));
    }

    public function test_the_compose_service_name_of_the_benchmark_is_recognised(): void
    {
        $this->runJob('http://benchmark-order:8000');

        $this->assertSame('Bearer customer-alice-token', $this->client->sent()[0]->headers['Authorization'] ?? null);
    }

    public function test_another_target_never_gets_the_fixture(): void
    {
        $run = $this->runJob('http://localhost:8000');

        $sent = $this->client->sent();
        $this->assertCount(2, $sent, 'the case with the unresolved token is not sent');
        foreach ($sent as $request) {
            $this->assertArrayNotHasKey('Authorization', $request->headers);
        }
        $error = TestResult::query()->where('test_run_id', $run->id)->where('verdict', TestVerdict::Error->value)->firstOrFail();
        $this->assertStringContainsString('Biến {{auth_token_admin}} chưa có giá trị', (string) $error->error_message);
        $this->assertDoesNotMatchRegularExpression('/\bM\d{2}\b|ADR-/', (string) $error->error_message);
    }

    public function test_resolver_matches_benchmarks_by_host_only(): void
    {
        $resolver = app(BenchmarkRunAuthResolver::class);

        $this->assertSame('customer', $resolver->fixtureFor('http://ecommerce-order:8000/')?->defaultRole);
        $this->assertSame('customer', $resolver->fixtureFor('https://benchmark-order:8000/api')?->defaultRole);
        $this->assertNotNull($resolver->fixtureFor('http://appointment-booking:8000'));
        $this->assertNull($resolver->fixtureFor('http://simple-store:8000'), 'simple-store declares no auth');
        $this->assertNull($resolver->fixtureFor('http://ecommerce-order-bug-007:8000'), 'a one-bug sandbox is not a listed host');
        $this->assertNull($resolver->fixtureFor('http://localhost:8000'));
        $this->assertNull($resolver->fixtureFor(null));

        config(['ait.benchmark.m12_reset_hosts' => []]);
        $this->assertNull(app(BenchmarkRunAuthResolver::class)->fixtureFor('http://ecommerce-order:8000'), 'empty host list: no benchmark run');
    }

    /**
     * @param  list<TargetRequest>  $requests
     * @return array<string, string|null>
     */
    private function authorizations(array $requests): array
    {
        $byPath = [];
        foreach ($requests as $request) {
            $byPath[(string) parse_url($request->url, PHP_URL_PATH)] = $request->headers['Authorization'] ?? null;
        }

        return $byPath;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function makeCase(string $method, string $path, string $technique, int $status, array $headers = []): void
    {
        $request = ['method' => $method, 'path' => $path];
        if ($headers !== []) {
            $request['headers'] = $headers;
        }
        $endpoint = Endpoint::query()->where('api_spec_id', $this->suite->api_spec_id)->where('method', $method)->where('path', $path)->firstOrFail();

        TestCase::factory()->for($this->suite, 'suite')->create([
            'endpoint_id' => $endpoint->id,
            'status' => TestCaseStatus::Valid,
            'technique' => $technique,
            'dsl' => [
                'dsl_version' => '1.0',
                'title' => "{$technique} {$method} {$path}",
                'endpoint' => ['method' => $method, 'path' => $path],
                'technique' => $technique,
                'source' => 'LLM',
                'steps' => [[
                    'name' => 'Call',
                    'request' => $request,
                    'assertions' => [['type' => 'status', 'equals' => $status]],
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
}
