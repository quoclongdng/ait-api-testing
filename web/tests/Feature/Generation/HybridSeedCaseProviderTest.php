<?php

namespace Tests\Feature\Generation;

use App\Actions\Generation\ExecuteAiGenerationAction;
use App\Actions\Generation\RecordLlmCallsAction;
use App\Enums\ApiSpecStatus;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\HttpMethod;
use App\Enums\TestCaseSource;
use App\Enums\TestCaseStatus;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Models\GenerationJob;
use App\Models\TestCase as TestCaseModel;
use App\Models\TestSuite;
use App\Services\Ai\FakeAiGeneratorClient;
use App\Services\Generation\HybridSeedCaseProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression (S10-01 audit r3): seeds read from the suite went through the `array` cast, which
 * decodes every `{}` as `[]`. Empty sub-schemas outside a fixed key list (`metadata: {}`,
 * `items: {}`, `additionalProperties: {}`) were sent as `[]`, ai-service rejected the seed with
 * 422 and the whole HYBRID job FAILED. `extract: []` was also wrongly turned into `{}`.
 */
class HybridSeedCaseProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_suite_seeds_keep_empty_json_objects_and_empty_arrays(): void
    {
        [$hybridJob, $endpoint] = $this->suiteWithSeededRuleCase(GenerationJobStatus::Running);

        $seeds = app(HybridSeedCaseProvider::class)->forJob($hybridJob, [$endpoint->id]);

        $this->assertCount(1, $seeds);
        $json = json_encode($seeds[0], JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('"metadata":{}', $json);
        $this->assertStringContainsString('"items":{}', $json);
        $this->assertStringContainsString('"additionalProperties":{}', $json);
        $this->assertStringContainsString('"variables":{}', $json);
        $this->assertStringContainsString('"extract":[]', $json);
        $this->assertStringContainsString('"required":["id"]', $json);
        // Seed consumers still read the DSL as nested arrays.
        $this->assertSame('/things/{id}', $seeds[0]['steps'][0]['request']['path']);
        $this->assertSame('integer', $seeds[0]['steps'][0]['assertions'][1]['schema']['properties']['id']['type']);
    }

    /**
     * Regression (S13-03, PR #123 reviewer note): ExecuteAiGenerationAction preferred
     * `params.seed_cases` over HybridSeedCaseProvider. generation_jobs.params uses the `array`
     * cast, so a seed passed that way lost every `{}` (`metadata: {}` became `metadata: []`).
     * HYBRID seeds now always come from the provider, which keeps empty JSON objects.
     */
    public function test_hybrid_job_ignores_params_seed_cases_and_keeps_empty_objects(): void
    {
        [$hybridJob, $endpoint] = $this->suiteWithSeededRuleCase(GenerationJobStatus::Queued);
        $hybridJob->update(['params' => [
            'endpoint_ids' => [$endpoint->id],
            'seed_cases' => [[
                'dsl_version' => '1.0',
                'title' => 'Seed from params',
                'endpoint' => ['method' => 'GET', 'path' => '/things/{id}'],
                'technique' => 'HAPPY_PATH',
                'source' => 'RULE',
                'steps' => [[
                    'name' => 'Get thing',
                    'request' => ['method' => 'GET', 'path' => '/things/{id}', 'path_params' => ['id' => 1]],
                    'assertions' => [['type' => 'json_schema', 'schema' => ['type' => 'object', 'properties' => ['metadata' => (object) []]]]],
                ]],
            ]],
        ]]);
        // The cast is what made the old path lossy: the stored seed now reads back `metadata: []`.
        $this->assertSame([], $hybridJob->refresh()->params['seed_cases'][0]['steps'][0]['assertions'][0]['schema']['properties']['metadata']);

        $client = new FakeAiGeneratorClient(reachable: true);
        (new ExecuteAiGenerationAction($client, new RecordLlmCallsAction))->execute($hybridJob);

        $request = $client->lastRequest();
        $this->assertNotNull($request);
        $this->assertCount(1, $request->seedCases);
        $json = json_encode($request->toArray()['seed_cases'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString('Seed from params', $json);
        $this->assertStringContainsString('Happy Path: GET /things/{id}', $json);
        $this->assertStringContainsString('"metadata":{}', $json);
        $this->assertStringNotContainsString('"metadata":[]', $json);
    }

    /**
     * A PARSED spec with one endpoint, one VALID RULE case whose response schema has empty JSON
     * objects, and a HYBRID job on the same suite.
     *
     * @return array{0: GenerationJob, 1: Endpoint}
     */
    private function suiteWithSeededRuleCase(GenerationJobStatus $hybridStatus): array
    {
        $spec = ApiSpec::factory()->create(['status' => ApiSpecStatus::Parsed]);
        $suite = TestSuite::factory()->create(['project_id' => $spec->project_id, 'api_spec_id' => $spec->id]);
        $endpoint = Endpoint::factory()->create([
            'api_spec_id' => $spec->id,
            'method' => HttpMethod::Get,
            'path' => '/things/{id}',
            'responses' => ['200' => ['description' => 'OK']],
        ]);
        $ruleJob = GenerationJob::factory()->create([
            'project_id' => $spec->project_id,
            'api_spec_id' => $spec->id,
            'test_suite_id' => $suite->id,
            'strategy' => GenerationStrategy::Rule,
            'status' => GenerationJobStatus::Succeeded,
        ]);
        $schema = (object) [
            'type' => 'object',
            'properties' => (object) [
                'id' => (object) ['type' => 'integer'],
                'metadata' => (object) [],
                'tags' => (object) ['type' => 'array', 'items' => (object) []],
            ],
            'additionalProperties' => (object) [],
            'required' => ['id'],
        ];
        TestCaseModel::factory()->create([
            'test_suite_id' => $suite->id,
            'endpoint_id' => $endpoint->id,
            'generation_job_id' => $ruleJob->id,
            'source' => TestCaseSource::Rule,
            'status' => TestCaseStatus::Valid,
            'dsl' => [
                'dsl_version' => '1.0',
                'title' => 'Happy Path: GET /things/{id}',
                'endpoint' => ['method' => 'GET', 'path' => '/things/{id}'],
                'technique' => 'HAPPY_PATH',
                'source' => 'RULE',
                'variables' => (object) [],
                'steps' => [[
                    'name' => 'Get thing',
                    'request' => ['method' => 'GET', 'path' => '/things/{id}', 'path_params' => ['id' => 1]],
                    'extract' => [],
                    'assertions' => [
                        ['type' => 'status', 'equals' => 200],
                        ['type' => 'json_schema', 'schema' => $schema],
                    ],
                ]],
            ],
        ]);
        $hybridJob = GenerationJob::factory()->create([
            'project_id' => $spec->project_id,
            'api_spec_id' => $spec->id,
            'test_suite_id' => $suite->id,
            'strategy' => GenerationStrategy::Hybrid,
            'status' => $hybridStatus,
            'params' => ['endpoint_ids' => [$endpoint->id]],
        ]);

        return [$hybridJob, $endpoint];
    }
}
