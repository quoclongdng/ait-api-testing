<?php

namespace App\Data;

final readonly class TargetResponse
{
    /**
     * @param  array<string, list<string>>  $headers
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
        public int $durationMs,
    ) {}

    public function json(bool $associative = true): mixed
    {
        return json_decode($this->body, $associative);
    }
}
