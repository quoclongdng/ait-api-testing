<?php

namespace Tests\Unit\Services;

use App\Contracts\AiGeneratorClient;
use App\Data\Ai\AiGenerateRequest;
use App\Data\Ai\AiGenerateResponse;
use App\Data\Ai\AiGenerationStats;
use App\Enums\ErrorCode;
use App\Enums\GenerationStrategy;
use App\Exceptions\ApiException;
use App\Services\Ai\FakeAiGeneratorClient;
use App\Services\Dsl\DslValidator;
use Tests\TestCase;

class FakeAiGeneratorClientTest extends TestCase
{
    private function sampleRequest(): AiGenerateRequest
    {
        return new AiGenerateRequest(
            jobId: 1,
            strategy: GenerationStrategy::Llm,
            endpoints: [
                ['method' => 'POST', 'path' => '/orders'],
            ],
            options: ['temperature' => 0.5, 'seed' => 123],
        );
    }

    public function test_default_generate_returns_valid_dsl_and_llm_call(): void
    {
        $fake = new FakeAiGeneratorClient(reachable: true);
        $request = $this->sampleRequest();

        $response = $fake->generate($request);

        $this->assertCount(1, $response->testCases);
        $this->assertSame('VALID', $response->testCases[0]->status);
        $this->assertSame('POST', $response->testCases[0]->dsl['endpoint']['method']);
        $this->assertSame('/orders', $response->testCases[0]->dsl['endpoint']['path']);

        $this->assertCount(1, $response->llmCalls);
        $this->assertSame('fake', $response->llmCalls[0]->provider);
        $this->assertSame(0.5, $response->llmCalls[0]->temperature);
        $this->assertSame(123, $response->llmCalls[0]->seed);

        $this->assertSame(1, $response->stats->generated);
        $this->assertSame(1, $response->stats->valid);

        $this->assertSame($request, $fake->lastRequest());
        $this->assertCount(1, $fake->recordedRequests());
    }

    public function test_generate_throws_when_reachable_is_false(): void
    {
        $fake = new FakeAiGeneratorClient(reachable: false);

        $this->expectException(ApiException::class);

        try {
            $fake->generate($this->sampleRequest());
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::AiServiceUnavailable, $e->errorCode);
            throw $e;
        }
    }

    /** Regression S13-03: the fake driver showed English text on M11/M15 (docs/DESIGN.md §8). */
    public function test_unreachable_message_is_vietnamese_and_shared_with_http_client(): void
    {
        $fake = new FakeAiGeneratorClient(reachable: false);

        try {
            $fake->generate($this->sampleRequest());
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(AiGeneratorClient::UNAVAILABLE_MESSAGE.'.', $e->getMessage());
            $this->assertStringContainsString('không khả dụng', $e->getMessage());
            $this->assertStringNotContainsString('currently unavailable', $e->getMessage());
        }
    }

    public function test_can_set_canned_response(): void
    {
        $fake = new FakeAiGeneratorClient(reachable: true);

        $customResponse = new AiGenerateResponse(
            testCases: [],
            llmCalls: [],
            stats: new AiGenerationStats(generated: 0, valid: 0, invalid: 0, repaired: 0, duplicates: 0),
        );
        $fake->setGenerateResponse($customResponse);

        $response = $fake->generate($this->sampleRequest());
        $this->assertSame($customResponse, $response);
    }

    public function test_can_set_canned_exception(): void
    {
        $fake = new FakeAiGeneratorClient(reachable: true);
        $fake->setGenerateException(new ApiException(ErrorCode::LlmProviderError, 'Mock provider failure'));

        $this->expectException(ApiException::class);

        try {
            $fake->generate($this->sampleRequest());
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::LlmProviderError, $e->errorCode);
            throw $e;
        }
    }

    public function test_fake_dsl_strictly_conforms_to_dsl_schema_and_passes_validator(): void
    {
        $fake = new FakeAiGeneratorClient(reachable: true);
        $response = $fake->generate($this->sampleRequest());

        $validator = new DslValidator;
        $result = $validator->validate($response->testCases[0]->dsl);

        $this->assertTrue($result->isValid, 'Fake DSL must be valid: '.implode('; ', $result->errors));
    }
}
