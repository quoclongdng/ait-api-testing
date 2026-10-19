<?php

namespace Tests\Feature\Generation;

use App\Actions\Generation\ExecuteRuleGenerationAction;
use App\Actions\Generation\TransitionGenerationJobAction;
use App\Enums\ApiSpecStatus;
use App\Enums\ErrorCode;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\HttpMethod;
use App\Enums\TestCaseSource;
use App\Enums\TestCaseStatus;
use App\Exceptions\ApiException;
use App\Http\ApiErrorRenderer;
use App\Jobs\Generation\GenerateRuleTestCases;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\TestCase as TestCaseModel;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase as BaseTestCase;

class RuleGenerationJobTest extends BaseTestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') === 'pgsql') {
            $host = (string) config('database.connections.pgsql.host');
            $port = (int) config('database.connections.pgsql.port');
            $connection = @fsockopen($host, $port, $errno, $errstr, 0.1);
            if (! $connection) {
                config(['database.default' => 'sqlite']);
                config(['database.connections.sqlite.database' => ':memory:']);
            } else {
                fclose($connection);
            }
        }
    }

    public function test_api_creates_queued_rule_generation_job_and_dispatches_queue_work(): void
    {
        Queue::fake();
        [$user, $suite, $endpoint] = $this->createGenerationContext();

        $response = $this->actingAs($user)->postJson(
            "/api/v1/suites/{$suite->id}/generation-jobs",
            [
                'strategy' => 'RULE',
                'params' => [
                    'endpoint_ids' => [$endpoint->id],
                    'techniques' => ['HAPPY_PATH'],
                    'max_cases_per_endpoint' => 2,
                ],
            ]
        );

        $response->assertAccepted()
            ->assertJsonPath('data.strategy', 'RULE')
            ->assertJsonPath('data.status', 'QUEUED')
            ->assertJsonPath('data.test_suite_id', $suite->id)
            ->assertJsonPath('data.params.endpoint_ids.0', $endpoint->id);

        $generationJobId = $response->json('data.id');
        $this->assertDatabaseHas('generation_jobs', [
            'id' => $generationJobId,
            'status' => GenerationJobStatus::Queued->value,
            'strategy' => GenerationStrategy::Rule->value,
        ]);
        Queue::assertPushed(GenerateRuleTestCases::class, fn (GenerateRuleTestCases $job): bool => $job->generationJobId === $generationJobId);
        // S14-06: RULE jobs stay on the default queue, never behind LLM/HYBRID generation.
        Queue::assertPushedOn('default', GenerateRuleTestCases::class);
    }

    public function test_api_rejects_unknown_strategy_and_endpoints_outside_suite_spec(): void
    {
        Queue::fake();
        [$user, $suite, $endpoint] = $this->createGenerationContext();
        $otherEndpoint = Endpoint::factory()->create(['api_spec_id' => ApiSpec::factory()->create([
            'project_id' => $suite->project_id,
            'status' => ApiSpecStatus::Parsed,
        ])->id]);

        $this->actingAs($user)->postJson("/api/v1/suites/{$suite->id}/generation-jobs", [
            'strategy' => 'GENETIC',
            'params' => ['endpoint_ids' => [$endpoint->id]],
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->actingAs($user)->postJson("/api/v1/suites/{$suite->id}/generation-jobs", [
            'strategy' => 'RULE',
            'params' => ['endpoint_ids' => [$otherEndpoint->id]],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('generation_jobs', 0);
        Queue::assertNothingPushed();
    }

    public function test_queue_worker_generates_and_persists_valid_test_cases_and_terminal_stats(): void
    {
        [$user, $suite, $endpoint] = $this->createGenerationContext();
        $generationJob = GenerationJob::factory()->create([
            'project_id' => $suite->project_id,
            'api_spec_id' => $suite->api_spec_id,
            'test_suite_id' => $suite->id,
            'created_by' => $user->id,
            'strategy' => GenerationStrategy::Rule,
            'status' => GenerationJobStatus::Queued,
            'params' => [
                'endpoint_ids' => [$endpoint->id],
                'techniques' => ['HAPPY_PATH'],
                'max_cases_per_endpoint' => 2,
            ],
        ]);

        (new GenerateRuleTestCases($generationJob->id))->handle(app(ExecuteRuleGenerationAction::class));

        $generationJob->refresh();
        $this->assertSame(GenerationJobStatus::Succeeded, $generationJob->status);
        $this->assertNotNull($generationJob->started_at);
        $this->assertNotNull($generationJob->finished_at);
        $stats = $generationJob->stats ?? [];
        $this->assertSame(1, $stats['generated'] ?? null);
        $this->assertSame(1, $stats['valid'] ?? null);
        $this->assertSame(0, $stats['invalid'] ?? null);
        $this->assertDatabaseHas('test_cases', [
            'generation_job_id' => $generationJob->id,
            'test_suite_id' => $suite->id,
            'endpoint_id' => $endpoint->id,
            'source' => TestCaseSource::Rule->value,
            'status' => TestCaseStatus::Valid->value,
        ]);
    }

    public function test_worker_fingerprints_cases_and_marks_regenerated_cases_as_duplicates(): void
    {
        [$user, $suite, $endpoint] = $this->createGenerationContext();
        $params = [
            'endpoint_ids' => [$endpoint->id],
            'techniques' => ['HAPPY_PATH'],
        ];
        $first = $this->createRuleJob($user, $suite, $params);
        app(ExecuteRuleGenerationAction::class)->execute($first->id);

        $first->refresh();
        $this->assertSame(GenerationJobStatus::Succeeded, $first->status);
        $fingerprint = TestCaseModel::query()->where('generation_job_id', $first->id)->value('fingerprint');
        $this->assertIsString($fingerprint);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $fingerprint);

        $second = $this->createRuleJob($user, $suite, $params);
        app(ExecuteRuleGenerationAction::class)->execute($second->id);

        $second->refresh();
        // Only duplicates and nothing failed: SUCCEEDED without a new case (§4.1, S14-06).
        $this->assertSame(GenerationJobStatus::Succeeded, $second->status);
        $this->assertNull($second->error);
        $this->assertSame(0, $second->stats['valid'] ?? null);
        $this->assertSame(1, $second->stats['duplicates'] ?? null);
        $this->assertDatabaseHas('test_cases', [
            'generation_job_id' => $second->id,
            'status' => TestCaseStatus::Duplicate->value,
            'fingerprint' => $fingerprint,
        ]);
    }

    public function test_worker_rejects_jobs_that_are_not_queued(): void
    {
        [$user, $suite, $endpoint] = $this->createGenerationContext();
        $generationJob = $this->createRuleJob($user, $suite, ['endpoint_ids' => [$endpoint->id]]);
        $generationJob->update(['status' => GenerationJobStatus::Succeeded]);

        try {
            app(ExecuteRuleGenerationAction::class)->execute($generationJob->id);
            $this->fail('A SUCCEEDED job must not be executed again.');
        } catch (ApiException $exception) {
            $this->assertSame(ErrorCode::InvalidStateTransition, $exception->errorCode);
        }

        $this->assertSame(GenerationJobStatus::Succeeded, $generationJob->refresh()->status);
        $this->assertDatabaseCount('test_cases', 0);
    }

    public function test_queue_failure_handler_marks_queued_job_as_failed(): void
    {
        config(['app.debug' => true]);
        [$user, $suite, $endpoint] = $this->createGenerationContext();
        $generationJob = $this->createRuleJob($user, $suite, ['endpoint_ids' => [$endpoint->id]]);

        (new GenerateRuleTestCases($generationJob->id))->failed(new \RuntimeException('worker crashed'));

        $generationJob->refresh();
        $this->assertSame(GenerationJobStatus::Failed, $generationJob->status);
        // Raw detail only with APP_DEBUG=true (see ErrorMessageSanitizationTest for debug off).
        $this->assertStringContainsString('worker crashed', (string) $generationJob->error);
        $this->assertNotNull($generationJob->finished_at);
    }

    public function test_worker_failure_path_moves_running_rule_job_to_failed(): void
    {
        [$user, $suite, $endpoint] = $this->createGenerationContext();
        $generationJob = $this->createRuleJob($user, $suite, ['endpoint_ids' => [$endpoint->id]]);
        $generationJob->update(['status' => GenerationJobStatus::Running]);

        // The worker calls CallQueuedHandler::failed(), which invokes $command->failed($e) with ONE argument.
        app(CallQueuedHandler::class)->failed(
            ['command' => serialize(new GenerateRuleTestCases($generationJob->id))],
            new TimeoutExceededException('timed out'),
            (string) Str::uuid(),
        );

        $generationJob->refresh();
        $this->assertSame(GenerationJobStatus::Failed, $generationJob->status);
        $this->assertNotNull($generationJob->finished_at);
    }

    public function test_api_rate_limits_generation_job_creation(): void
    {
        Queue::fake();
        [$user, $suite, $endpoint] = $this->createGenerationContext();
        $payload = ['strategy' => 'RULE', 'params' => ['endpoint_ids' => [$endpoint->id]]];

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->postJson("/api/v1/suites/{$suite->id}/generation-jobs", $payload)
                ->assertAccepted();
        }

        $this->actingAs($user)->postJson("/api/v1/suites/{$suite->id}/generation-jobs", $payload)
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    public function test_api_forbids_creating_jobs_on_another_users_suite(): void
    {
        Queue::fake();
        [, $suite, $endpoint] = $this->createGenerationContext();

        $this->actingAs(User::factory()->tester()->create())
            ->postJson("/api/v1/suites/{$suite->id}/generation-jobs", [
                'strategy' => 'RULE',
                'params' => ['endpoint_ids' => [$endpoint->id]],
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->assertDatabaseCount('generation_jobs', 0);
    }

    public function test_job_marks_partial_when_some_endpoints_cannot_generate_cases(): void
    {
        [$user, $suite, $endpoint] = $this->createGenerationContext();
        $unsupportedEndpoint = Endpoint::factory()->create([
            'api_spec_id' => $suite->api_spec_id,
            'method' => HttpMethod::Get,
            'path' => '/not-found',
            'responses' => ['404' => ['description' => 'Not found']],
        ]);
        $generationJob = GenerationJob::factory()->create([
            'project_id' => $suite->project_id,
            'api_spec_id' => $suite->api_spec_id,
            'test_suite_id' => $suite->id,
            'created_by' => $user->id,
            'strategy' => GenerationStrategy::Rule,
            'status' => GenerationJobStatus::Queued,
            'params' => [
                'endpoint_ids' => [$endpoint->id, $unsupportedEndpoint->id],
                'techniques' => ['HAPPY_PATH'],
            ],
        ]);

        app(ExecuteRuleGenerationAction::class)->execute($generationJob->id);

        $generationJob->refresh();
        $this->assertSame(GenerationJobStatus::Partial, $generationJob->status);
        $this->assertSame(1, $generationJob->stats['valid'] ?? null);
        $this->assertNotEmpty($generationJob->error);
    }

    public function test_job_fails_when_no_valid_cases_can_be_generated(): void
    {
        [$user, $suite] = $this->createGenerationContext();
        $endpoint = Endpoint::factory()->create([
            'api_spec_id' => $suite->api_spec_id,
            'method' => HttpMethod::Get,
            'path' => '/not-found',
            'responses' => ['404' => ['description' => 'Not found']],
        ]);
        $generationJob = GenerationJob::factory()->create([
            'project_id' => $suite->project_id,
            'api_spec_id' => $suite->api_spec_id,
            'test_suite_id' => $suite->id,
            'created_by' => $user->id,
            'strategy' => GenerationStrategy::Rule,
            'status' => GenerationJobStatus::Queued,
            'params' => ['endpoint_ids' => [$endpoint->id], 'techniques' => ['HAPPY_PATH']],
        ]);

        app(ExecuteRuleGenerationAction::class)->execute($generationJob->id);

        $generationJob->refresh();
        $this->assertSame(GenerationJobStatus::Failed, $generationJob->status);
        $this->assertSame(0, $generationJob->stats['valid'] ?? null);
        $this->assertNotNull($generationJob->finished_at);
    }

    #[DataProvider('validGenerationJobTransitions')]
    public function test_generation_job_accepts_every_transition_defined_by_state_machine(
        GenerationJobStatus $from,
        GenerationJobStatus $to,
    ): void {
        $generationJob = GenerationJob::factory()->create(['status' => $from]);
        $transitioned = app(TransitionGenerationJobAction::class)->execute($generationJob, $to);

        $this->assertSame($to, $transitioned->status);
        $this->assertSame($to, $generationJob->refresh()->status);
    }

    /**
     * @return array<string, array{GenerationJobStatus, GenerationJobStatus}>
     */
    public static function validGenerationJobTransitions(): array
    {
        return [
            'QUEUED to RUNNING' => [GenerationJobStatus::Queued, GenerationJobStatus::Running],
            'QUEUED to FAILED' => [GenerationJobStatus::Queued, GenerationJobStatus::Failed],
            'RUNNING to SUCCEEDED' => [GenerationJobStatus::Running, GenerationJobStatus::Succeeded],
            'RUNNING to PARTIAL' => [GenerationJobStatus::Running, GenerationJobStatus::Partial],
            'RUNNING to FAILED' => [GenerationJobStatus::Running, GenerationJobStatus::Failed],
        ];
    }

    #[DataProvider('invalidGenerationJobTransitions')]
    public function test_generation_job_rejects_every_transition_not_defined_by_state_machine(
        GenerationJobStatus $from,
        GenerationJobStatus $to,
    ): void {
        $generationJob = GenerationJob::factory()->create(['status' => $from]);

        try {
            app(TransitionGenerationJobAction::class)->execute($generationJob, $to);
            $this->fail("{$from->value} to {$to->value} must be rejected.");
        } catch (ApiException $exception) {
            $this->assertSame(ErrorCode::InvalidStateTransition, $exception->errorCode);
            $this->assertSame(409, $exception->errorCode->httpStatus());

            $response = app(ApiErrorRenderer::class)($exception, Request::create('/api/v1/generation-jobs/1'));
            $this->assertNotNull($response);
            $this->assertSame(409, $response->getStatusCode());
            $this->assertSame('INVALID_STATE_TRANSITION', $response->getData(true)['error']['code']);
        }

        $this->assertSame($from, $generationJob->refresh()->status);
    }

    /**
     * @return array<string, array{GenerationJobStatus, GenerationJobStatus}>
     */
    public static function invalidGenerationJobTransitions(): array
    {
        $allowed = [
            GenerationJobStatus::Queued->value.'>'.GenerationJobStatus::Running->value,
            GenerationJobStatus::Queued->value.'>'.GenerationJobStatus::Failed->value,
            GenerationJobStatus::Running->value.'>'.GenerationJobStatus::Succeeded->value,
            GenerationJobStatus::Running->value.'>'.GenerationJobStatus::Partial->value,
            GenerationJobStatus::Running->value.'>'.GenerationJobStatus::Failed->value,
        ];
        $cases = [];
        foreach (GenerationJobStatus::cases() as $from) {
            foreach (GenerationJobStatus::cases() as $to) {
                if (! in_array($from->value.'>'.$to->value, $allowed, true)) {
                    $cases[$from->value.' to '.$to->value] = [$from, $to];
                }
            }
        }

        return $cases;
    }

    public function test_generation_job_status_is_visible_only_to_project_owner_or_admin(): void
    {
        [$owner, $suite] = $this->createGenerationContext();
        $generationJob = GenerationJob::factory()->create([
            'project_id' => $suite->project_id,
            'api_spec_id' => $suite->api_spec_id,
            'test_suite_id' => $suite->id,
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)->getJson("/api/v1/generation-jobs/{$generationJob->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $generationJob->id);

        $this->actingAs(User::factory()->tester()->create())
            ->getJson("/api/v1/generation-jobs/{$generationJob->id}")
            ->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())
            ->getJson("/api/v1/generation-jobs/{$generationJob->id}")
            ->assertOk();
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function createRuleJob(User $user, TestSuite $suite, array $params): GenerationJob
    {
        return GenerationJob::factory()->create([
            'project_id' => $suite->project_id,
            'api_spec_id' => $suite->api_spec_id,
            'test_suite_id' => $suite->id,
            'created_by' => $user->id,
            'strategy' => GenerationStrategy::Rule,
            'status' => GenerationJobStatus::Queued,
            'params' => $params,
        ]);
    }

    /**
     * @return array{User, TestSuite, Endpoint}
     */
    private function createGenerationContext(): array
    {
        $user = User::factory()->tester()->create();
        $project = Project::factory()->for($user, 'owner')->create();
        $apiSpec = ApiSpec::factory()->for($project)->create(['status' => ApiSpecStatus::Parsed]);
        $suite = TestSuite::factory()->create([
            'project_id' => $project->id,
            'api_spec_id' => $apiSpec->id,
            'created_by' => $user->id,
        ]);
        $endpoint = Endpoint::factory()->create([
            'api_spec_id' => $apiSpec->id,
            'method' => HttpMethod::Post,
            'path' => '/items',
            'request_body' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => ['name' => ['type' => 'string']],
                            'required' => ['name'],
                        ],
                    ],
                ],
            ],
            'responses' => [
                '201' => ['description' => 'Created'],
                '422' => ['description' => 'Invalid input'],
            ],
        ]);

        return [$user, $suite, $endpoint];
    }
}
