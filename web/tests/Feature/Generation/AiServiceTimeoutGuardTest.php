<?php

namespace Tests\Feature\Generation;

use App\Actions\Generation\ExecuteAiGenerationAction;
use App\Actions\Generation\RecordLlmCallsAction;
use App\Contracts\AiGeneratorClient;
use App\Data\Ai\AiGenerateRequest;
use App\Data\Ai\AiGenerateResponse;
use App\Data\AiServiceStatus;
use App\Enums\ErrorCode;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Exceptions\ApiException;
use App\Models\Endpoint;
use App\Models\GenerationJob;
use App\Models\LlmCallLog;
use App\Services\Ai\FakeAiGeneratorClient;
use App\Services\Ai\HttpAiGeneratorClient;
use App\Services\Support\GenerationJobError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Unit\Services\AiResponseContractTest;

/**
 * S11-02 review: a web/.env from before S11-02 keeps AI_SERVICE_TIMEOUT=120 while ai-service now
 * budgets 1320 s per POST /v1/generate. The web would give up at 120 s while ai-service keeps
 * calling the LLM, and those calls would never reach llm_call_logs (D09). The web must refuse
 * before any LLM call when its timeout does not cover the reported budget + 60 s.
 */
class AiServiceTimeoutGuardTest extends TestCase
{
    use RefreshDatabase;

    private function queuedLlmJob(): GenerationJob
    {
        $job = GenerationJob::factory()->create([
            'status' => GenerationJobStatus::Queued,
            'strategy' => GenerationStrategy::Llm,
        ]);
        Endpoint::factory()->create(['api_spec_id' => $job->api_spec_id]);

        return $job;
    }

    private function clientReporting(?float $budget): BudgetReportingAiClient
    {
        return new BudgetReportingAiClient($budget);
    }

    public function test_old_web_timeout_is_refused_before_any_llm_call(): void
    {
        config(['ait.ai_service.timeout_seconds' => 120]);
        $client = $this->clientReporting(1320.0);
        $job = $this->queuedLlmJob();

        try {
            (new ExecuteAiGenerationAction($client, new RecordLlmCallsAction))->execute($job);
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::AiServiceUnavailable, $e->errorCode);
            $this->assertStringContainsString('AI_SERVICE_TIMEOUT=1380', $e->getMessage());
            $this->assertSame(['ai_service_timeout' => 120, 'required_ai_service_timeout' => 1380], $e->details);
        }

        $this->assertSame(0, $client->generateCalls);
        $job->refresh();
        $this->assertSame(GenerationJobStatus::Failed, $job->status);
        $this->assertSame(ErrorCode::AiServiceUnavailable, GenerationJobError::code($job->error));
        $this->assertNotNull($job->finished_at);
        $this->assertSame(0, LlmCallLog::query()->count());
    }

    public function test_timeout_exactly_budget_plus_margin_is_accepted(): void
    {
        config(['ait.ai_service.timeout_seconds' => 1380]);
        $client = $this->clientReporting(1320.0);
        $job = $this->queuedLlmJob();

        (new ExecuteAiGenerationAction($client, new RecordLlmCallsAction))->execute($job);

        $this->assertSame(1, $client->generateCalls);
        $this->assertSame(GenerationJobStatus::Running, $job->refresh()->status);
    }

    public function test_fractional_budget_rounds_the_required_timeout_up(): void
    {
        config(['ait.ai_service.timeout_seconds' => 160]);
        $client = $this->clientReporting(100.5);

        try {
            (new ExecuteAiGenerationAction($client, new RecordLlmCallsAction))->execute($this->queuedLlmJob());
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(161, $e->details['required_ai_service_timeout']);
        }
        $this->assertSame(0, $client->generateCalls);
    }

    public function test_ai_service_without_a_reported_budget_is_not_checked(): void
    {
        config(['ait.ai_service.timeout_seconds' => 120]);
        $client = $this->clientReporting(null);

        (new ExecuteAiGenerationAction($client, new RecordLlmCallsAction))->execute($this->queuedLlmJob());

        $this->assertSame(1, $client->generateCalls);
    }

    public function test_http_client_never_posts_generate_when_health_budget_exceeds_the_timeout(): void
    {
        config(['ait.ai_service.timeout_seconds' => 120]);
        Http::fake([
            'ai-service/health' => Http::response([
                'status' => 'ok', 'provider' => 'openai_compatible', 'version' => '0.1.0',
                'model' => 'qwen2.5-coder:7b', 'temperature' => 0.2, 'seed' => 42,
                'generate_budget_seconds' => 1320,
            ]),
            'ai-service/v1/generate' => Http::response(AiResponseContractTest::payload()),
        ]);
        $job = $this->queuedLlmJob();

        try {
            (new ExecuteAiGenerationAction(new HttpAiGeneratorClient('http://ai-service', 120), new RecordLlmCallsAction))->execute($job);
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::AiServiceUnavailable, $e->errorCode);
        }

        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1/generate'));
        $this->assertSame(GenerationJobStatus::Failed, $job->refresh()->status);
    }
}

/** Reports a given AI_GENERATE_BUDGET_SECONDS on status() and counts generate() calls. */
final class BudgetReportingAiClient implements AiGeneratorClient
{
    public int $generateCalls = 0;

    public function __construct(private readonly ?float $budget) {}

    public function status(): AiServiceStatus
    {
        return new AiServiceStatus(reachable: true, provider: 'openai_compatible', generateBudgetSeconds: $this->budget);
    }

    public function generate(AiGenerateRequest $request): AiGenerateResponse
    {
        $this->generateCalls++;

        return (new FakeAiGeneratorClient)->generate($request);
    }
}
