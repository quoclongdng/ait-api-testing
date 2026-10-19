<?php

namespace Tests\Feature\Api;

use App\Enums\ApiSpecStatus;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Models\Project;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Regression (S13-03 QA, docs): list operations returned 422 VALIDATION_FAILED for an invalid
 * page/per_page/filter without documenting it, GET /specs/{id}/endpoints clamped the same
 * parameters instead of rejecting them, and BusinessRuleWrite/Patch.code had no pattern although
 * the server only accepts letters, digits, "-" and "_".
 */
class ListValidationContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function contract(): array
    {
        /** @var array<string, mixed> $contract */
        $contract = Yaml::parseFile(base_path('../docs/contracts/web-api.openapi.yaml'));

        return $contract;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidPaginationQueries(): array
    {
        return [
            'per_page above maximum' => ['per_page=101'],
            'per_page zero' => ['per_page=0'],
            'per_page negative' => ['per_page=-5'],
            'per_page fractional' => ['per_page=1.5'],
            'page zero' => ['page=0'],
            'page negative' => ['page=-5'],
            'page fractional' => ['page=1.5'],
            'page not a number' => ['page=abc'],
        ];
    }

    #[DataProvider('invalidPaginationQueries')]
    public function test_spec_endpoints_list_rejects_out_of_range_pagination_instead_of_clamping(string $query): void
    {
        $owner = User::factory()->tester()->create();
        $project = Project::factory()->create(['owner_id' => $owner->id]);
        $spec = ApiSpec::factory()->create(['project_id' => $project->id, 'status' => ApiSpecStatus::Parsed]);
        Endpoint::factory()->create(['api_spec_id' => $spec->id]);

        $response = $this->actingAs($owner)->getJson("/api/v1/specs/{$spec->id}/endpoints?{$query}");

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $parameter = explode('=', $query)[0];
        $this->assertArrayHasKey($parameter, (array) $response->json('error.details'));
    }

    public function test_spec_endpoints_list_accepts_the_documented_bounds(): void
    {
        $owner = User::factory()->tester()->create();
        $project = Project::factory()->create(['owner_id' => $owner->id]);
        $spec = ApiSpec::factory()->create(['project_id' => $project->id, 'status' => ApiSpecStatus::Parsed]);
        Endpoint::factory()->count(2)->create(['api_spec_id' => $spec->id]);

        $this->actingAs($owner)->getJson("/api/v1/specs/{$spec->id}/endpoints?per_page=100&page=1")
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonPath('meta.total', 2);
        $this->actingAs($owner)->getJson("/api/v1/specs/{$spec->id}/endpoints?per_page=1&page=2")
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonCount(1, 'data');
    }

    public function test_spec_endpoints_list_checks_access_before_pagination(): void
    {
        $owner = User::factory()->tester()->create();
        $stranger = User::factory()->tester()->create();
        $project = Project::factory()->create(['owner_id' => $owner->id]);
        $spec = ApiSpec::factory()->create(['project_id' => $project->id, 'status' => ApiSpecStatus::Parsed]);

        $this->actingAs($stranger)->getJson("/api/v1/specs/{$spec->id}/endpoints?per_page=101")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN')
            ->assertJsonPath('error.message', 'Bạn không có quyền truy cập các endpoint của bản đặc tả này.');
    }

    public function test_every_list_operation_rejects_invalid_pagination_with_validation_failed(): void
    {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->create(['owner_id' => $admin->id]);
        $suite = TestSuite::factory()->create(['project_id' => $project->id, 'created_by' => $admin->id]);
        $run = TestRun::factory()->create(['test_suite_id' => $suite->id, 'triggered_by' => $admin->id]);
        $spec = ApiSpec::factory()->create(['project_id' => $project->id, 'status' => ApiSpecStatus::Parsed]);

        $uris = [
            '/api/v1/projects',
            "/api/v1/specs/{$spec->id}/endpoints",
            "/api/v1/projects/{$project->id}/business-rules",
            "/api/v1/projects/{$project->id}/suites",
            "/api/v1/suites/{$suite->id}/test-cases",
            "/api/v1/runs/{$run->id}/results",
            '/api/v1/experiments',
            '/api/v1/admin/users',
            '/api/v1/admin/benchmarks',
        ];

        foreach ($uris as $uri) {
            $this->actingAs($admin)->getJson($uri.'?per_page=101')
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }

        $this->actingAs($admin)->getJson("/api/v1/suites/{$suite->id}/test-cases?status=BOGUS")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->actingAs($admin)->getJson("/api/v1/runs/{$run->id}/results?verdict=BOGUS")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_contract_documents_422_for_every_paginated_or_filtered_list(): void
    {
        $contract = $this->contract();
        /** @var array<string, array<string, mixed>> $paths */
        $paths = $contract['paths'];
        $checked = [];

        foreach ($paths as $path => $item) {
            /** @var array<string, mixed>|null $get */
            $get = $item['get'] ?? null;
            if (! is_array($get)) {
                continue;
            }
            /** @var list<array<string, mixed>> $parameters */
            $parameters = $get['parameters'] ?? [];
            $queryParameters = array_filter(
                $parameters,
                fn (array $p): bool => in_array($p['$ref'] ?? null, ['#/components/parameters/Page', '#/components/parameters/PerPage'], true)
                    || ($p['in'] ?? null) === 'query',
            );
            if ($queryParameters === [] || $path === '/runs/{runId}/export' || $path === '/experiments/{experimentId}/export') {
                continue;
            }

            // YAML status keys such as "422" become integer array keys in PHP.
            /** @var array<int|string, array<string, mixed>> $responses */
            $responses = $get['responses'];
            $this->assertArrayHasKey(422, $responses, "GET {$path} must document 422 for invalid query parameters");
            $response = $responses[422];
            $documentsError = ($response['$ref'] ?? null) === '#/components/responses/ValidationFailed'
                || (($response['content']['application/json']['schema']['$ref'] ?? null) === '#/components/schemas/Error'
                    && str_contains((string) ($response['description'] ?? ''), 'VALIDATION_FAILED'));
            $this->assertTrue($documentsError, "GET {$path} 422 must describe VALIDATION_FAILED with the Error envelope");
            $checked[] = (string) $get['operationId'];
        }

        foreach (['listProjects', 'listApiSpecEndpoints', 'listBusinessRules', 'listTestSuites', 'listTestCases', 'listTestRunResults', 'listExperiments', 'listUsers', 'listBenchmarks'] as $operation) {
            $this->assertContains($operation, $checked);
        }
    }

    public function test_contract_business_rule_code_pattern_matches_what_the_server_accepts(): void
    {
        $contract = $this->contract();
        $owner = User::factory()->tester()->create();
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        $samples = ['br-001' => true, 'BR_LIMIT-2' => true, 'a b' => false, 'BR.1' => false, 'mã' => false];

        foreach (['BusinessRuleWrite', 'BusinessRulePatch'] as $schema) {
            $pattern = $contract['components']['schemas'][$schema]['properties']['code']['pattern'] ?? null;
            $this->assertIsString($pattern, "{$schema}.code must declare a pattern");

            foreach ($samples as $code => $accepted) {
                $this->assertSame($accepted, preg_match('/'.$pattern.'/u', $code) === 1, "{$schema}.code pattern on '{$code}'");
            }
        }

        $i = 0;
        foreach ($samples as $code => $accepted) {
            $response = $this->actingAs($owner)->postJson("/api/v1/projects/{$project->id}/business-rules", [
                'code' => $code,
                'description' => 'Quy tắc mẫu '.$i++,
            ]);
            $response->assertStatus($accepted ? 201 : 422);
            if ($accepted) {
                $response->assertJsonPath('data.code', strtoupper($code));
            }
        }
    }
}
