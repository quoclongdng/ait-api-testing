<?php

namespace Tests\Feature\Generation;

use App\Actions\Generation\ExecuteRuleGenerationAction;
use App\Enums\ApiSpecStatus;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\HttpMethod;
use App\Enums\TestCaseStatus;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\TestCase as TestCaseModel;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression (S13-03 QA final, flaky GenerationScreenTest): ApiSpec::endpoints has no ORDER BY, so
 * PostgreSQL returns endpoints in physical order. A non-HOT UPDATE writes a new row version at the end of the
 * heap, after which the M11 per-endpoint table (and the generation order) listed endpoints out of spec
 * order. Target endpoints follow endpoint id order.
 */
class TargetEndpointOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_target_endpoints_keep_spec_order_after_an_endpoint_row_is_updated(): void
    {
        $tester = User::factory()->tester()->create();
        $project = Project::factory()->for($tester, 'owner')->create();
        $spec = ApiSpec::factory()->for($project)->create(['status' => ApiSpecStatus::Parsed]);
        $suite = TestSuite::factory()->create(['project_id' => $project->id, 'api_spec_id' => $spec->id, 'created_by' => $tester->id]);
        $first = Endpoint::factory()->create(['api_spec_id' => $spec->id, 'method' => HttpMethod::Get, 'path' => '/a']);
        $second = Endpoint::factory()->create(['api_spec_id' => $spec->id, 'method' => HttpMethod::Post, 'path' => '/a']);
        foreach ([['endpoint_ids' => [$first->id, $second->id]], ['endpoint_ids' => [$second->id, $first->id]], []] as $params) {
            $job = GenerationJob::factory()->create([
                'project_id' => $project->id, 'api_spec_id' => $spec->id, 'test_suite_id' => $suite->id,
                'created_by' => $tester->id, 'params' => $params,
            ]);

            // What PostgreSQL may return after a non-HOT UPDATE of the first endpoint (the relation has no
            // ORDER BY): rows in physical order, not in spec order.
            $spec->setRelation('endpoints', new Collection([$second, $first]));
            $job->setRelation('apiSpec', $spec);

            $this->assertSame([$first->id, $second->id], $job->getTargetEndpointIds());
        }
    }

    public function test_rule_generation_walks_endpoints_in_spec_order_after_an_endpoint_row_is_updated(): void
    {
        // Regression (S13-03 QA, stack P spec 69): ExecuteRuleGenerationAction iterated the reloaded relation
        // in heap order, so the first test case ids followed 173,168,167… instead of the spec order.
        // Ids in spec order. A non-HOT UPDATE of the first row (an indexed column changes) moves it to the end of
        // the heap and of the api_spec_id index; the paths are not alphabetical either, so no scan returns id order.
        [$tester, $suite, $endpoints] = $this->specWithEndpoints(['/zeta', '/alpha', '/mid']);
        $endpoints[0]->update(['path' => '/zeta-v2']);
        $this->assertNotSame(
            array_map(fn (Endpoint $e): int => $e->id, $endpoints),
            Endpoint::query()->where('api_spec_id', $suite->api_spec_id)->pluck('id')->all(),
            'precondition: after the UPDATE the unordered query returns heap order, not id order',
        );

        $job = GenerationJob::factory()->create([
            'project_id' => $suite->project_id, 'api_spec_id' => $suite->api_spec_id, 'test_suite_id' => $suite->id,
            'created_by' => $tester->id, 'strategy' => GenerationStrategy::Rule, 'status' => GenerationJobStatus::Queued,
            'params' => ['endpoint_ids' => [$endpoints[2]->id, $endpoints[0]->id, $endpoints[1]->id], 'techniques' => ['HAPPY_PATH']],
        ]);
        app(ExecuteRuleGenerationAction::class)->execute($job->id);

        $this->assertSame(GenerationJobStatus::Succeeded, $job->refresh()->status);
        $order = TestCaseModel::query()->where('generation_job_id', $job->id)->orderBy('id')->pluck('endpoint_id')->unique()->values()->all();
        $this->assertSame(array_map(fn (Endpoint $e): int => $e->id, $endpoints), $order);
    }

    public function test_generation_job_screen_lists_its_cases_in_creation_order(): void
    {
        // Regression (S13-03 QA, /generation-jobs/33): the case list had no ORDER BY and followed heap order.
        [$tester, $suite, $endpoints] = $this->specWithEndpoints(['/a', '/b', '/c']);
        $job = GenerationJob::factory()->create([
            'project_id' => $suite->project_id, 'api_spec_id' => $suite->api_spec_id, 'test_suite_id' => $suite->id,
            'created_by' => $tester->id, 'strategy' => GenerationStrategy::Rule, 'status' => GenerationJobStatus::Succeeded,
            'params' => ['endpoint_ids' => array_map(fn (Endpoint $e): int => $e->id, $endpoints)],
        ]);
        $cases = [];
        foreach (['Ca thứ nhất ZQ1', 'Ca thứ hai ZQ2', 'Ca thứ ba ZQ3'] as $i => $title) {
            $cases[] = TestCaseModel::factory()->create([
                'test_suite_id' => $suite->id, 'generation_job_id' => $job->id, 'endpoint_id' => $endpoints[$i]->id,
                'title' => $title, 'status' => TestCaseStatus::Valid,
            ]);
        }
        $cases[0]->update(['title' => 'Ca thứ nhất ZQ1 (đã sửa)']);
        $this->assertNotSame(
            array_map(fn (TestCaseModel $c): int => $c->id, $cases),
            DB::table('test_cases')->where('generation_job_id', $job->id)->pluck('id')->all(),
            'precondition: after the UPDATE the unordered query returns heap order, not id order',
        );

        $html = (string) $this->actingAs($tester)->get("/generation-jobs/{$job->id}")->assertOk()->getContent();

        $positions = array_map(fn (string $marker): int|false => strpos($html, $marker), ['ZQ1', 'ZQ2', 'ZQ3']);
        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'cases are listed in id (creation) order');
    }

    /**
     * @param  list<string>  $paths
     * @return array{User, TestSuite, list<Endpoint>}
     */
    private function specWithEndpoints(array $paths): array
    {
        $tester = User::factory()->tester()->create();
        $project = Project::factory()->for($tester, 'owner')->create();
        $spec = ApiSpec::factory()->for($project)->create(['status' => ApiSpecStatus::Parsed]);
        $suite = TestSuite::factory()->create(['project_id' => $project->id, 'api_spec_id' => $spec->id, 'created_by' => $tester->id]);
        $endpoints = [];
        foreach ($paths as $path) {
            $endpoints[] = Endpoint::factory()->create([
                'api_spec_id' => $spec->id, 'method' => HttpMethod::Get, 'path' => $path,
                'parameters' => [], 'request_body' => null, 'security' => null,
                'responses' => ['200' => ['description' => 'OK']],
            ]);
        }

        return [$tester, $suite, $endpoints];
    }
}
