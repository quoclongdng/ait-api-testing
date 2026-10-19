<?php

namespace Tests\Feature\Generation;

use App\Actions\ApiSpecs\CreateApiSpecAction;
use App\Actions\Generation\ExecuteRuleGenerationAction;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\TestCaseStatus;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\TestCase as TestCaseModel;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regressions (S10-01 r2) on the RULE strategy with the experiment parameters
 * (every technique, max_cases_per_endpoint = 10) on the real simple-store benchmark spec.
 */
class RuleGenerationCoverageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ApiSpec $spec;

    private TestSuite $suite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->tester()->create();
        $project = Project::factory()->for($this->user, 'owner')->create();
        $content = (string) file_get_contents(base_path('../benchmark/simple-store/openapi.yaml'));
        $this->spec = app(CreateApiSpecAction::class)->execute($project, 'v1', $content, 'simple-store.yaml');
        $this->suite = TestSuite::factory()->for($project)->for($this->user, 'creator')->create(['api_spec_id' => $this->spec->id]);
    }

    /**
     * The cap was applied in technique order before validation, so POST /products lost every
     * BOUNDARY, EQUIVALENCE and INVALID_INPUT case.
     */
    public function test_cap_keeps_every_technique_on_post_products(): void
    {
        $cases = $this->generate('POST', '/products');

        $this->assertCount(10, $cases);
        $techniques = array_unique(array_map(fn (TestCaseModel $c): string => $c->technique->value, $cases));
        $this->assertEqualsCanonicalizing(
            ['HAPPY_PATH', 'MISSING_REQUIRED', 'TYPE_MISMATCH', 'BOUNDARY', 'EQUIVALENCE', 'INVALID_INPUT'],
            array_values($techniques),
        );
        foreach ($cases as $case) {
            $this->assertSame(TestCaseStatus::Valid, $case->status, $case->title);
        }
    }

    public function test_integer_path_param_gets_type_mismatch_and_not_found_cases(): void
    {
        $cases = $this->generate('GET', '/products/{id}');

        $byTitle = [];
        foreach ($cases as $case) {
            $this->assertSame(TestCaseStatus::Valid, $case->status, $case->title);
            $byTitle[$case->title] = $case->dsl['steps'][0];
        }
        $mismatch = $byTitle["Sai kiểu dữ liệu của tham số đường dẫn 'id' trong GET /products/{id}"] ?? null;
        $this->assertIsArray($mismatch);
        $this->assertSame('not_a_valid_integer', $mismatch['request']['path_params']['id']);
        $this->assertSame(422, $mismatch['assertions'][0]['equals']);

        $missing = $byTitle["Phân hoạch tương đương không hợp lệ: tham số đường dẫn 'id' không tồn tại trong GET /products/{id}"] ?? null;
        $this->assertIsArray($missing);
        $this->assertSame(404, $missing['assertions'][0]['equals']);
    }

    public function test_nullable_anyof_fields_get_boundary_cases(): void
    {
        $cases = $this->generate('PUT', '/products/{id}');

        $titles = array_map(fn (TestCaseModel $c): string => $c->title, $cases);
        $this->assertContains("Biên không hợp lệ: trường 'quantity' nhỏ hơn minimum trong PUT /products/{id}", $titles);
        $this->assertContains("Biên không hợp lệ: trường 'price' nhỏ hơn minimum trong PUT /products/{id}", $titles);
    }

    public function test_happy_path_asserts_the_documented_response_schema(): void
    {
        $cases = $this->generate('GET', '/products/{id}');
        $happy = collect($cases)->first(fn (TestCaseModel $c): bool => $c->technique->value === 'HAPPY_PATH');
        $this->assertInstanceOf(TestCaseModel::class, $happy);

        $types = array_column($happy->dsl['steps'][0]['assertions'], 'type');
        $this->assertSame(['status', 'json_schema'], $types);
        $schema = $happy->dsl['steps'][0]['assertions'][1]['schema'];
        $this->assertContains('sku', $schema['required'] ?? []);
    }

    /**
     * @return list<TestCaseModel>
     */
    private function generate(string $method, string $path): array
    {
        $endpoint = Endpoint::query()->where('api_spec_id', $this->spec->id)->where('method', $method)->where('path', $path)->firstOrFail();
        $job = GenerationJob::factory()->create([
            'project_id' => $this->suite->project_id,
            'api_spec_id' => $this->spec->id,
            'test_suite_id' => $this->suite->id,
            'created_by' => $this->user->id,
            'strategy' => GenerationStrategy::Rule,
            'status' => GenerationJobStatus::Queued,
            'params' => ['endpoint_ids' => [$endpoint->id], 'techniques' => [], 'max_cases_per_endpoint' => 10],
        ]);

        $job = app(ExecuteRuleGenerationAction::class)->execute($job->id);
        $this->assertSame(GenerationJobStatus::Succeeded, $job->status, (string) $job->error);

        return array_values(TestCaseModel::query()->where('generation_job_id', $job->id)->orderBy('id')->get()->all());
    }
}
