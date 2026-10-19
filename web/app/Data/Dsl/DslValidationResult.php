<?php

namespace App\Data\Dsl;

/**
 * Result of validating a Test DSL document against JSON Schema and OpenAPI semantics.
 */
readonly class DslValidationResult
{
    /**
     * @param  list<string>  $errors
     * @param  list<string>  $schemaErrors
     * @param  list<string>  $semanticErrors
     * @param  array<string, mixed>|null  $dsl
     */
    public function __construct(
        public bool $isValid,
        public array $errors = [],
        public array $schemaErrors = [],
        public array $semanticErrors = [],
        public ?array $dsl = null,
    ) {}

    /**
     * Create a successful validation result.
     *
     * @param  array<string, mixed>  $dsl
     */
    public static function valid(array $dsl): self
    {
        return new self(
            isValid: true,
            errors: [],
            schemaErrors: [],
            semanticErrors: [],
            dsl: $dsl,
        );
    }

    /**
     * Create a failed validation result.
     *
     * @param  list<string>  $schemaErrors
     * @param  list<string>  $semanticErrors
     * @param  array<string, mixed>|null  $dsl
     */
    public static function invalid(array $schemaErrors = [], array $semanticErrors = [], ?array $dsl = null): self
    {
        $allErrors = array_values(array_unique(array_merge($schemaErrors, $semanticErrors)));

        return new self(
            isValid: false,
            errors: $allErrors,
            schemaErrors: $schemaErrors,
            semanticErrors: $semanticErrors,
            dsl: $dsl,
        );
    }
}
