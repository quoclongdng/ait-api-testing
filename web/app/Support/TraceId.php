<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * One trace id per request (§6 `trace_id`): the caller's X-Request-Id when it is a short opaque token,
 * otherwise a fresh UUID. Shown on the error pages, returned in the API envelope and the X-Request-Id
 * response header, and attached to every log line of the request, so a visitor's report can be matched
 * to the server log without exposing any detail of the error.
 */
final class TraceId
{
    public const ATTRIBUTE = 'ait.trace_id';

    /** Opaque token only: no spaces/control characters that could forge log lines or headers. */
    private const PATTERN = '/^[A-Za-z0-9._:\-]{1,128}$/';

    public static function for(Request $request): string
    {
        $current = $request->attributes->get(self::ATTRIBUTE);
        if (is_string($current) && $current !== '') {
            return $current;
        }

        $header = $request->headers->get('X-Request-Id');
        $traceId = is_string($header) && preg_match(self::PATTERN, $header) === 1
            ? $header
            : (string) Str::uuid();
        $request->attributes->set(self::ATTRIBUTE, $traceId);

        return $traceId;
    }

    /** The trace id of the current HTTP request (a fresh UUID outside one). */
    public static function current(): string
    {
        $request = app()->bound('request') ? app('request') : null;

        return $request instanceof Request ? self::for($request) : (string) Str::uuid();
    }
}
