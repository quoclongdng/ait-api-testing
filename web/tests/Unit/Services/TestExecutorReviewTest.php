<?php

namespace Tests\Unit\Services;

use App\Data\Execution\StepExecutionResult;
use App\Data\TargetResponse;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Services\Execution\TargetAllowlistValidator;
use App\Services\Execution\TestExecutor;
use App\Services\Target\FakeTargetHostResolver;
use App\Services\Target\FakeTargetHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TestExecutorReviewTest extends TestCase
{
    private function allowlist(): TargetAllowlistValidator
    {
        return new TargetAllowlistValidator(['localhost'], new FakeTargetHostResolver(['localhost' => ['127.0.0.1']]));
    }

    /** @return list<array{string}> */
    public static function unsafeUrls(): array
    {
        return [
            ['gopher://localhost'], ['ftp://localhost'], ['file://localhost'],
            ['//localhost'], ['http://user:password@localhost'], ['http://localhost:0'],
            ["http://localhost\n"], ['http://localhost\\@evil.test'],
        ];
    }

    #[DataProvider('unsafeUrls')]
    public function test_unsafe_target_is_rejected_before_fake_client_is_called(string $url): void
    {
        $client = new FakeTargetHttpClient;
        $executor = new TestExecutor($client, $this->allowlist());
        try {
            $executor->execute(['steps' => [['request' => ['method' => 'GET', 'path' => '/data']]]], $url);
            $this->fail('Unsafe target was accepted');
        } catch (ApiException $exception) {
            $this->assertSame(ErrorCode::TargetNotAllowed, $exception->errorCode);
        }
        $this->assertSame([], $client->sent());
    }

    public function test_unresolvable_allowlisted_host_is_rejected_before_fake_client_is_called(): void
    {
        $client = new FakeTargetHttpClient;
        $executor = new TestExecutor($client, new TargetAllowlistValidator(['missing.test'], new FakeTargetHostResolver));
        try {
            $executor->execute(['steps' => [['request' => ['method' => 'GET', 'path' => '/']]]], 'http://missing.test');
            $this->fail('Unresolvable target was accepted');
        } catch (ApiException $exception) {
            $this->assertSame(ErrorCode::TargetNotAllowed, $exception->errorCode);
        }
        $this->assertSame([], $client->sent());
    }

    /** @return list<array{string, string}> */
    public static function missingExtracts(): array
    {
        return [['header', 'X-Order-ID'], ['body', '$.missing'], ['body', '$.orders[4].id'], ['body', '$.id.value']];
    }

    #[DataProvider('missingExtracts')]
    public function test_missing_extract_stops_execution_and_does_not_create_null_variable(string $from, string $path): void
    {
        $client = new FakeTargetHttpClient;
        $client->respondWith(200, ['id' => null, 'orders' => [['id' => 42]]]);
        $result = (new TestExecutor($client, $this->allowlist()))->execute(['steps' => [
            ['name' => 'Create order', 'request' => ['method' => 'GET', 'path' => '/orders'],
                'extract' => [['var' => 'order_id', 'from' => $from, 'path' => $path]]],
            ['request' => ['method' => 'POST', 'path' => '/orders/{{order_id}}/pay']],
        ]], 'http://localhost');
        $this->assertFalse($result->isSuccess);
        $this->assertStringContainsString('Không trích xuất được', (string) $result->errorMessage);
        $this->assertStringContainsString('Create order', (string) $result->errorMessage);
        $this->assertArrayNotHasKey('order_id', $result->finalVariables);
        $this->assertCount(1, $client->sent());
        $this->assertCount(1, $result->stepResults);
    }

    public function test_explicit_null_is_a_successful_extract_and_partial_extracts_are_not_committed(): void
    {
        $client = new FakeTargetHttpClient;
        $client->respondWith(200, ['id' => null]);
        $result = (new TestExecutor($client, $this->allowlist()))->execute(['steps' => [
            ['request' => ['method' => 'GET', 'path' => '/orders'], 'extract' => [['var' => 'id', 'from' => 'body', 'path' => '$.id']]],
        ]], 'http://localhost');
        $this->assertTrue($result->isSuccess);
        $this->assertArrayHasKey('id', $result->finalVariables);
        $this->assertNull($result->finalVariables['id']);

        $client->respondWith(200, ['id' => 42]);
        $result = (new TestExecutor($client, $this->allowlist()))->execute(['variables' => ['id' => 1], 'steps' => [
            ['request' => ['method' => 'GET', 'path' => '/orders'], 'extract' => [
                ['var' => 'id', 'from' => 'body', 'path' => '$.id'],
                ['var' => 'missing', 'from' => 'body', 'path' => '$.missing'],
            ]],
        ]], 'http://localhost');
        $this->assertFalse($result->isSuccess);
        $this->assertSame(1, $result->finalVariables['id']);
        $this->assertSame([], $result->stepResults[0]->extractedVariables);
    }

    public function test_invalid_response_json_fails_extraction(): void
    {
        try {
            (new TestExecutor)->extractValue(new TargetResponse(200, [], '{broken', 1), 'body', '$.id');
            $this->fail('Invalid JSON body was accepted');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Không trích xuất được: response body không phải JSON hợp lệ.', $exception->getMessage());
            $this->assertInstanceOf(\JsonException::class, $exception->getPrevious());
        }
    }

    public function test_nonpositive_timeout_is_rejected_before_fake_client_is_called(): void
    {
        foreach ([0, -1] as $timeout) {
            $client = new FakeTargetHttpClient;
            try {
                (new TestExecutor($client, $this->allowlist()))->execute(['steps' => [
                    ['request' => ['method' => 'GET', 'path' => '/', 'timeout_ms' => $timeout]],
                ]], 'http://localhost');
                $this->fail('Invalid timeout was accepted');
            } catch (ApiException $exception) {
                $this->assertSame(ErrorCode::DslInvalid, $exception->errorCode);
            }
            $this->assertSame([], $client->sent());
        }
    }

    public function test_binary_response_logs_are_json_safe_and_bounded_including_encoding_label(): void
    {
        foreach (["\xff\xfe\0", str_repeat("\xff\0", 40000), "valid\0text"] as $raw) {
            $logged = StepExecutionResult::truncateBody($raw);
            $this->assertStringStartsWith('[base64] ', $logged);
            $this->assertLessThanOrEqual(65536, strlen($logged));
            $this->assertNotSame('', json_encode(['body' => $logged], JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString("\0", $logged);
            if (strlen($raw) < 10) {
                $this->assertSame($raw, base64_decode(substr($logged, 9), true));
            }
        }
    }
}
