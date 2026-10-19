<?php

namespace App\Contracts;

use App\Data\Ai\AiGenerateRequest;
use App\Data\Ai\AiGenerateResponse;
use App\Data\AiServiceStatus;
use App\Exceptions\ApiException;

/**
 * Client for the Python ai-service (LLM test generation).
 *
 * Contract: docs/contracts/ai-service.openapi.yaml
 * Implementations: HttpAiGeneratorClient (real), FakeAiGeneratorClient (tests / parallel work).
 * Selected by config('ait.ai_service.driver').
 */
interface AiGeneratorClient
{
    /**
     * Vietnamese message (docs/DESIGN.md §8) shown on M11/M15 when ai-service cannot be reached.
     * Shared by every implementation so the fake driver shows the same text as the HTTP one.
     */
    public const UNAVAILABLE_MESSAGE = 'AI service không khả dụng hoặc kết nối bị từ chối';

    public function status(): AiServiceStatus;

    /**
     * Generate test cases via ai-service (LLM / HYBRID).
     *
     * @throws ApiException
     */
    public function generate(AiGenerateRequest $request): AiGenerateResponse;
}
