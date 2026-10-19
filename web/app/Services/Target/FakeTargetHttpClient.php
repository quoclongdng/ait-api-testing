<?php

namespace App\Services\Target;

use App\Contracts\TargetHttpClient;
use App\Data\TargetRequest;
use App\Data\TargetResponse;

/**
 * In-memory target. Queue responses with respondWith(); every sent request is recorded.
 * Returns 404 with an empty JSON body when no response is queued.
 */
final class FakeTargetHttpClient implements TargetHttpClient
{
    /** @var list<TargetResponse> */
    private array $queue = [];

    /** @var list<TargetRequest> */
    private array $sent = [];

    /**
     * @param  array<string, list<string>|string>  $headers
     */
    public function respondWith(int $status, mixed $json = null, array $headers = ['Content-Type' => ['application/json']], ?string $rawBody = null, int $durationMs = 1): self
    {
        $normalizedHeaders = [];
        foreach ($headers as $key => $values) {
            $normalizedHeaders[$key] = is_array($values) ? $values : [(string) $values];
        }

        $this->queue[] = new TargetResponse(
            status: $status,
            headers: $normalizedHeaders,
            body: $rawBody ?? (string) json_encode($json),
            durationMs: $durationMs,
        );

        return $this;
    }

    public function send(TargetRequest $request): TargetResponse
    {
        $this->sent[] = $request;

        return array_shift($this->queue)
            ?? new TargetResponse(404, ['Content-Type' => ['application/json']], '{}', 1);
    }

    /**
     * @return list<TargetRequest>
     */
    public function sent(): array
    {
        return $this->sent;
    }
}
