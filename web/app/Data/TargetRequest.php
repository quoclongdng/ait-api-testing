<?php

namespace App\Data;

final readonly class TargetRequest
{
    public bool $hasBody;

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, scalar>  $query
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers = [],
        public array $query = [],
        public mixed $body = null,
        public int $timeoutMs = 10000,
        ?bool $hasBody = null,
    ) {
        if ($timeoutMs <= 0) {
            throw new \InvalidArgumentException('Target request timeout_ms must be greater than zero.');
        }
        $this->hasBody = $hasBody ?? ($body !== null);
    }
}
