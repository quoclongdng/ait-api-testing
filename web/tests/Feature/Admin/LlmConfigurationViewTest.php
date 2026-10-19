<?php

namespace Tests\Feature\Admin;

use App\Actions\System\DescribeLlmConfigurationAction;
use App\Contracts\AiGeneratorClient;
use App\Data\Ai\AiGenerateRequest;
use App\Data\Ai\AiGenerateResponse;
use App\Data\AiServiceStatus;
use App\Models\User;
use App\Services\Ai\HttpAiGeneratorClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class LlmConfigurationViewTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function llmConfig(array $overrides = []): array
    {
        return array_merge([
            'driver' => 'http',
            'url' => 'http://ai-service:8001',
            'timeout' => 1380,
            'reachable' => true,
            'provider' => 'openai_compatible',
            'version' => '1.2.3',
            'model' => 'qwen2.5-coder:7b',
            'temperature' => 0.2,
            'seed' => 42,
            'generate_budget_seconds' => 1320.0,
            'required_timeout' => 1380,
            'timeout_sufficient' => true,
        ], $overrides);
    }

    public function test_m04_shows_provider_and_model_reported_by_ai_service_not_web_guesses(): void
    {
        $this->view('admin.allowed-targets.index', [
            'errors' => new ViewErrorBag,
            'allowedTargets' => collect(),
            'environmentTargets' => ['benchmark'],
            'llmConfig' => $this->llmConfig(),
        ])
            ->assertSee('LLM hệ thống (chỉ đọc)')
            ->assertSee('Nhà cung cấp LLM (provider)')
            ->assertSee('openai_compatible')
            ->assertSee('1.2.3')
            ->assertSee('qwen2.5-coder:7b')
            ->assertSee('0.2 / 42')
            ->assertDontSee('Mặc định của ai-service')
            ->assertDontSee('Web không đọc được các giá trị đó')
            ->assertDontSee('Model tham khảo (Web)')
            ->assertDontSee('hiện chưa được áp dụng vào request sinh test');
    }

    public function test_m04_marks_ai_service_offline_when_unreachable(): void
    {
        $this->view('admin.allowed-targets.index', [
            'errors' => new ViewErrorBag,
            'allowedTargets' => collect(),
            'environmentTargets' => [],
            'llmConfig' => $this->llmConfig([
                'reachable' => false,
                'provider' => null,
                'version' => null,
                'model' => null,
                'temperature' => null,
                'seed' => null,
            ]),
        ])
            ->assertSee('ai-service ngoại tuyến')
            ->assertSee('Không xác định');
    }

    public function test_http_client_reads_model_temperature_and_seed_from_ai_service_health(): void
    {
        Http::fake([
            'ai-service:8001/health' => Http::response([
                'status' => 'ok',
                'provider' => 'openai_compatible',
                'version' => '0.1.0',
                'model' => 'qwen2.5-coder:7b',
                'temperature' => 0.2,
                'seed' => 42,
            ]),
        ]);

        $status = (new HttpAiGeneratorClient('http://ai-service:8001', 120))->status();

        $this->assertTrue($status->reachable);
        $this->assertSame('qwen2.5-coder:7b', $status->model);
        $this->assertSame(0.2, $status->temperature);
        $this->assertSame(42, $status->seed);
        // The public web /api/v1/health shape is unchanged (web-api contract).
        $this->assertSame(['reachable', 'provider', 'version'], array_keys($status->toArray()));
    }

    public function test_m04_page_renders_the_model_reported_by_ai_service(): void
    {
        $this->app->instance(AiGeneratorClient::class, new class implements AiGeneratorClient
        {
            public function status(): AiServiceStatus
            {
                return new AiServiceStatus(reachable: true, provider: 'openai_compatible', version: '0.1.0', model: 'llama3.1:8b', temperature: 0.4, seed: 7);
            }

            public function generate(AiGenerateRequest $request): AiGenerateResponse
            {
                throw new \LogicException('not used');
            }
        });

        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/allowed-targets')
            ->assertOk()
            ->assertSee('llama3.1:8b')
            ->assertSee('0.4 / 7');
    }

    public function test_m04_warns_when_ai_service_timeout_does_not_cover_the_generate_budget(): void
    {
        $this->view('admin.allowed-targets.index', [
            'errors' => new ViewErrorBag,
            'allowedTargets' => collect(),
            'environmentTargets' => [],
            'llmConfig' => $this->llmConfig(['timeout' => 120, 'timeout_sufficient' => false]),
        ])
            ->assertSee('llm-timeout-warning', false)
            ->assertSee('Cấu hình thời gian không khớp')
            ->assertSee('AI_SERVICE_TIMEOUT=1380')
            ->assertSee('1320s');
    }

    public function test_m04_shows_no_timeout_warning_when_the_chain_holds_or_budget_is_unknown(): void
    {
        foreach ([true, null] as $sufficient) {
            $this->view('admin.allowed-targets.index', [
                'errors' => new ViewErrorBag,
                'allowedTargets' => collect(),
                'environmentTargets' => [],
                'llmConfig' => $this->llmConfig(['timeout_sufficient' => $sufficient]),
            ])->assertDontSee('llm-timeout-warning', false);
        }
    }

    public function test_describe_llm_configuration_flags_an_old_web_timeout(): void
    {
        config(['ait.ai_service.timeout_seconds' => 120]);
        $this->app->instance(AiGeneratorClient::class, new class implements AiGeneratorClient
        {
            public function status(): AiServiceStatus
            {
                return new AiServiceStatus(reachable: true, provider: 'openai_compatible', version: '0.1.0', generateBudgetSeconds: 1320.0);
            }

            public function generate(AiGenerateRequest $request): AiGenerateResponse
            {
                throw new \LogicException('not used');
            }
        });

        $config = app(DescribeLlmConfigurationAction::class)->execute();
        $this->assertSame(1320.0, $config['generate_budget_seconds']);
        $this->assertSame(1380, $config['required_timeout']);
        $this->assertFalse($config['timeout_sufficient']);

        config(['ait.ai_service.timeout_seconds' => 1380]);
        $this->assertTrue(app(DescribeLlmConfigurationAction::class)->execute()['timeout_sufficient']);
    }

    public function test_http_client_reads_generate_budget_from_ai_service_health(): void
    {
        Http::fake([
            'ai-service:8001/health' => Http::response([
                'status' => 'ok', 'provider' => 'openai_compatible', 'version' => '0.1.0',
                'model' => 'qwen2.5-coder:7b', 'temperature' => 0.2, 'seed' => 42,
                'generate_budget_seconds' => 1320,
            ]),
        ]);

        $status = (new HttpAiGeneratorClient('http://ai-service:8001', 120))->status();

        $this->assertSame(1320.0, $status->generateBudgetSeconds);
        $this->assertSame(1380, $status->requiredWebTimeoutSeconds());
        $this->assertNull((new AiServiceStatus(reachable: true))->requiredWebTimeoutSeconds());
    }
}
