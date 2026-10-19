<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\TransitionTestRunStatusAction;
use App\Contracts\TargetHttpClient;
use App\Data\TargetRequest;
use App\Data\TargetResponse;
use App\Enums\ErrorCode;
use App\Enums\TestCaseStatus;
use App\Enums\TestRunStatus;
use App\Enums\TestVerdict;
use App\Exceptions\ApiException;
use App\Jobs\ExecuteTestRunJob;
use App\Models\Project;
use App\Models\TestCase;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\Target\FakeTargetHttpClient;
use App\Support\StepLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\TimeoutExceededException;
use RuntimeException;
use Tests\TestCase as FeatureTestCase;

class ExecuteTestRunJobTest extends FeatureTestCase
{
    use RefreshDatabase;

    private FakeTargetHttpClient $fakeClient;

    private User $user;

    private TestSuite $suite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeClient = new FakeTargetHttpClient;
        $this->app->instance(TargetHttpClient::class, $this->fakeClient);

        $this->user = User::factory()->create();
        $project = Project::factory()->for($this->user, 'owner')->create();
        $this->suite = TestSuite::factory()->for($project)->for($this->user, 'creator')->create();
    }

    public function test_it_executes_only_valid_and_approved_test_cases(): void
    {
        $valid = $this->makeCase(TestCaseStatus::Valid, 'GET', '/items', 200);
        $approved = $this->makeCase(TestCaseStatus::Approved, 'POST', '/items', 201);
        foreach ([TestCaseStatus::Invalid, TestCaseStatus::Duplicate, TestCaseStatus::Archived] as $status) {
            $this->makeCase($status, 'GET', '/ignored', 200);
        }

        $this->fakeClient->respondWith(200, ['data' => []]);
        $this->fakeClient->respondWith(201, ['id' => 1]);

        $run = $this->runJob($this->makeRun());

        $this->assertSame(TestRunStatus::Completed, $run->status);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
        $this->assertEquals(['total' => 2, 'pass' => 2, 'fail' => 0, 'error' => 0, 'skipped' => 0], $run->totals);
        $this->assertCount(2, $this->fakeClient->sent());

        $results = TestResult::where('test_run_id', $run->id)->orderBy('id')->get();
        $this->assertSame([$valid->id, $approved->id], $results->pluck('test_case_id')->all());
        foreach ($results as $result) {
            $this->assertSame(TestVerdict::Pass, $result->verdict);
            $this->assertNull($result->error_message);
        }
    }

    public function test_it_records_fail_verdict_with_contract_shaped_assertion_results(): void
    {
        $this->makeCase(TestCaseStatus::Valid, 'GET', '/health', 200);
        $this->fakeClient->respondWith(500, ['error' => 'server error']);

        $run = $this->runJob($this->makeRun());

        $this->assertSame(TestRunStatus::Completed, $run->status);
        $this->assertEquals(['total' => 1, 'pass' => 0, 'fail' => 1, 'error' => 0, 'skipped' => 0], $run->totals);

        $result = TestResult::where('test_run_id', $run->id)->firstOrFail();
        $this->assertSame(TestVerdict::Fail, $result->verdict);
        $this->assertCount(1, $result->assertion_results);
        $assertion = array_values($result->assertion_results)[0] ?? null;
        $this->assertIsArray($assertion);
        $this->assertEqualsCanonicalizing(['type', 'passed', 'expected', 'actual', 'message'], array_keys($assertion));
        $this->assertSame('status', $assertion['type']);
        $this->assertFalse($assertion['passed']);
        $this->assertSame(200, $assertion['expected']);
        $this->assertSame(500, $assertion['actual']);
        $this->assertSame(500, $result->response_log['status']);
        $this->assertSame('GET', $result->request_log['method']);
    }

    public function test_experiment_case_rerun_without_credentials_reports_the_unresolved_auth_token(): void
    {
        // S11-01: a case stored by an experiment keeps Authorization: Bearer {{auth_token_<role>}};
        // re-running its suite from M12 (no auth fixture) must not send the placeholder.
        $this->makeCase(TestCaseStatus::Valid, 'GET', '/cart', 200, ['Authorization' => 'Bearer {{auth_token_customer}}']);
        $this->makeCase(TestCaseStatus::Valid, 'GET', '/products', 200);
        $this->fakeClient->respondWith(200, []);

        $run = $this->runJob($this->makeRun());

        $this->assertSame(TestRunStatus::Completed, $run->status);
        $this->assertEquals(['total' => 2, 'pass' => 1, 'fail' => 0, 'error' => 1, 'skipped' => 0], $run->totals);
        $this->assertCount(1, $this->fakeClient->sent(), 'the case with the unresolved token sends nothing');
        $this->assertSame('/products', parse_url($this->fakeClient->sent()[0]->url, PHP_URL_PATH));

        $result = TestResult::where('test_run_id', $run->id)->orderBy('id')->firstOrFail();
        $this->assertSame(TestVerdict::Error, $result->verdict);
        $this->assertStringContainsString('Biến {{auth_token_customer}} chưa có giá trị', (string) $result->error_message);
        $this->assertStringContainsString('thực nghiệm', (string) $result->error_message);
    }

    public function test_it_records_error_verdict_when_the_request_cannot_be_sent(): void
    {
        $this->makeCase(TestCaseStatus::Valid, 'GET', '/slow', 200);
        $this->app->instance(TargetHttpClient::class, new class implements TargetHttpClient
        {
            public function send(TargetRequest $request): TargetResponse
            {
                throw new RuntimeException('Connection timed out');
            }
        });

        $run = $this->runJob($this->makeRun());

        $this->assertSame(TestRunStatus::Completed, $run->status);
        $this->assertEquals(['total' => 1, 'pass' => 0, 'fail' => 0, 'error' => 1, 'skipped' => 0], $run->totals);
        $result = TestResult::where('test_run_id', $run->id)->firstOrFail();
        $this->assertSame(TestVerdict::Error, $result->verdict);
        $this->assertStringContainsString('Connection timed out', (string) $result->error_message);
        $this->assertSame([], $result->assertion_results);
    }

    /**
     * Regression (S10-01 r2): Guzzle puts the request URI (query included) into every cURL error, so
     * query-string credentials were stored in error_message and every export in plain text.
     */
    public function test_transport_error_message_masks_query_credentials_in_results_and_exports(): void
    {
        $this->makeDslCase([
            'name' => 'Call',
            'request' => ['method' => 'GET', 'path' => '/items', 'query' => ['api_key' => 'SECRET-KEY-123', 'page' => 1]],
            'assertions' => [['type' => 'status', 'equals' => 200]],
        ]);
        $this->app->instance(TargetHttpClient::class, new class implements TargetHttpClient
        {
            public function send(TargetRequest $request): TargetResponse
            {
                throw new RuntimeException('cURL error 28: Operation timed out after 10001 milliseconds (see https://curl.se/libcurl/c/libcurl-errors.html) for '.$request->url.'?'.http_build_query($request->query));
            }
        });

        $run = $this->runJob($this->makeRun());

        $result = TestResult::where('test_run_id', $run->id)->firstOrFail();
        $this->assertSame(TestVerdict::Error, $result->verdict);
        $this->assertStringContainsString('api_key=***', (string) $result->error_message);
        $this->assertStringContainsString('page=1', (string) $result->error_message);
        $this->assertStringNotContainsString('SECRET-KEY-123', (string) json_encode($result->getAttributes()));

        foreach (['json', 'csv', 'html'] as $format) {
            $content = (string) $this->actingAs($this->user)
                ->get("/api/v1/runs/{$run->id}/export?format={$format}")
                ->assertOk()
                ->getContent();
            $this->assertStringNotContainsString('SECRET-KEY-123', $content, $format);
        }
    }

    /**
     * Regression (S10-01 r2): a multi-step case whose step 1 assertion already failed was stored as
     * ERROR when step 2 then hit a transport error; an assertion failure is FAIL (§4.3).
     */
    public function test_failed_assertion_before_a_transport_error_is_fail_not_error(): void
    {
        TestCase::factory()->for($this->suite, 'suite')->create([
            'status' => TestCaseStatus::Valid,
            'dsl' => [
                'dsl_version' => '1.0',
                'title' => 'Create then list',
                'endpoint' => ['method' => 'POST', 'path' => '/items'],
                'technique' => 'SEQUENCE',
                'source' => 'RULE',
                'steps' => [
                    [
                        'name' => 'Create',
                        'request' => ['method' => 'POST', 'path' => '/items', 'body' => ['name' => 'A']],
                        'assertions' => [['type' => 'status', 'equals' => 201]],
                    ],
                    [
                        'name' => 'List',
                        'request' => ['method' => 'GET', 'path' => '/items'],
                        'assertions' => [['type' => 'status', 'equals' => 200]],
                    ],
                ],
            ],
        ]);
        $this->app->instance(TargetHttpClient::class, new class implements TargetHttpClient
        {
            private int $calls = 0;

            public function send(TargetRequest $request): TargetResponse
            {
                if (++$this->calls === 1) {
                    return new TargetResponse(500, ['Content-Type' => ['application/json']], '{}', 1);
                }

                throw new RuntimeException('Connection timed out');
            }
        });

        $run = $this->runJob($this->makeRun());

        $this->assertEquals(['total' => 1, 'pass' => 0, 'fail' => 1, 'error' => 0, 'skipped' => 0], $run->totals);
        $result = TestResult::where('test_run_id', $run->id)->firstOrFail();
        $this->assertSame(TestVerdict::Fail, $result->verdict);
        $this->assertStringContainsString('Connection timed out', (string) $result->error_message);
    }

    public function test_it_masks_authorization_header_in_request_log(): void
    {
        $this->makeCase(TestCaseStatus::Valid, 'GET', '/me', 200, ['Authorization' => 'Bearer secret-token']);
        $this->fakeClient->respondWith(200, ['id' => 1]);

        $run = $this->runJob($this->makeRun());

        $result = TestResult::where('test_run_id', $run->id)->firstOrFail();
        $this->assertSame('***', $result->request_log['headers']['Authorization']);
        $this->assertStringNotContainsString('secret-token', (string) json_encode($result->request_log));
    }

    public function test_multi_step_case_uses_extracted_variables_and_evaluates_every_step(): void
    {
        TestCase::factory()->for($this->suite, 'suite')->create([
            'status' => TestCaseStatus::Valid,
            'dsl' => [
                'dsl_version' => '1.0',
                'title' => 'Create then read',
                'endpoint' => ['method' => 'POST', 'path' => '/items'],
                'technique' => 'SEQUENCE',
                'source' => 'RULE',
                'steps' => [
                    [
                        'name' => 'Create',
                        'request' => ['method' => 'POST', 'path' => '/items', 'body' => ['name' => 'A']],
                        'extract' => [['var' => 'item_id', 'from' => 'body', 'path' => '$.id']],
                        'assertions' => [['type' => 'status', 'equals' => 201]],
                    ],
                    [
                        'name' => 'Read',
                        'request' => ['method' => 'GET', 'path' => '/items/{{item_id}}'],
                        'assertions' => [
                            ['type' => 'status', 'equals' => 200],
                            ['type' => 'json_path', 'path' => '$.id', 'op' => 'eq', 'value' => '{{item_id}}'],
                        ],
                    ],
                ],
            ],
        ]);
        $this->fakeClient->respondWith(201, ['id' => 42]);
        $this->fakeClient->respondWith(200, ['id' => 42]);

        $run = $this->runJob($this->makeRun());

        $sent = $this->fakeClient->sent();
        $this->assertCount(2, $sent);
        $this->assertStringEndsWith('/items/42', $sent[1]->url);

        $result = TestResult::where('test_run_id', $run->id)->firstOrFail();
        $this->assertSame(TestVerdict::Pass, $result->verdict);
        $this->assertCount(3, $result->assertion_results);
        $this->assertCount(2, $result->request_log['steps']);
        $this->assertCount(2, $result->response_log['steps']);
    }

    public function test_failed_extract_with_failed_assertion_is_fail_not_error(): void
    {
        $this->makeExtractingCase();
        $this->fakeClient->respondWith(400, ['detail' => 'quantity must be positive']);

        $run = $this->runJob($this->makeRun());

        $this->assertEquals(['total' => 1, 'pass' => 0, 'fail' => 1, 'error' => 0, 'skipped' => 0], $run->totals);
        $this->assertCount(1, $this->fakeClient->sent());

        $result = TestResult::where('test_run_id', $run->id)->firstOrFail();
        $this->assertSame(TestVerdict::Fail, $result->verdict);
        $this->assertStringContainsString('Không trích xuất được', (string) $result->error_message);
        $assertion = array_values($result->assertion_results)[0] ?? null;
        $this->assertIsArray($assertion);
        $this->assertSame('status', $assertion['type']);
        $this->assertFalse($assertion['passed']);
        $this->assertSame(201, $assertion['expected']);
        $this->assertSame(400, $assertion['actual']);
    }

    /**
     * Regression (S10-01): a missing extract path on a received response is FAIL, not ERROR.
     * ERROR is reserved for a request that could not be sent or timed out (docs/DESIGN.md §4.3).
     */
    public function test_failed_extract_with_passing_assertions_is_fail(): void
    {
        $this->makeExtractingCase();
        $this->fakeClient->respondWith(201, ['name' => 'A']);

        $run = $this->runJob($this->makeRun());

        $this->assertEquals(['total' => 1, 'pass' => 0, 'fail' => 1, 'error' => 0, 'skipped' => 0], $run->totals);
        $this->assertCount(1, $this->fakeClient->sent());

        $result = TestResult::where('test_run_id', $run->id)->firstOrFail();
        $this->assertSame(TestVerdict::Fail, $result->verdict);
        $this->assertStringContainsString('Không trích xuất được', (string) $result->error_message);
        $this->assertCount(2, $result->assertion_results);
        $assertions = array_values($result->assertion_results);
        $this->assertTrue($assertions[0]['passed']);
        $this->assertSame('extract', $assertions[1]['type']);
        $this->assertFalse($assertions[1]['passed']);
    }

    /**
     * Regression (S13-03): step/extraction errors reached test_results.error_message (M12, M13 and
     * the CSV/HTML export) in English, with the raw DSL step name "Execute POST /orders".
     */
    public function test_stored_extraction_error_message_is_vietnamese_with_display_step_label(): void
    {
        TestCase::factory()->for($this->suite, 'suite')->create([
            'status' => TestCaseStatus::Valid,
            'dsl' => [
                'dsl_version' => '1.0',
                'title' => 'Create order',
                'endpoint' => ['method' => 'POST', 'path' => '/orders'],
                'technique' => 'SEQUENCE',
                'source' => 'RULE',
                'steps' => [
                    [
                        'name' => 'Execute POST /orders',
                        'request' => ['method' => 'POST', 'path' => '/orders', 'body' => ['quantity' => 1]],
                        'extract' => [['var' => 'order_id', 'from' => 'body', 'path' => '$.id']],
                        'assertions' => [['type' => 'status', 'equals' => 201]],
                    ],
                    [
                        'request' => ['method' => 'GET', 'path' => '/orders/{{order_id}}'],
                        'assertions' => [['type' => 'status', 'equals' => 200]],
                    ],
                ],
            ],
        ]);
        $this->fakeClient->respondWith(201, ['name' => 'A']);

        $run = $this->runJob($this->makeRun());

        $result = TestResult::where('test_run_id', $run->id)->firstOrFail();
        $this->assertSame(TestVerdict::Fail, $result->verdict);
        $this->assertSame(
            "Bước 1 ('Gọi POST /orders'): Không trích xuất được: JSONPath '\$.id' không tồn tại trong response.",
            $result->error_message,
        );

        $csv = (string) $this->actingAs($this->user)->get("/api/v1/runs/{$run->id}/export?format=csv")->assertOk()->getContent();
        $this->assertStringContainsString("Bước 1 ('Gọi POST /orders'): Không trích xuất được", $csv);
        $this->assertStringNotContainsString('Extraction failed', $csv);
        $this->assertStringNotContainsString('Execute POST', $csv);
    }

    public function test_step_labels_cover_rule_names_and_unnamed_steps(): void
    {
        $this->assertSame('Gọi POST /orders', StepLabel::display('Execute POST /orders'));
        $this->assertSame('Bước 2', StepLabel::display('Step 2'));
        $this->assertSame('Step 2: Get category', StepLabel::display('Step 2: Get category'));
    }

    /**
     * Regression (S10-01): the `array` cast turns a stored `{}` into `[]`, which used to be sent
     * as the JSON array "[]" (e.g. MISSING_REQUIRED with the only required field removed).
     */
    public function test_empty_object_body_is_sent_as_json_object(): void
    {
        $this->makeDslCase([
            'name' => 'Missing required',
            'request' => ['method' => 'POST', 'path' => '/checkout', 'query' => (object) [], 'headers' => (object) [], 'body' => (object) []],
            'assertions' => [['type' => 'status', 'equals' => 422]],
        ]);
        $this->fakeClient->respondWith(422, ['detail' => 'cart_id is required']);

        $run = $this->runJob($this->makeRun());

        $sent = $this->fakeClient->sent();
        $this->assertCount(1, $sent);
        $this->assertTrue($sent[0]->hasBody);
        $this->assertSame('{}', json_encode($sent[0]->body));
        $this->assertSame(TestVerdict::Pass, TestResult::where('test_run_id', $run->id)->firstOrFail()->verdict);
    }

    /**
     * Regression (S10-01): `{}` inside assertions (json_path value, inline schema `items`) read back
     * from the DB must not produce a false FAIL.
     */
    public function test_empty_object_assertion_values_and_schemas_do_not_fail(): void
    {
        $this->makeDslCase([
            'name' => 'Read',
            'request' => ['method' => 'GET', 'path' => '/meta'],
            'assertions' => [
                ['type' => 'status', 'equals' => 200],
                ['type' => 'json_path', 'path' => '$.meta', 'op' => 'eq', 'value' => (object) []],
                ['type' => 'json_schema', 'schema' => [
                    'type' => 'object',
                    'properties' => ['tags' => ['type' => 'array', 'items' => (object) []]],
                ]],
            ],
        ]);
        $this->fakeClient->respondWith(200, rawBody: '{"meta":{},"tags":[1,"x"]}');

        $run = $this->runJob($this->makeRun());

        $result = TestResult::where('test_run_id', $run->id)->firstOrFail();
        $this->assertSame(TestVerdict::Pass, $result->verdict, (string) json_encode($result->assertion_results));
    }

    /**
     * Regression (S10-01): credential headers other than Authorization, credential query
     * parameters and header assertions on Set-Cookie must be masked in test_results.
     */
    public function test_credential_headers_query_and_header_assertions_are_masked(): void
    {
        $this->makeDslCase([
            'name' => 'Call',
            'request' => [
                'method' => 'GET',
                'path' => '/me',
                'headers' => ['Cookie' => 'session=cookie-secret', 'X-API-Key' => 'key-secret', 'Accept' => 'application/json'],
                'query' => ['api_key' => 'query-secret', 'page' => 1],
            ],
            'assertions' => [
                ['type' => 'status', 'equals' => 200],
                ['type' => 'header', 'name' => 'Set-Cookie', 'op' => 'contains', 'value' => 'session='],
            ],
        ]);
        $this->fakeClient->respondWith(200, ['id' => 1], ['Content-Type' => 'application/json', 'Set-Cookie' => 'session=response-secret; HttpOnly']);

        $run = $this->runJob($this->makeRun());

        $result = TestResult::where('test_run_id', $run->id)->firstOrFail();
        $this->assertSame(TestVerdict::Pass, $result->verdict);
        $stored = (string) json_encode([$result->request_log, $result->response_log, $result->assertion_results]);
        foreach (['cookie-secret', 'key-secret', 'query-secret', 'response-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
        }
        $this->assertSame('***', $result->request_log['headers']['Cookie']);
        $this->assertSame('***', $result->request_log['query']['api_key']);
        $this->assertSame(1, $result->request_log['query']['page']);
        $this->assertSame('application/json', $result->request_log['headers']['Accept']);
        $this->assertSame(['***'], $result->response_log['headers']['Set-Cookie']);
        $this->assertSame('***', array_values($result->assertion_results)[1]['actual']);
    }

    public function test_it_marks_run_as_failed_when_target_outside_allowlist(): void
    {
        $case = $this->makeCase(TestCaseStatus::Valid, 'GET', '/data', 200);

        $run = $this->runJob($this->makeRun('http://untrusted-attacker.com'));

        $this->assertSame(TestRunStatus::Failed, $run->status);
        $this->assertEquals(['total' => 1, 'pass' => 0, 'fail' => 0, 'error' => 1, 'skipped' => 0], $run->totals);
        $this->assertCount(0, $this->fakeClient->sent());
        $result = TestResult::where('test_run_id', $run->id)->firstOrFail();
        $this->assertSame($case->id, $result->test_case_id);
        $this->assertSame(TestVerdict::Error, $result->verdict);
        $this->assertStringContainsString('TARGET_NOT_ALLOWED', (string) $result->error_message);
    }

    public function test_run_cancelled_before_start_is_not_executed(): void
    {
        $this->makeCase(TestCaseStatus::Valid, 'GET', '/ping', 200);

        $run = $this->runJob($this->makeRun(status: TestRunStatus::Cancelled));

        $this->assertSame(TestRunStatus::Cancelled, $run->status);
        $this->assertNull($run->started_at);
        $this->assertCount(0, $this->fakeClient->sent());
        $this->assertSame(0, TestResult::where('test_run_id', $run->id)->count());
    }

    public function test_cancel_during_execution_skips_remaining_cases_and_keeps_cancelled(): void
    {
        $first = $this->makeCase(TestCaseStatus::Valid, 'GET', '/one', 200);
        $second = $this->makeCase(TestCaseStatus::Valid, 'GET', '/two', 200);
        $third = $this->makeCase(TestCaseStatus::Valid, 'GET', '/three', 200);
        $run = $this->makeRun();

        // The target "cancels" the run (as a TESTER would via the API) while the first case runs.
        $this->app->instance(TargetHttpClient::class, new class($run->id) implements TargetHttpClient
        {
            public function __construct(private int $runId) {}

            public function send(TargetRequest $request): TargetResponse
            {
                $run = TestRun::findOrFail($this->runId);
                if ($run->status === TestRunStatus::Running) {
                    app(TransitionTestRunStatusAction::class)->execute($run, TestRunStatus::Cancelled);
                }

                return new TargetResponse(200, ['Content-Type' => ['application/json']], '{}', 1);
            }
        });

        $run = $this->runJob($run);

        $this->assertSame(TestRunStatus::Cancelled, $run->status);
        $this->assertEquals(['total' => 3, 'pass' => 1, 'fail' => 0, 'error' => 0, 'skipped' => 2], $run->totals);

        $verdicts = TestResult::where('test_run_id', $run->id)->orderBy('id')->pluck('verdict', 'test_case_id');
        $this->assertSame(TestVerdict::Pass, $verdicts[$first->id]);
        $this->assertSame(TestVerdict::Skipped, $verdicts[$second->id]);
        $this->assertSame(TestVerdict::Skipped, $verdicts[$third->id]);
    }

    public function test_it_runs_only_the_selected_test_cases(): void
    {
        $this->makeCase(TestCaseStatus::Valid, 'GET', '/one', 200);
        $selected = $this->makeCase(TestCaseStatus::Valid, 'GET', '/two', 200);
        $this->fakeClient->respondWith(200, []);

        $run = $this->makeRun();
        $this->app->call([new ExecuteTestRunJob($run->id, [$selected->id]), 'handle']);
        $run->refresh();

        $this->assertSame(1, $run->totals['total'] ?? null);
        $this->assertSame([$selected->id], TestResult::where('test_run_id', $run->id)->pluck('test_case_id')->all());
    }

    public function test_empty_suite_completes_with_zero_totals(): void
    {
        $run = $this->runJob($this->makeRun());

        $this->assertSame(TestRunStatus::Completed, $run->status);
        $this->assertEquals(['total' => 0, 'pass' => 0, 'fail' => 0, 'error' => 0, 'skipped' => 0], $run->totals);
    }

    public function test_failed_job_moves_run_to_failed_through_the_state_machine(): void
    {
        $queued = $this->makeRun();
        (new ExecuteTestRunJob($queued->id))->failed(new RuntimeException('worker crashed'));
        $queued->refresh();
        $this->assertSame(TestRunStatus::Failed, $queued->status);
        $this->assertNotNull($queued->finished_at);

        $cancelled = $this->makeRun(status: TestRunStatus::Cancelled);
        (new ExecuteTestRunJob($cancelled->id))->failed(new RuntimeException('worker crashed'));
        $this->assertSame(TestRunStatus::Cancelled, $cancelled->fresh()?->status);
    }

    /**
     * Regression (S10-01 r2): a run killed by the job timeout ended FAILED with partial results and
     * null totals; the unexecuted cases are now stored as SKIPPED and totals are filled.
     */
    public function test_run_killed_mid_execution_records_unexecuted_cases_and_totals(): void
    {
        $executed = $this->makeCase(TestCaseStatus::Valid, 'GET', '/items', 200);
        $pending = $this->makeCase(TestCaseStatus::Approved, 'GET', '/items/1', 200);
        $notSelected = $this->makeCase(TestCaseStatus::Valid, 'GET', '/items/2', 200);
        $run = $this->makeRun(status: TestRunStatus::Running);
        TestResult::create([
            'test_run_id' => $run->id,
            'test_case_id' => $executed->id,
            'verdict' => TestVerdict::Pass,
            'duration_ms' => 5,
            'request_log' => ['steps' => []],
            'response_log' => ['steps' => []],
            'assertion_results' => [],
            'error_message' => null,
        ]);

        (new ExecuteTestRunJob($run->id, [$executed->id, $pending->id]))
            ->failed(new TimeoutExceededException('timed out'));

        $run->refresh();
        $this->assertSame(TestRunStatus::Failed, $run->status);
        $this->assertEquals(['total' => 2, 'pass' => 1, 'fail' => 0, 'error' => 0, 'skipped' => 1], $run->totals);
        $skipped = TestResult::query()->where('test_run_id', $run->id)->where('test_case_id', $pending->id)->firstOrFail();
        $this->assertSame(TestVerdict::Skipped, $skipped->verdict);
        $this->assertNotNull($skipped->error_message);
        $this->assertFalse(TestResult::query()->where('test_run_id', $run->id)->where('test_case_id', $notSelected->id)->exists());
    }

    public function test_job_timeout_allows_large_runs_against_slow_targets(): void
    {
        // 600 s cut off ~60 cases at the 10 s default per-request timeout.
        $this->assertSame(1500, (new ExecuteTestRunJob(1))->timeout);
    }

    public function test_job_is_not_retried(): void
    {
        $this->assertSame(1, (new ExecuteTestRunJob(1))->tries);
    }

    public function test_transition_uses_compare_and_set_against_stale_instances(): void
    {
        $run = $this->makeRun();
        $stale = TestRun::findOrFail($run->id);

        app(TransitionTestRunStatusAction::class)->execute($run, TestRunStatus::Cancelled);

        try {
            app(TransitionTestRunStatusAction::class)->execute($stale, TestRunStatus::Running);
            $this->fail('A stale QUEUED instance must not move a CANCELLED run to RUNNING.');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::InvalidStateTransition, $e->errorCode);
        }

        $this->assertSame(TestRunStatus::Cancelled, $run->fresh()?->status);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function makeCase(TestCaseStatus $status, string $method, string $path, int $expectedStatus, array $headers = []): TestCase
    {
        $request = ['method' => $method, 'path' => $path];
        if ($headers !== []) {
            $request['headers'] = $headers;
        }

        return TestCase::factory()->for($this->suite, 'suite')->create([
            'status' => $status,
            'dsl' => [
                'dsl_version' => '1.0',
                'title' => "{$method} {$path}",
                'endpoint' => ['method' => $method, 'path' => $path],
                'technique' => 'HAPPY_PATH',
                'source' => 'RULE',
                'steps' => [
                    [
                        'name' => 'Call',
                        'request' => $request,
                        'assertions' => [['type' => 'status', 'equals' => $expectedStatus]],
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function makeDslCase(array $step): TestCase
    {
        return TestCase::factory()->for($this->suite, 'suite')->create([
            'status' => TestCaseStatus::Valid,
            'dsl' => [
                'dsl_version' => '1.0',
                'title' => 'Case',
                'endpoint' => ['method' => $step['request']['method'], 'path' => $step['request']['path']],
                'technique' => 'HAPPY_PATH',
                'source' => 'RULE',
                'steps' => [$step],
            ],
        ]);
    }

    private function makeExtractingCase(): TestCase
    {
        return TestCase::factory()->for($this->suite, 'suite')->create([
            'status' => TestCaseStatus::Valid,
            'dsl' => [
                'dsl_version' => '1.0',
                'title' => 'Create then read order',
                'endpoint' => ['method' => 'POST', 'path' => '/orders'],
                'technique' => 'SEQUENCE',
                'source' => 'RULE',
                'steps' => [
                    [
                        'name' => 'Create',
                        'request' => ['method' => 'POST', 'path' => '/orders', 'body' => ['quantity' => 1]],
                        'extract' => [['var' => 'order_id', 'from' => 'body', 'path' => '$.id']],
                        'assertions' => [['type' => 'status', 'equals' => 201]],
                    ],
                    [
                        'name' => 'Read',
                        'request' => ['method' => 'GET', 'path' => '/orders/{{order_id}}'],
                        'assertions' => [['type' => 'status', 'equals' => 200]],
                    ],
                ],
            ],
        ]);
    }

    private function makeRun(string $target = 'http://localhost:8000', TestRunStatus $status = TestRunStatus::Queued): TestRun
    {
        return TestRun::factory()->for($this->suite)->for($this->user, 'triggerer')->create([
            'target_base_url' => $target,
            'status' => $status,
            'started_at' => null,
            'finished_at' => null,
            'totals' => null,
        ]);
    }

    private function runJob(TestRun $run): TestRun
    {
        $this->app->call([new ExecuteTestRunJob($run->id), 'handle']);

        return $run->refresh();
    }
}
