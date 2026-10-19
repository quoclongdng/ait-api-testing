<?php

namespace App\Data\OpenApi;

use App\Enums\ApiSpecFormat;

/**
 * Result of parsing and resolving an OpenAPI specification.
 */
readonly class ParsedOpenApi
{
    /**
     * @param  list<ParsedEndpoint>  $endpoints
     * @param  array<string, mixed>|null  $normalized
     * @param  list<string>  $errors
     */
    public function __construct(
        public bool $isValid,
        public ApiSpecFormat $format,
        public ?string $openapiVersion = null,
        public ?string $title = null,
        public ?array $normalized = null,
        public array $endpoints = [],
        public array $errors = [],
    ) {}

    /**
     * Factory for a failed parse result.
     *
     * @param  list<string>  $errors
     */
    public static function failed(ApiSpecFormat $format, array $errors, ?string $openapiVersion = null): self
    {
        return new self(
            isValid: false,
            format: $format,
            openapiVersion: $openapiVersion,
            title: null,
            normalized: null,
            endpoints: [],
            errors: $errors,
        );
    }
}
