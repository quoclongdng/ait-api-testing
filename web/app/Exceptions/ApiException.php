<?php

namespace App\Exceptions;

use App\Data\Ai\AiLlmCall;
use App\Enums\ErrorCode;
use RuntimeException;

/**
 * Throw from Actions/Services to return a business error in the standard envelope.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     * @param  list<AiLlmCall>  $llmCalls
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly array $llmCalls = [],
    ) {
        parent::__construct($message);
    }
}
