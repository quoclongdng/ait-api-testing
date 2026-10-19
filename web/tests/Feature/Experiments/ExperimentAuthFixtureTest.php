<?php

namespace Tests\Feature\Experiments;

use App\Actions\Experiments\RunExperimentAction;
use App\Contracts\AiGeneratorClient;
use App\Contracts\BenchmarkSandbox;
use App\Contracts\TargetHttpClient;
use App\Data\TargetRequest;
use App\Data\TargetResponse;
use App\Enums\BenchmarkBugCategory;
use App\Enums\ExperimentStatus;
use App\Enums\GenerationStrategy;
use App\Enums\HttpMethod;
use App\Enums\TestCaseSource;
use App\Enums\TestCaseStatus;
use App\Enums\TestTechnique;
use App\Models\Benchmark;
use App\Models\BenchmarkBug;
use App\Models\BusinessRule;
use App\Models\Experiment;
use App\Models\ExperimentBugDetection;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\TestCase as TestCaseModel;
use App\Models\TestResult;
use App\Models\User;
use App\Services\Ai\FakeAiGeneratorClient;
use App\Services\Benchmark\FakeBenchmarkSandbox;
use App\Services\Benchmark\GroundTruthLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * ADR-0006: the auth fixture and business rules of ground-truth.yaml are snapshotted into the
 * experiment, the executor injects the default credential for every strategy, RULE adds AUTH cases,
 * and LLM/HYBRID receive role names and the business-rule snapshot (never tokens).
 */
class ExperimentAuthFixtureTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<TargetRequest> */
    private array $requests = [];

    private const TOKENS = [
        'customer-alice-token' => 'CUSTOMER',
        'customer-bob-token' => 'CUSTOMER',
        'admin-token' => 'ADMIN',
    ];

    /**
     * Minimal ecommerce-order double: public catalogue, bearer authentication, ADMIN-only listing
     * (BUG-017 lets a customer read it) and customer-only cart/order operations.
     */
    private function bindEcommerceTarget(): void
    {
        $test = $this;
        $this->app->instance(TargetHttpClient::class, new class($test) implements TargetHttpClient
        {
            public function __construct(private ExperimentAuthFixtureTest $test) {}

            public function send(TargetRequest $request): TargetResponse
            {
                return $this->test->answer($request);
            }
        });
    }

    public function answer(TargetRequest $request): TargetResponse
    {
        $this->requests[] = $request;
        /** @var FakeBenchmarkSandbox $sandbox */
        $sandbox = app(BenchmarkSandbox::class);
        $path = (string) parse_url($request->url, PHP_URL_PATH);
        if ($path === '/health' || str_starts_with($path, '/products')) {
            return $this->reply(200);
        }

        $header = $this->authorization($request);
        $role = $header !== null && str_starts_with($header, 'Bearer ') ? (self::TOKENS[substr($header, 7)] ?? null) : null;
        if ($role === null) {
            return $this->reply(401);
        }
        if ($path === '/admin/orders') {
            return $this->reply($role === 'ADMIN' || $sandbox->activeBugCode === 'BUG-017' ? 200 : 403);
        }
        if ($role === 'ADMIN' && ! str_starts_with($path, '/orders/') || ($role === 'ADMIN' && $request->method === 'POST')) {
            return $this->reply(403);
        }

        return $this->reply($request->method === 'POST' ? 201 : 200);
    }

    private function authorization(TargetRequest $request): ?string
    {
        foreach ($request->headers as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                return $value;
            }
        }

        return null;
    }

    private function reply(int $status): TargetResponse
    {
        return new TargetResponse($status, ['Content-Type' => ['application/json']], '{}', 1);
    }

    private function benchmark(): Benchmark
    {
        $benchmark = Benchmark::factory()->create([
            'slug' => 'ecommerce-order',
            'name' => 'E-Commerce Order',
            // Host `benchmark` resolves under the fake target driver (phpunit.xml).
            'base_url' => 'http://benchmark:8003',
        ]);
        BenchmarkBug::factory()->create([
            'benchmark_id' => $benchmark->id,
            'code' => 'BUG-017',
            'method' => HttpMethod::Get,
            'path' => '/admin/orders',
            'category' => BenchmarkBugCategory::AuthError,
            'toggle_flag' => 'BUG_017',
        ]);

        return $benchmark;
    }

    private function createExperiment(User $user, Benchmark $benchmark, string $strategy, bool $singleRepetition = true): Experiment
    {
        Queue::fake();
        $id = (int) $this->actingAs($user)->postJson('/api/v1/experiments', [
            'name' => "ADR-0006 {$strategy}",
            'benchmark_id' => $benchmark->id,
            'strategy' => $strategy,
            'config' => ['model' => null, 'seed' => 42, 'temperature' => 0.2, 'repetitions' => 3],
        ])->assertStatus(202)->json('data.id');

        $experiment = Experiment::query()->findOrFail($id);
        if (! $singleRepetition) {
            return $experiment;
        }
        // One repetition is enough for the run checks.
        $experiment->update(['config' => ['repetitions' => 1, 'seeds' => [42]] + $experiment->config]);

        return $experiment->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function firstRepetition(Experiment $experiment): array
    {
        return ($experiment->repetitions_data ?? [])[0] ?? [];
    }

    public function test_creation_snapshots_auth_fixture_and_business_rules_from_ground_truth(): void
    {
        $user = User::factory()->tester()->create();
        $benchmark = $this->benchmark();
        $groundTruth = app(GroundTruthLoader::class)->load('ecommerce-order');

        $experiment = $this->createExperiment($user, $benchmark, 'RULE', singleRepetition: false);

        // jsonb reorders object keys: compare as maps.
        $this->assertEquals($groundTruth->auth?->toArray(), $experiment->config['auth']);
        $this->assertSame('customer-alice-token', $experiment->config['auth']['roles']['customer']['token']);
        $this->assertEquals($groundTruth->businessRules, $experiment->config['business_rules']);
        $this->assertNotEmpty($experiment->config['business_rules']);

        // GET /experiments/{id} exposes the snapshot within the contract (config.auth/business_rules).
        $content = (string) $this->actingAs($user)->getJson("/api/v1/experiments/{$experiment->id}")->assertOk()->getContent();
        $validator = new Validator;
        $validator->resolver()?->registerRaw(
            (string) json_encode(Yaml::parseFile(base_path('../docs/contracts/web-api.openapi.yaml'))),
            'https://ait.local/web-api.json',
        );
        $result = $validator->validate(json_decode($content), 'https://ait.local/web-api.json#/components/schemas/ExperimentResponse');
        $this->assertTrue($result->isValid(), (string) json_encode($result->error() !== null ? (new ErrorFormatter)->format($result->error()) : null));
    }

    public function test_clients_cannot_supply_the_snapshot_themselves(): void
    {
        $user = User::factory()->tester()->create();
        $benchmark = $this->benchmark();

        $this->actingAs($user)->postJson('/api/v1/experiments', [
            'name' => 'forged',
            'benchmark_id' => $benchmark->id,
            'strategy' => 'RULE',
            'config' => ['seed' => 42, 'temperature' => 0.2, 'repetitions' => 3, 'auth' => ['scheme' => 'bearer']],
        ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_public_benchmark_snapshots_no_auth_but_its_business_rules(): void
    {
        $user = User::factory()->tester()->create();
        $benchmark = Benchmark::factory()->create(['slug' => 'simple-store', 'base_url' => 'http://benchmark:8002']);
        BenchmarkBug::factory()->create(['benchmark_id' => $benchmark->id, 'code' => 'BUG-001']);

        $experiment = $this->createExperiment($user, $benchmark, 'RULE');

        $this->assertNull($experiment->config['auth']);
        $this->assertSame(['BR-SS-001', 'BR-SS-002', 'BR-SS-003', 'BR-SS-004', 'BR-SS-005', 'BR-SS-006', 'BR-SS-007', 'BR-SS-008'], array_column($experiment->config['business_rules'], 'code'));
    }

    public function test_rule_experiment_uses_injected_credentials_and_detects_the_role_bug(): void
    {
        $this->bindEcommerceTarget();
        $user = User::factory()->tester()->create();
        $experiment = $this->createExperiment($user, $this->benchmark(), 'RULE');

        app(RunExperimentAction::class)->execute($experiment->id, $user->id);

        $experiment->refresh();
        $this->assertSame(ExperimentStatus::Completed, $experiment->status, (string) json_encode($experiment->metrics));

        // The RULE job got role names only.
        $job = GenerationJob::query()->findOrFail((int) $this->firstRepetition($experiment)['generation_job_id']);
        $this->assertSame('customer', $job->params['auth']['default_role']);
        $this->assertStringNotContainsString('customer-alice-token', (string) json_encode($job->params));
        $this->assertSame([$job->id], $this->firstRepetition($experiment)['generation_job_ids']);

        // RULE now tests secured endpoints (not only "missing credential") and adds AUTH cases.
        $cases = TestCaseModel::query()->where('generation_job_id', $job->id)->where('status', TestCaseStatus::Valid)->get();
        $forbidden = $cases->first(fn (TestCaseModel $c): bool => $c->technique === TestTechnique::Auth
            && ($c->dsl['endpoint']['path'] ?? null) === '/admin/orders'
            && ($c->dsl['steps'][0]['request']['headers']['Authorization'] ?? null) === 'Bearer {{auth_token_customer}}');
        $this->assertNotNull($forbidden, 'forbidden-role AUTH case for GET /admin/orders');
        $this->assertTrue($cases->contains(fn (TestCaseModel $c): bool => $c->technique === TestTechnique::HappyPath && ($c->dsl['endpoint']['path'] ?? null) === '/cart'));
        $this->assertTrue($cases->contains(fn (TestCaseModel $c): bool => ($c->dsl['steps'][0]['request']['headers']['Authorization'] ?? null) === 'Bearer ait-rule-invalid-credential'));
        foreach ($cases as $case) {
            $this->assertSame(TestCaseSource::Rule, $case->source);
            if ($case->technique !== TestTechnique::Auth) {
                // Stored DSL never carries a credential: the executor adds it at run time.
                $this->assertArrayNotHasKey('Authorization', $case->dsl['steps'][0]['request']['headers'] ?? []);
            }
        }

        // Executor: default role on secured operations, operation role for the admin listing,
        // nothing on public endpoints, nothing added to AUTH cases.
        $byPath = [];
        foreach ($this->requests as $request) {
            $byPath[(string) parse_url($request->url, PHP_URL_PATH)][] = $this->authorization($request);
        }
        $this->assertContains('Bearer customer-alice-token', $byPath['/cart']);
        $this->assertContains('Bearer admin-token', $byPath['/admin/orders']);
        $this->assertContains('Bearer customer-alice-token', $byPath['/admin/orders']);
        $this->assertContains(null, $byPath['/admin/orders']);
        $this->assertSame([null], array_values(array_unique($byPath['/products'], SORT_REGULAR)));

        // D14: the forbidden-role case PASSes on clean (403) and FAILs with BUG-017 (200).
        $detection = ExperimentBugDetection::query()->where('experiment_id', $experiment->id)->firstOrFail();
        $this->assertTrue($detection->detected);
        $this->assertContains($forbidden->id, $detection->detecting_test_case_ids ?? []);

        // Logs mask the injected credential (§9).
        $logs = (string) json_encode(TestResult::query()->pluck('request_log')->all());
        $this->assertStringNotContainsString('customer-alice-token', $logs);
        $this->assertStringNotContainsString('admin-token', $logs);
    }

    public function test_llm_job_gets_role_names_and_the_business_rule_snapshot_not_the_project_rules(): void
    {
        $this->bindEcommerceTarget();
        $ai = new FakeAiGeneratorClient;
        $this->app->instance(AiGeneratorClient::class, $ai);
        $user = User::factory()->tester()->create();
        $benchmark = $this->benchmark();
        $experiment = $this->createExperiment($user, $benchmark, 'LLM');
        // Edit the snapshot to prove the run reads it (and nothing else).
        $experiment->update(['config' => ['business_rules' => [
            ['code' => 'BR-SNAP-1', 'description' => 'Snapshot rule', 'method' => 'GET', 'path' => '/cart'],
            ['code' => 'BR-SNAP-2', 'description' => 'Global snapshot rule', 'method' => null, 'path' => null],
        ]] + $experiment->config]);
        // A live rule in the shared experiment project must be ignored.
        $project = Project::factory()->create(['owner_id' => $user->id, 'name' => "Thực nghiệm: {$benchmark->name} ({$benchmark->version})"]);
        BusinessRule::factory()->create(['project_id' => $project->id, 'code' => 'BR-LIVE', 'description' => 'Live rule']);

        app(RunExperimentAction::class)->execute($experiment->id, $user->id);

        $request = $ai->lastRequest();
        $this->assertNotNull($request);
        $this->assertSame(['BR-SNAP-1', 'BR-SNAP-2'], array_column($request->businessRules, 'code'));
        $cartId = collect($request->endpoints)->firstWhere('path', '/cart')['id'] ?? null;
        $this->assertSame([$cartId, null], array_column($request->businessRules, 'endpoint_id'));
        $this->assertSame('customer', $request->auth['default_role'] ?? null);
        $this->assertEqualsCanonicalizing(['customer', 'customer_bob', 'admin'], array_column($request->auth['roles'] ?? [], 'name'));
        $payload = (string) json_encode($request->toArray());
        $this->assertStringContainsString('"auth"', $payload);
        $this->assertStringNotContainsString('-token"', $payload);

        $job = GenerationJob::query()->findOrFail((int) $this->firstRepetition($experiment->refresh())['generation_job_id']);
        $this->assertSame('BR-SNAP-1', $job->params['business_rules'][0]['code']);
    }

    public function test_hybrid_repetition_lists_the_rule_seed_job_and_the_hybrid_job(): void
    {
        $this->bindEcommerceTarget();
        $this->app->instance(AiGeneratorClient::class, new FakeAiGeneratorClient);
        $user = User::factory()->tester()->create();
        $experiment = $this->createExperiment($user, $this->benchmark(), 'HYBRID');

        app(RunExperimentAction::class)->execute($experiment->id, $user->id);

        $experiment->refresh();
        $ids = $this->firstRepetition($experiment)['generation_job_ids'];
        $this->assertCount(2, $ids);
        $this->assertSame([GenerationStrategy::Rule, GenerationStrategy::Hybrid], array_map(
            fn (int $id): GenerationStrategy => GenerationJob::query()->findOrFail($id)->strategy,
            $ids,
        ));

        $tester = User::factory()->tester()->create();
        $export = $this->actingAs($tester)->get("/api/v1/experiments/{$experiment->id}/export?format=json")->assertOk()->json();
        $this->assertSame($ids, $export['experiments'][0]['repetitions'][0]['generation_job_ids']);
        $this->assertSame('bearer', $export['experiments'][0]['config']['auth']['scheme']);
        $this->assertNotEmpty($export['experiments'][0]['config']['business_rules']);
    }
}
