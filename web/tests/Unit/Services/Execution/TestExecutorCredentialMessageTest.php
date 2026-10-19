<?php

namespace Tests\Unit\Services\Execution;

use App\Services\Execution\TestExecutor;
use App\Services\Target\FakeTargetHostResolver;
use App\Services\Target\FakeTargetHttpClient;
use App\Services\Target\TargetAllowlistService;
use Tests\TestCase;

/**
 * QA S14-06: the error of a step whose `{{auth_token_<role>}}` has no value is shown to TESTERs in M12:
 * no internal ADR or screen code, and no advice to run an experiment where none can be created
 * (EXPERIMENTS_ENABLED=false on the public deployment).
 */
class TestExecutorCredentialMessageTest extends TestCase
{
    private function message(): string
    {
        $client = new FakeTargetHttpClient;
        $executor = new TestExecutor($client, new TargetAllowlistService(
            ['localhost'],
            resolver: new FakeTargetHostResolver(['localhost' => ['127.0.0.1']]),
        ));

        $result = $executor->execute([
            'dsl_version' => '1.0',
            'title' => 'Get cart',
            'endpoint' => ['method' => 'GET', 'path' => '/cart'],
            'technique' => 'HAPPY_PATH',
            'source' => 'LLM',
            'steps' => [[
                'name' => 'get cart',
                'request' => ['method' => 'GET', 'path' => '/cart', 'headers' => ['Authorization' => 'Bearer {{auth_token_customer}}']],
                'assertions' => [['type' => 'status', 'equals' => 200]],
            ]],
        ], 'http://localhost:8000');

        $this->assertSame([], $client->sent(), 'the placeholder is never sent');

        return (string) $result->stepResults[0]->errorMessage;
    }

    public function test_message_has_no_internal_codes(): void
    {
        config(['ait.experiments.enabled' => true]);

        $message = $this->message();

        $this->assertStringContainsString('Biến {{auth_token_customer}} chưa có giá trị', $message);
        $this->assertStringContainsString('benchmark của nền tảng', $message);
        $this->assertStringContainsString('thực nghiệm', $message);
        $this->assertDoesNotMatchRegularExpression('/\bM\d{2}\b|ADR-/', $message);
    }

    public function test_experiments_are_not_suggested_when_this_deployment_cannot_create_one(): void
    {
        config(['ait.experiments.enabled' => false]);

        $message = $this->message();

        $this->assertStringContainsString('Biến {{auth_token_customer}} chưa có giá trị', $message);
        $this->assertStringNotContainsString('thực nghiệm', $message);
        $this->assertStringContainsString('sửa header Authorization', $message);
        $this->assertDoesNotMatchRegularExpression('/\bM\d{2}\b|ADR-/', $message);
    }
}
