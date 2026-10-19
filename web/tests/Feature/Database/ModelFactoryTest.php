<?php

namespace Tests\Feature\Database;

use App\Enums\ApiSpecFormat;
use App\Enums\ApiSpecStatus;
use App\Enums\BenchmarkBugCategory;
use App\Enums\ExperimentStatus;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\HttpMethod;
use App\Enums\LlmPurpose;
use App\Enums\TestCaseSource;
use App\Enums\TestCaseStatus;
use App\Enums\TestRunStatus;
use App\Enums\TestTechnique;
use App\Enums\TestVerdict;
use App\Enums\UserRole;
use App\Models\AllowedTarget;
use App\Models\ApiSpec;
use App\Models\Benchmark;
use App\Models\BenchmarkBug;
use App\Models\BusinessRule;
use App\Models\Endpoint;
use App\Models\Experiment;
use App\Models\ExperimentBugDetection;
use App\Models\GenerationJob;
use App\Models\LlmCallLog;
use App\Models\Project;
use App\Models\TestCase;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Opis\JsonSchema\Validator as JsonSchemaValidator;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase as BaseTestCase;

class ModelFactoryTest extends BaseTestCase
{
    use RefreshDatabase;

    public function test_user_factory_creates_record_with_enums(): void
    {
        $user = User::factory()->create();
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertSame(UserRole::Tester, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertFalse($user->isAdmin());

        $admin = User::factory()->admin()->create();
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertTrue($admin->isAdmin());

        $inactive = User::factory()->inactive()->create();
        $this->assertFalse($inactive->is_active);
    }

    public function test_project_factory_creates_record_and_relations(): void
    {
        $project = Project::factory()->create();
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
        $this->assertInstanceOf(User::class, $project->owner);
        $this->assertTrue($project->owner->projects->contains($project));
    }

    public function test_allowed_target_factory_creates_record(): void
    {
        $target = AllowedTarget::factory()->create();
        $this->assertDatabaseHas('allowed_targets', ['id' => $target->id]);
        $this->assertInstanceOf(User::class, $target->creator);
    }

    public function test_api_spec_factory_creates_record(): void
    {
        $spec = ApiSpec::factory()->create();
        $this->assertDatabaseHas('api_specs', ['id' => $spec->id]);
        $this->assertSame(ApiSpecFormat::Json, $spec->format);
        $this->assertSame(ApiSpecStatus::Uploaded, $spec->status);
        $this->assertNotNull($spec->normalized);
        $this->assertInstanceOf(Project::class, $spec->project);
    }

    public function test_endpoint_factory_creates_record(): void
    {
        $endpoint = Endpoint::factory()->create();
        $this->assertDatabaseHas('endpoints', ['id' => $endpoint->id]);
        $this->assertInstanceOf(HttpMethod::class, $endpoint->method);
        $this->assertInstanceOf(ApiSpec::class, $endpoint->apiSpec);
        $this->assertArrayHasKey('200', $endpoint->responses);
    }

    public function test_business_rule_factory_creates_record(): void
    {
        $rule = BusinessRule::factory()->create();
        $this->assertDatabaseHas('business_rules', ['id' => $rule->id]);
        $this->assertInstanceOf(Project::class, $rule->project);
    }

    public function test_test_suite_factory_creates_record(): void
    {
        $suite = TestSuite::factory()->create();
        $this->assertDatabaseHas('test_suites', ['id' => $suite->id]);
        $this->assertInstanceOf(Project::class, $suite->project);
        $this->assertInstanceOf(ApiSpec::class, $suite->apiSpec);
        $this->assertInstanceOf(User::class, $suite->creator);
    }

    public function test_generation_job_factory_creates_record(): void
    {
        $job = GenerationJob::factory()->create();
        $this->assertDatabaseHas('generation_jobs', ['id' => $job->id]);
        $this->assertSame(GenerationStrategy::Rule, $job->strategy);
        $this->assertSame(GenerationJobStatus::Queued, $job->status);
        $this->assertInstanceOf(TestSuite::class, $job->testSuite);
    }

    public function test_test_case_factory_creates_record(): void
    {
        $case = TestCase::factory()->create();
        $this->assertDatabaseHas('test_cases', ['id' => $case->id]);
        $this->assertSame(TestCaseSource::Rule, $case->source);
        $this->assertSame(TestTechnique::HappyPath, $case->technique);
        $this->assertSame(TestCaseStatus::Valid, $case->status);
        $this->assertNotNull($case->generation_job_id);
        $this->assertSame($case->suite->api_spec_id, $case->endpoint->api_spec_id);

        $this->assertSame('1.0', $case->dsl['dsl_version']);
        $this->assertSame($case->endpoint->path, $case->dsl['endpoint']['path']);
        $this->assertSame($case->endpoint->method->value, $case->dsl['endpoint']['method']);
        $this->assertSame('status', $case->dsl['steps'][0]['assertions'][0]['type']);
        $this->assertSame(200, $case->dsl['steps'][0]['assertions'][0]['equals']);

        $this->assertInstanceOf(TestSuite::class, $case->suite);
        $this->assertInstanceOf(Endpoint::class, $case->endpoint);
    }

    public function test_test_case_factory_manual_state(): void
    {
        $case = TestCase::factory()->manual()->create();
        $this->assertSame(TestCaseSource::Manual, $case->source);
        $this->assertNull($case->generation_job_id);
    }

    public function test_test_case_factory_invalid_state(): void
    {
        $case = TestCase::factory()->invalid()->create();
        $this->assertSame(TestCaseStatus::Invalid, $case->status);
        $this->assertNull($case->fingerprint);
        $this->assertNotNull($case->validation_errors);
    }

    public function test_llm_call_log_factory_creates_record(): void
    {
        $log = LlmCallLog::factory()->create();
        $this->assertDatabaseHas('llm_call_logs', ['id' => $log->id]);
        $this->assertSame(LlmPurpose::Generate, $log->purpose);
        $this->assertArrayHasKey('messages', $log->prompt);
        $this->assertInstanceOf(GenerationJob::class, $log->generationJob);
    }

    public function test_llm_call_log_is_insert_only_and_blocks_update_and_delete(): void
    {
        $log = LlmCallLog::factory()->create();

        $this->expectException(\LogicException::class);
        $log->update(['model' => 'other-model']);
    }

    public function test_llm_call_log_is_insert_only_and_blocks_delete(): void
    {
        $log = LlmCallLog::factory()->create();

        $this->expectException(\LogicException::class);
        $log->delete();
    }

    public function test_test_run_factory_creates_record(): void
    {
        $run = TestRun::factory()->create();
        $this->assertDatabaseHas('test_runs', ['id' => $run->id]);
        $this->assertSame(TestRunStatus::Queued, $run->status);
        $this->assertInstanceOf(TestSuite::class, $run->testSuite);
        $this->assertInstanceOf(User::class, $run->triggerer);
    }

    public function test_test_result_factory_creates_record(): void
    {
        $result = TestResult::factory()->create();
        $this->assertDatabaseHas('test_results', ['id' => $result->id]);
        $this->assertSame(TestVerdict::Pass, $result->verdict);
        $this->assertArrayHasKey('method', $result->request_log);
        $this->assertArrayHasKey('status', $result->response_log);
        $this->assertNotEmpty($result->assertion_results);
        $this->assertInstanceOf(TestRun::class, $result->testRun);
        $this->assertInstanceOf(TestCase::class, $result->testCase);
        $this->assertSame($result->testRun->test_suite_id, $result->testCase->test_suite_id);
    }

    public function test_test_result_is_insert_only_and_blocks_update(): void
    {
        $result = TestResult::factory()->create();

        $this->expectException(\LogicException::class);
        $result->update(['duration_ms' => 999]);
    }

    public function test_test_result_is_insert_only_and_blocks_delete(): void
    {
        $result = TestResult::factory()->create();

        $this->expectException(\LogicException::class);
        $result->delete();
    }

    public function test_benchmark_factory_creates_record(): void
    {
        $benchmark = Benchmark::factory()->create();
        $this->assertDatabaseHas('benchmarks', ['id' => $benchmark->id]);
    }

    public function test_benchmark_bug_factory_creates_record(): void
    {
        $bug = BenchmarkBug::factory()->create();
        $this->assertDatabaseHas('benchmark_bugs', ['id' => $bug->id]);
        $this->assertSame(HttpMethod::Get, $bug->method);
        $this->assertSame(BenchmarkBugCategory::ValidationMissing, $bug->category);
        $this->assertInstanceOf(Benchmark::class, $bug->benchmark);
    }

    public function test_experiment_factory_creates_record(): void
    {
        $experiment = Experiment::factory()->create();
        $this->assertDatabaseHas('experiments', ['id' => $experiment->id]);
        $this->assertSame(GenerationStrategy::Rule, $experiment->strategy);
        $this->assertSame(ExperimentStatus::Planned, $experiment->status);
        $this->assertArrayHasKey('repetitions', $experiment->config);
        $this->assertInstanceOf(Benchmark::class, $experiment->benchmark);
    }

    public function test_experiment_bug_detection_factory_creates_record(): void
    {
        $detection = ExperimentBugDetection::factory()->create();
        $this->assertDatabaseHas('experiment_bug_detections', ['id' => $detection->id]);
        $this->assertTrue($detection->detected);
        $this->assertInstanceOf(Experiment::class, $detection->experiment);
        $this->assertInstanceOf(BenchmarkBug::class, $detection->benchmarkBug);
        $this->assertInstanceOf(TestRun::class, $detection->cleanTestRun);
        $this->assertInstanceOf(TestRun::class, $detection->bugTestRun);
        $this->assertSame($detection->experiment->benchmark_id, $detection->benchmarkBug->benchmark_id);

        // Consistency checks ensuring TestRuns prove D14
        $this->assertSame($detection->cleanTestRun->test_suite_id, $detection->bugTestRun->test_suite_id);
        $this->assertSame('clean', $detection->cleanTestRun->benchmark_variant);
        $this->assertSame($detection->benchmarkBug->code, $detection->bugTestRun->benchmark_variant);
        $this->assertSame(TestRunStatus::Completed, $detection->cleanTestRun->status);
        $this->assertSame(TestRunStatus::Completed, $detection->bugTestRun->status);
        $this->assertNotEmpty($detection->detecting_test_case_ids);

        // Verify totals match DB/OpenAPI TestRunTotals contract
        $this->assertMatchesTestRunTotalsSchema($detection->cleanTestRun->totals);
        $this->assertMatchesTestRunTotalsSchema($detection->bugTestRun->totals);
        $this->assertSameTotals(['total' => 1, 'pass' => 1, 'fail' => 0, 'error' => 0, 'skipped' => 0], $detection->cleanTestRun->totals);
        $this->assertSameTotals(['total' => 1, 'pass' => 0, 'fail' => 1, 'error' => 0, 'skipped' => 0], $detection->bugTestRun->totals);

        // Verify test cases and test results prove D14 (PASS on clean, FAIL/ERROR on bug)
        $this->assertSame(1, $detection->cleanTestRun->testSuite->testCases()->count());

        $cleanResult = $detection->cleanTestRun->testResults()->first();
        $this->assertInstanceOf(TestResult::class, $cleanResult);
        $this->assertSame(TestVerdict::Pass, $cleanResult->verdict);

        $bugResult = $detection->bugTestRun->testResults()->first();
        $this->assertInstanceOf(TestResult::class, $bugResult);
        $this->assertSame(TestVerdict::Fail, $bugResult->verdict);

        $this->assertSame($cleanResult->test_case_id, $bugResult->test_case_id);
        $this->assertSame([$cleanResult->test_case_id], $detection->detecting_test_case_ids);
    }

    public function test_experiment_bug_detection_factory_not_detected_state(): void
    {
        $detection = ExperimentBugDetection::factory()->notDetected()->create();
        $this->assertFalse($detection->detected);
        $this->assertSame([], $detection->detecting_test_case_ids);
        $this->assertSame($detection->cleanTestRun->test_suite_id, $detection->bugTestRun->test_suite_id);
        $this->assertSame('clean', $detection->cleanTestRun->benchmark_variant);
        $this->assertSame($detection->benchmarkBug->code, $detection->bugTestRun->benchmark_variant);
        $this->assertSame(TestRunStatus::Completed, $detection->cleanTestRun->status);
        $this->assertSame(TestRunStatus::Completed, $detection->bugTestRun->status);

        // Verify totals match DB/OpenAPI TestRunTotals contract
        $this->assertMatchesTestRunTotalsSchema($detection->cleanTestRun->totals);
        $this->assertMatchesTestRunTotalsSchema($detection->bugTestRun->totals);
        $this->assertSameTotals(['total' => 1, 'pass' => 1, 'fail' => 0, 'error' => 0, 'skipped' => 0], $detection->cleanTestRun->totals);
        $this->assertSameTotals(['total' => 1, 'pass' => 1, 'fail' => 0, 'error' => 0, 'skipped' => 0], $detection->bugTestRun->totals);

        // Suite has test case matching total=1, and both runs PASS
        $this->assertSame(1, $detection->cleanTestRun->testSuite->testCases()->count());

        $cleanResult = $detection->cleanTestRun->testResults()->first();
        $this->assertInstanceOf(TestResult::class, $cleanResult);
        $this->assertSame(TestVerdict::Pass, $cleanResult->verdict);

        $bugResult = $detection->bugTestRun->testResults()->first();
        $this->assertInstanceOf(TestResult::class, $bugResult);
        $this->assertSame(TestVerdict::Pass, $bugResult->verdict);

        $this->assertSame($cleanResult->test_case_id, $bugResult->test_case_id);
    }

    /**
     * @param  array<string, int>  $expected
     * @param  array<string, mixed>|null  $actual
     */
    private function assertSameTotals(array $expected, ?array $actual): void
    {
        $this->assertNotNull($actual);
        // PostgreSQL jsonb does not preserve object key order.
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
    }

    private function assertMatchesTestRunTotalsSchema(mixed $totals): void
    {
        $schema = $this->getTestRunTotalsSchema();
        $validator = new JsonSchemaValidator;
        $data = json_decode((string) json_encode($totals));
        $result = $validator->validate($data, $schema);
        $this->assertTrue(
            $result->isValid(),
            'Totals do not match TestRunTotals schema: '.json_encode($result->error()?->message())
        );
    }

    private function getTestRunTotalsSchema(): object
    {
        $openapiPath = base_path('../docs/contracts/web-api.openapi.yaml');
        /** @var array<string, mixed> $spec */
        $spec = Yaml::parseFile($openapiPath);
        /** @var array<string, mixed> $components */
        $components = $spec['components'];
        /** @var array<string, mixed> $schemas */
        $schemas = $components['schemas'];
        /** @var object $totalsSchema */
        $totalsSchema = json_decode((string) json_encode($schemas['TestRunTotals']));

        return $totalsSchema;
    }

    public function test_experiment_bug_detection_is_insert_only_and_blocks_update(): void
    {
        $detection = ExperimentBugDetection::factory()->create();

        $this->expectException(\LogicException::class);
        $detection->update(['detected' => false]);
    }

    public function test_experiment_bug_detection_is_insert_only_and_blocks_delete(): void
    {
        $detection = ExperimentBugDetection::factory()->create();

        $this->expectException(\LogicException::class);
        $detection->delete();
    }
}
