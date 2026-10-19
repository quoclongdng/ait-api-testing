<?php

namespace App\Services\Target;

use App\Contracts\TargetHttpClient;
use App\Data\TargetRequest;
use App\Data\TargetResponse;
use App\Services\Execution\TargetAllowlistValidator;
use Illuminate\Support\Facades\Http;

final class LaravelTargetHttpClient implements TargetHttpClient
{
    /**
     * Hard cap on the bytes read from a target response (docs/DESIGN.md §9). The 64 KB limit only applies
     * when the result is stored; without this cap a large or endless body would exhaust the worker's
     * memory and kill the whole run instead of giving that one case an ERROR verdict.
     */
    public const MAX_RESPONSE_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private ?TargetAllowlistValidator $allowlistValidator = null,
        private int $maxResponseBytes = self::MAX_RESPONSE_BYTES,
    ) {}

    public function send(TargetRequest $request): TargetResponse
    {
        $startedAt = hrtime(true);
        $addresses = ($this->allowlistValidator ?? new TargetAllowlistValidator)->resolveAllowedAddresses($request->url);
        if (! extension_loaded('curl')) {
            throw new \RuntimeException('The target HTTP client requires ext-curl to pin resolved target addresses.');
        }
        $host = (string) parse_url($request->url, PHP_URL_HOST);
        $scheme = strtolower((string) parse_url($request->url, PHP_URL_SCHEME));
        $port = parse_url($request->url, PHP_URL_PORT) ?? ($scheme === 'https' ? 443 : 80);
        $limit = $this->maxResponseBytes;
        $curlOptions = [
            CURLOPT_PROXY => '',
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
            // Refuse a declared oversize body, and abort a streamed/chunked one once it passes the cap.
            CURLOPT_MAXFILESIZE => $limit,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn (mixed $handle, int|float $downloadTotal, int|float $downloaded): int => $downloaded > $limit ? 1 : 0,
        ];
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) === false) {
            $pinned = array_map(static fn (string $ip): string => str_contains($ip, ':') ? '['.$ip.']' : $ip, $addresses);
            $curlOptions[CURLOPT_RESOLVE] = [$host.':'.$port.':'.implode(',', $pinned)];
        }

        $pending = Http::withHeaders($request->headers)
            ->timeout($request->timeoutMs / 1000.0)
            ->connectTimeout($request->timeoutMs / 1000.0)
            ->withOptions(['allow_redirects' => false, 'http_errors' => false, 'proxy' => '', 'curl' => $curlOptions]);

        $options = $request->query === [] ? [] : ['query' => $request->query];
        if ($request->hasBody) {
            if ($request->body === null) {
                $options['body'] = 'null';
                if (! $this->hasHeader($request->headers, 'Content-Type')) {
                    $pending = $pending->withHeaders(['Content-Type' => 'application/json']);
                }
            } else {
                $options['json'] = $request->body;
            }
        }

        $response = $pending->send(strtoupper($request->method), $request->url, $options);

        $body = $response->toPsrResponse()->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $content = $body->read($limit + 1);
        while (strlen($content) <= $limit && ! $body->eof()) {
            $chunk = $body->read($limit + 1 - strlen($content));
            if ($chunk === '') {
                break;
            }
            $content .= $chunk;
        }
        if (strlen($content) > $limit) {
            throw new \RuntimeException("Response body exceeds the {$limit}-byte limit for target responses.");
        }

        return new TargetResponse(
            status: $response->status(),
            headers: $response->headers(),
            body: $content,
            durationMs: intdiv(hrtime(true) - $startedAt, 1_000_000),
        );
    }

    /**
     * Case-insensitive header existence check (RFC 9110 §5.1).
     *
     * @param  array<string, mixed>  $headers
     */
    private function hasHeader(array $headers, string $name): bool
    {
        foreach (array_keys($headers) as $key) {
            if (strcasecmp((string) $key, $name) === 0) {
                return true;
            }
        }

        return false;
    }
}
