<?php

namespace Tests\Unit\Services;

use App\Contracts\TargetHostResolver;
use App\Data\TargetRequest;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Services\Dsl\DslValidator;
use App\Services\Execution\TargetAllowlistValidator;
use App\Services\Execution\TestExecutor;
use App\Services\Target\FakeTargetHostResolver;
use App\Services\Target\LaravelTargetHttpClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LaravelTargetHttpClientTest extends TestCase
{
    private function allowlist(): TargetAllowlistValidator
    {
        return new TargetAllowlistValidator(['benchmark'], new FakeTargetHostResolver(['benchmark' => ['127.0.0.1']]));
    }

    /** @return list<array{int, float}> */
    public static function timeouts(): array
    {
        return [[500, 0.5], [1500, 1.5], [10000, 10.0]];
    }

    #[DataProvider('timeouts')]
    public function test_transport_keeps_millisecond_timeout_and_pins_dns(int $milliseconds, float $seconds): void
    {
        Http::fake(function (Request $request, array $options) use ($seconds) {
            $this->assertSame($seconds, $options['timeout']);
            $this->assertSame($seconds, $options['connect_timeout']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame('', $options['proxy']);
            $this->assertSame('', $options['curl'][CURLOPT_PROXY]);
            $this->assertSame(['benchmark:8000:127.0.0.1'], $options['curl'][CURLOPT_RESOLVE]);

            return Http::response(['ok' => true]);
        });
        $response = (new LaravelTargetHttpClient($this->allowlist()))->send(new TargetRequest('GET', 'http://benchmark:8000/test', timeoutMs: $milliseconds));
        $this->assertSame(200, $response->status);
        Http::assertSentCount(1);
    }

    public function test_redirect_is_returned_without_following_location(): void
    {
        Http::fake(['http://benchmark/start' => Http::response('', 302, ['Location' => 'http://outside.test/secret'])]);
        $response = (new LaravelTargetHttpClient($this->allowlist()))->send(new TargetRequest('GET', 'http://benchmark/start'));
        $this->assertSame(302, $response->status);
        $this->assertSame(['http://outside.test/secret'], $response->headers['Location']);
        Http::assertSentCount(1);
    }

    public function test_unsafe_schemes_are_rejected_even_when_calling_transport_directly(): void
    {
        Http::fake();
        foreach (['gopher', 'ftp', 'file'] as $scheme) {
            try {
                (new LaravelTargetHttpClient($this->allowlist()))->send(new TargetRequest('GET', $scheme.'://benchmark/data'));
                $this->fail('Transport accepted unsafe scheme');
            } catch (ApiException $exception) {
                $this->assertSame(ErrorCode::TargetNotAllowed, $exception->errorCode);
            }
        }
        Http::assertNothingSent();
    }

    public function test_nonpositive_target_request_timeout_is_rejected(): void
    {
        Http::fake();
        foreach ([0, -500] as $timeout) {
            try {
                (new LaravelTargetHttpClient($this->allowlist()))->send(new TargetRequest('GET', 'http://benchmark/data', timeoutMs: $timeout));
                $this->fail('Transport accepted invalid timeout');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('timeout_ms', $exception->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_resolved_ipv4_and_ipv6_addresses_are_pinned_with_hostname_and_https_port(): void
    {
        $resolver = new class implements TargetHostResolver
        {
            public int $calls = 0;

            public function resolve(string $host): array
            {
                $this->calls++;

                return ['192.0.2.1', '2001:db8::1'];
            }
        };
        Http::fake(function (Request $request, array $options) {
            $this->assertSame('https://benchmark/test', $request->url());
            $this->assertSame(['benchmark:443:192.0.2.1,[2001:db8::1]'], $options['curl'][CURLOPT_RESOLVE]);
            $this->assertTrue($options['curl'][CURLOPT_FRESH_CONNECT]);
            $this->assertTrue($options['curl'][CURLOPT_FORBID_REUSE]);

            return Http::response(['ok' => true]);
        });
        (new LaravelTargetHttpClient(new TargetAllowlistValidator(['benchmark'], $resolver)))->send(new TargetRequest('GET', 'https://benchmark/test'));
        $this->assertSame(1, $resolver->calls, 'Resolve once and pass those addresses to cURL');
    }

    public function test_real_client_missing_extract_stops_before_payment_request(): void
    {
        foreach ([['body', '$.id'], ['header', 'X-Order-ID']] as [$from, $path]) {
            Http::fake(['http://benchmark/orders' => Http::response(['message' => 'No id'])]);
            $allowlist = $this->allowlist();
            $result = (new TestExecutor(new LaravelTargetHttpClient($allowlist), $allowlist))->execute(['steps' => [
                ['request' => ['method' => 'POST', 'path' => '/orders'],
                    'extract' => [['var' => 'order_id', 'from' => $from, 'path' => $path]]],
                ['request' => ['method' => 'POST', 'path' => '/orders/{{order_id}}/pay']],
            ]], 'http://benchmark');
            $this->assertFalse($result->isSuccess);
            $this->assertStringContainsString('Không trích xuất được', (string) $result->errorMessage);
            $this->assertArrayNotHasKey('order_id', $result->finalVariables);
            Http::assertSentCount(1);
        }
    }

    public function test_real_client_preserves_raw_large_response_and_logs_safe_bounded_body(): void
    {
        foreach ([str_repeat('A', 65535).'ệ', str_repeat('ệ', 30000), str_repeat("\xff\0", 40000)] as $index => $raw) {
            $path = '/data'.$index;
            Http::fake(['http://benchmark'.$path => Http::response($raw, 200)]);
            $allowlist = $this->allowlist();
            $result = (new TestExecutor(new LaravelTargetHttpClient($allowlist), $allowlist))->execute(['steps' => [
                ['request' => ['method' => 'GET', 'path' => $path]],
            ]], 'http://benchmark');
            $this->assertTrue($result->isSuccess);
            $step = $result->stepResults[0];
            $this->assertNotNull($step->response);
            $this->assertSame(hash('sha256', $raw), hash('sha256', $step->response->body));
            $log = $step->toArray();
            $this->assertLessThanOrEqual(65536, strlen($log['response']['body']));
            $this->assertTrue(mb_check_encoding($log['response']['body'], 'UTF-8'));
            $decoded = json_decode(json_encode($log, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($log['response']['body'], $decoded['response']['body']);
            Http::assertSentCount(1);
        }
    }

    /**
     * Regression (S10-01): the transport used to read the whole target body into memory with no
     * cap, so an endless body could exhaust the worker. Now curl aborts past the limit and an
     * oversize body becomes a step error (ERROR verdict) instead of a fatal error.
     */
    public function test_transport_caps_the_response_body_size(): void
    {
        Http::fake(function (Request $request, array $options) {
            $this->assertSame(1024, $options['curl'][CURLOPT_MAXFILESIZE]);
            $this->assertFalse($options['curl'][CURLOPT_NOPROGRESS]);
            $progress = $options['curl'][CURLOPT_PROGRESSFUNCTION];
            $this->assertSame(0, $progress(null, 0, 1024, 0, 0));
            $this->assertSame(1, $progress(null, 0, 1025, 0, 0));

            return Http::response(str_repeat('x', $request->url() === 'http://benchmark/small' ? 1024 : 1025));
        });
        $client = new LaravelTargetHttpClient($this->allowlist(), 1024);

        $this->assertSame(1024, strlen($client->send(new TargetRequest('GET', 'http://benchmark/small'))->body));

        try {
            $client->send(new TargetRequest('GET', 'http://benchmark/large'));
            $this->fail('An oversize target response was accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('1024-byte limit', $exception->getMessage());
        }

        $allowlist = $this->allowlist();
        $result = (new TestExecutor($client, $allowlist))->execute(['steps' => [
            ['request' => ['method' => 'GET', 'path' => '/large'], 'assertions' => [['type' => 'status', 'equals' => 200]]],
        ]], 'http://benchmark');
        $this->assertNull($result->stepResults[0]->response);
        $this->assertStringContainsString('1024-byte limit', (string) $result->stepResults[0]->errorMessage);
    }

    public function test_default_response_cap_is_several_megabytes(): void
    {
        $this->assertGreaterThanOrEqual(1024 * 1024, LaravelTargetHttpClient::MAX_RESPONSE_BYTES);
    }

    public function test_send_handles_absent_body_null_false_array_and_object(): void
    {
        Http::fake();
        $client = new LaravelTargetHttpClient(new TargetAllowlistValidator(
            ['benchmark'], new FakeTargetHostResolver(['benchmark' => ['127.0.0.1']]),
        ));

        // 1. Absent body: hasBody is false
        $client->send(new TargetRequest(
            method: 'POST',
            url: 'http://benchmark/test1',
            hasBody: false,
        ));

        // 2. Body is null: hasBody is true
        $client->send(new TargetRequest(
            method: 'POST',
            url: 'http://benchmark/test2',
            body: null,
            hasBody: true,
        ));

        // 3. Body is false: hasBody is true
        $client->send(new TargetRequest(
            method: 'POST',
            url: 'http://benchmark/test3',
            body: false,
            hasBody: true,
        ));

        // 4. Body is []: hasBody is true
        $client->send(new TargetRequest(
            method: 'POST',
            url: 'http://benchmark/test4',
            body: [],
            hasBody: true,
        ));

        // 5. Body is {}: hasBody is true
        $client->send(new TargetRequest(
            method: 'POST',
            url: 'http://benchmark/test5',
            body: new \stdClass,
            hasBody: true,
        ));

        /** @var array<int, array{0: Request, 1: ?Response}> $recorded */
        $recorded = Http::recorded();
        $this->assertCount(5, $recorded);

        // 1. Absent body
        $req1 = $recorded[0][0];
        $this->assertSame('', $req1->body());
        $this->assertSame([], $req1->header('Content-Type'));

        // 2. Literal null body
        $req2 = $recorded[1][0];
        $this->assertSame('null', $req2->body());
        $this->assertSame(['application/json'], $req2->header('Content-Type'));

        // 3. Literal false body
        $req3 = $recorded[2][0];
        $this->assertSame('false', $req3->body());
        $this->assertSame(['application/json'], $req3->header('Content-Type'));

        // 4. Array [] body
        $req4 = $recorded[3][0];
        $this->assertSame('[]', $req4->body());
        $this->assertSame(['application/json'], $req4->header('Content-Type'));

        // 5. Object {} body
        $req5 = $recorded[4][0];
        $this->assertSame('{}', $req5->body());
        $this->assertSame(['application/json'], $req5->header('Content-Type'));
    }

    /** @return iterable<string, array{string, string, mixed}> */
    public static function mediaCases(): iterable
    {
        foreach (['executor', 'direct-client'] as $mode) {
            foreach (['Content-Type', 'content-type', 'CONTENT-TYPE', 'cOnTeNt-TyPe'] as $header) {
                foreach (['null' => null, 'object' => (object) ['name' => 'Pen']] as $bodyKind => $body) {
                    yield "$mode-$header-$bodyKind" => [$mode, $header, $body];
                }
            }
        }
    }

    #[DataProvider('mediaCases')]
    public function test_explicit_content_type_is_preserved_case_insensitively(string $mode, string $header, mixed $body): void
    {
        $headers = [$header => 'application/vnd.review+json'];
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'Review content type casing',
            'endpoint' => ['method' => 'POST', 'path' => '/media'],
            'technique' => 'HAPPY_PATH',
            'source' => 'MANUAL',
            'steps' => [
                [
                    'name' => 'Send media',
                    'request' => ['method' => 'POST', 'path' => '/media', 'headers' => $headers, 'body' => $body],
                    'assertions' => [['type' => 'status', 'equals' => 200]],
                ],
            ],
        ];
        $validation = (new DslValidator)->validate($dsl);
        $this->assertTrue($validation->isValid, implode('; ', $validation->errors));

        /** @var list<string> $wireType */
        $wireType = [];
        Http::fake(function (Request $request) use (&$wireType) {
            $wireType = [];
            foreach ($request->headers() as $name => $values) {
                if (strcasecmp($name, 'Content-Type') === 0) {
                    array_push($wireType, ...$values);
                }
            }

            return Http::response(['ok' => true], 200);
        });

        $allowlist = $this->allowlist();
        $client = new LaravelTargetHttpClient($allowlist);
        if ($mode === 'executor') {
            $result = (new TestExecutor($client, $allowlist))->execute($dsl, 'http://benchmark');
            $this->assertTrue($result->isSuccess);
        } else {
            $response = $client->send(new TargetRequest('POST', 'http://benchmark/media', headers: $headers, body: $body, hasBody: true));
            $this->assertSame(200, $response->status);
        }

        $this->assertSame(['application/vnd.review+json'], $wireType);
        Http::assertSentCount(1);
    }
}
