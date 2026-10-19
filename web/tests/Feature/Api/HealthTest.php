<?php

namespace Tests\Feature\Api;

use App\Contracts\AiGeneratorClient;
use App\Services\Ai\FakeAiGeneratorClient;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_is_ok_when_database_and_ai_service_are_up(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'status' => 'ok',
                    'checks' => [
                        'database' => true,
                        'ai_service' => ['reachable' => true, 'provider' => 'fake', 'version' => 'fake'],
                    ],
                ],
            ]);
    }

    public function test_health_is_degraded_when_ai_service_is_down(): void
    {
        $fake = $this->app->make(AiGeneratorClient::class);
        $this->assertInstanceOf(FakeAiGeneratorClient::class, $fake);
        $fake->setReachable(false);

        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'degraded')
            ->assertJsonPath('data.checks.ai_service.reachable', false);
    }
}
