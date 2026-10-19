<?php

namespace App\Enums;

/**
 * Error codes returned in the API error envelope. Source of truth: docs/DESIGN.md §6.
 * Adding a code requires updating docs/DESIGN.md and docs/contracts/web-api.openapi.yaml.
 */
enum ErrorCode: string
{
    case ValidationFailed = 'VALIDATION_FAILED';
    case Unauthenticated = 'UNAUTHENTICATED';
    case Forbidden = 'FORBIDDEN';
    case NotFound = 'NOT_FOUND';
    case MethodNotAllowed = 'METHOD_NOT_ALLOWED';
    case Conflict = 'CONFLICT';
    case InvalidStateTransition = 'INVALID_STATE_TRANSITION';
    case SpecParseFailed = 'SPEC_PARSE_FAILED';
    case DslInvalid = 'DSL_INVALID';
    case TargetNotAllowed = 'TARGET_NOT_ALLOWED';
    case RateLimited = 'RATE_LIMITED';
    case AiServiceUnavailable = 'AI_SERVICE_UNAVAILABLE';
    case LlmProviderError = 'LLM_PROVIDER_ERROR';
    case InternalError = 'INTERNAL_ERROR';

    public function httpStatus(): int
    {
        return match ($this) {
            self::ValidationFailed, self::SpecParseFailed, self::DslInvalid, self::TargetNotAllowed => 422,
            self::Unauthenticated => 401,
            self::Forbidden => 403,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
            self::Conflict, self::InvalidStateTransition => 409,
            self::RateLimited => 429,
            self::LlmProviderError => 502,
            self::AiServiceUnavailable => 503,
            self::InternalError => 500,
        };
    }
}
