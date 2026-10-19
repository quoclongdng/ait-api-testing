<?php

namespace App\Data\OpenApi;

use App\Enums\HttpMethod;

/**
 * Normalized representation of an individual HTTP endpoint extracted from OpenAPI spec.
 */
readonly class ParsedEndpoint
{
    /**
     * @param  list<array<string, mixed>>|null  $parameters
     * @param  array<string, mixed>|null  $requestBody
     * @param  array<string, mixed>  $responses
     * @param  list<array<string, mixed>>|null  $security
     */
    public function __construct(
        public HttpMethod $method,
        public string $path,
        public ?string $operationId = null,
        public ?string $summary = null,
        public ?string $description = null,
        public ?array $parameters = null,
        public ?array $requestBody = null,
        public array $responses = [],
        public ?array $security = null,
    ) {}

    /**
     * Convert to an array ready for database insertion or serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method->value,
            'path' => $this->path,
            'operation_id' => $this->operationId,
            'summary' => $this->summary,
            'description' => $this->description,
            'parameters' => $this->parameters,
            'request_body' => $this->requestBody,
            'responses' => $this->responses,
            'security' => $this->security,
        ];
    }
}
