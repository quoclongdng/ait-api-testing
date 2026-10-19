<?php

namespace Tests\Unit\Support;

use App\Data\Execution\StepExecutionResult;
use App\Data\TargetRequest;
use App\Data\TargetResponse;
use App\Support\SensitiveDataMasker;
use PHPUnit\Framework\TestCase;

/**
 * Regression (S10-01): only Authorization used to be masked in test_results logs.
 */
class SensitiveDataMaskerTest extends TestCase
{
    public function test_credential_headers_are_masked_case_insensitively(): void
    {
        $masked = SensitiveDataMasker::maskHeaders([
            'authorization' => 'Bearer a',
            'Proxy-Authorization' => 'Basic b',
            'Cookie' => 'session=c',
            'Set-Cookie' => ['s=d', 's2=e'],
            'X-API-Key' => 'f',
            'Api-Key' => 'g',
            'X-Auth-Token' => 'h',
            'X-Client-Secret' => 'i',
            'Accept' => 'application/json',
            'Content-Type' => ['application/json'],
        ]);

        $this->assertSame([
            'authorization' => '***',
            'Proxy-Authorization' => '***',
            'Cookie' => '***',
            'Set-Cookie' => ['***'],
            'X-API-Key' => '***',
            'Api-Key' => '***',
            'X-Auth-Token' => '***',
            'X-Client-Secret' => '***',
            'Accept' => 'application/json',
            'Content-Type' => ['application/json'],
        ], $masked);
    }

    public function test_credential_query_parameters_and_userinfo_are_masked_in_urls(): void
    {
        $this->assertSame(
            'http://***@benchmark:8000/items?api_key=***&page=2&access_token=***#top',
            SensitiveDataMasker::maskUrl('http://user:pw@benchmark:8000/items?api_key=s1&page=2&access_token=s2#top'),
        );
        $this->assertSame('http://benchmark/items', SensitiveDataMasker::maskUrl('http://benchmark/items'));
        $this->assertSame(['apiKey' => '***', 'limit' => 5], SensitiveDataMasker::maskQuery(['apiKey' => 'x', 'limit' => 5]));
    }

    /**
     * Regression (S10-01 r2): transport error messages embed the full request URI.
     */
    public function test_urls_embedded_in_free_text_are_masked(): void
    {
        $this->assertSame(
            'cURL error 28: timed out (see https://curl.se/libcurl/c/libcurl-errors.html) for http://benchmark:8000/items?api_key=***&page=1',
            SensitiveDataMasker::maskText('cURL error 28: timed out (see https://curl.se/libcurl/c/libcurl-errors.html) for http://benchmark:8000/items?api_key=SECRET&page=1'),
        );
        $this->assertSame("Bước 1 ('a'): 'https://h/x?token=***' failed", SensitiveDataMasker::maskText("Bước 1 ('a'): 'https://h/x?token=t0k' failed"));
        $this->assertSame('no url here', SensitiveDataMasker::maskText('no url here'));
    }

    public function test_step_logs_mask_every_credential(): void
    {
        $step = new StepExecutionResult(
            stepIndex: 1,
            stepName: 'Login',
            request: new TargetRequest('GET', 'http://benchmark/me?token=t-secret', ['X-API-Key' => 'k-secret'], ['password' => 'p-secret']),
            response: new TargetResponse(200, ['Set-Cookie' => ['session=c-secret']], '{}', 3),
        );

        $logs = (string) json_encode([$step->requestLog(), $step->responseLog(), $step->toArray()]);
        foreach (['t-secret', 'k-secret', 'p-secret', 'c-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $logs);
        }
    }
}
