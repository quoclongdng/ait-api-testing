<?php

namespace Tests\Feature\Security;

use App\Models\AllowedTarget;
use App\Models\ApiSpec;
use App\Models\Benchmark;
use App\Models\BusinessRule;
use App\Models\Concerns\HasIntegerRouteKey;
use App\Models\Experiment;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\TestCase as TestCaseModel;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * S13-03: a non-numeric (or out-of-range) id in a route parameter used to reach PostgreSQL as
 * `WHERE id = 'abc'` and fail with invalid_text_representation, rendered as HTTP 500. Every route
 * with a model-bound id must answer 404: the normal 404 page on the web, the §6 NOT_FOUND envelope
 * under /api. The route table is walked so a route added later is covered automatically.
 */
class NonNumericRouteIdTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Values PostgreSQL rejects for a bigint column (22P02 / 22003) before the fix.
     */
    private const BAD_IDS = ['abc', '1.5', '99999999999999999999'];

    /**
     * @return list<RoutingRoute>
     */
    private function routesWithParameters(): array
    {
        $router = app('router');
        $this->assertInstanceOf(Router::class, $router);

        $routes = [];
        foreach ($router->getRoutes()->getRoutes() as $route) {
            if ($route->parameterNames() === [] || ! in_array('auth', $route->gatherMiddleware(), true)) {
                continue;
            }
            $routes[] = $route;
        }

        return $routes;
    }

    /**
     * One live fixture per route parameter name, so only the parameter under test is invalid.
     *
     * @return array<string, Model>
     */
    private function fixtures(User $admin): array
    {
        $project = Project::factory()->for($admin, 'owner')->create();
        $spec = ApiSpec::factory()->for($project)->create();
        $suite = TestSuite::factory()->create([
            'project_id' => $project->id,
            'api_spec_id' => $spec->id,
            'created_by' => $admin->id,
        ]);

        return [
            'project' => $project,
            'spec' => $spec,
            'suite' => $suite,
            'businessRule' => BusinessRule::factory()->for($project)->create(),
            'generationJob' => GenerationJob::factory()->create([
                'project_id' => $project->id,
                'api_spec_id' => $spec->id,
                'test_suite_id' => $suite->id,
                'created_by' => $admin->id,
            ]),
            'run' => TestRun::factory()->create(['test_suite_id' => $suite->id, 'triggered_by' => $admin->id]),
            'testCase' => TestCaseModel::factory()->create(['test_suite_id' => $suite->id]),
            'experiment' => Experiment::factory()->create(),
            'user' => User::factory()->tester()->create(),
            'allowedTarget' => AllowedTarget::factory()->create(),
            'benchmark' => Benchmark::factory()->create(),
        ];
    }

    public function test_every_id_route_returns_not_found_for_a_non_numeric_id(): void
    {
        $admin = User::factory()->admin()->create();
        $fixtures = $this->fixtures($admin);

        // Guard against a vacuous pass: every fixture must itself resolve through route binding.
        foreach ($fixtures as $name => $model) {
            $this->assertNotNull(
                $model->resolveRouteBinding((string) $model->getKey()),
                "Fixture {$name} does not resolve, the 404 below would prove nothing.",
            );
        }

        $checked = 0;
        $unmapped = [];
        $failures = [];

        foreach ($this->routesWithParameters() as $route) {
            $names = $route->parameterNames();
            $missing = array_diff($names, array_keys($fixtures));
            if ($missing !== []) {
                $unmapped[] = $route->uri().' {'.implode('}, {', $missing).'}';

                continue;
            }

            $method = array_values(array_diff($route->methods(), ['HEAD']))[0];
            $isApi = str_starts_with($route->uri(), 'api/');

            foreach ($names as $target) {
                foreach (self::BAD_IDS as $bad) {
                    $uri = '/'.$route->uri();
                    foreach ($names as $name) {
                        $value = $name === $target ? $bad : (string) $fixtures[$name]->getKey();
                        $uri = str_replace('{'.$name.'}', rawurlencode($value), $uri);
                    }

                    $response = $isApi
                        ? $this->actingAs($admin)->json($method, $uri)
                        : $this->actingAs($admin)->call($method, $uri);
                    $checked++;

                    $code = $isApi ? $response->json('error.code') : null;
                    if ($response->status() !== 404 || ($isApi && $code !== 'NOT_FOUND')) {
                        $failures[] = sprintf('%s %s -> %d %s', $method, $uri, $response->status(), $code ?? '');
                    }
                }
            }
        }

        $this->assertSame([], $unmapped, 'Route parameters without a fixture in this test: add one.');
        $this->assertGreaterThan(50, $checked);
        $this->assertSame([], $failures, 'Routes not answering 404 for a non-numeric id.');
    }

    public function test_every_model_bound_by_a_route_uses_the_integer_route_key_guard(): void
    {
        $missing = [];

        foreach ($this->routesWithParameters() as $route) {
            foreach ($route->signatureParameters(['subClass' => UrlRoutable::class]) as $parameter) {
                $type = $parameter->getType();
                if (! $type instanceof ReflectionNamedType) {
                    continue;
                }
                $class = $type->getName();
                if (! in_array(HasIntegerRouteKey::class, class_uses_recursive($class), true)) {
                    $missing[$class] = $route->uri();
                }
            }
        }

        $this->assertSame([], $missing, 'Route-bound models without HasIntegerRouteKey.');
    }

    public function test_known_paths_from_the_report_return_not_found(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (['/experiments/abc', '/runs/abc', '/runs/abc/report', '/suites/abc', '/projects/abc/specs', '/test-cases/abc', '/generation-jobs/abc'] as $path) {
            $this->actingAs($admin)->get($path)->assertNotFound();
        }

        foreach (['/experiments/abc', '/runs/abc', '/runs/abc/results', '/suites/abc', '/projects/abc', '/specs/abc/endpoints', '/test-cases/abc', '/generation-jobs/abc'] as $path) {
            $this->actingAs($admin)->getJson('/api/v1'.$path)
                ->assertNotFound()
                ->assertJsonPath('error.code', 'NOT_FOUND')
                ->assertJsonStructure(['error' => ['code', 'message', 'details'], 'trace_id']);
        }
    }

    public function test_guests_are_still_asked_to_authenticate_before_binding(): void
    {
        $this->get('/runs/abc')->assertRedirect(route('login'));
        $this->getJson('/api/v1/runs/abc')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_numeric_ids_still_resolve_and_unknown_numeric_ids_are_not_found(): void
    {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->for($admin, 'owner')->create();

        $this->actingAs($admin)->getJson('/api/v1/projects/'.$project->id)
            ->assertOk()
            ->assertJsonPath('data.id', $project->id);
        $this->actingAs($admin)->getJson('/api/v1/projects/9223372036854775807')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_bigint_route_value_detection(): void
    {
        foreach (['1', '42', '007', '0', '9223372036854775807', 7] as $ok) {
            $this->assertTrue(Project::isBigIntRouteValue($ok), var_export($ok, true));
        }
        foreach (['abc', '1abc', '1.5', '-1', ' 1', "1\n", '', '9223372036854775808', '99999999999999999999', null, [], -1] as $bad) {
            $this->assertFalse(Project::isBigIntRouteValue($bad), var_export($bad, true));
        }
    }
}
