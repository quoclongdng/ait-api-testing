<?php

namespace App\Http\Middleware;

use App\Support\TraceId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global middleware: fixes the request's trace id, shares it with every log entry (including the
 * report of an unhandled exception) and echoes it in the X-Request-Id response header (S14-02).
 */
final class AssignTraceId
{
    public function handle(Request $request, Closure $next): Response
    {
        $traceId = TraceId::for($request);
        Log::shareContext(['trace_id' => $traceId]);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('X-Request-Id', $traceId);

        return $response;
    }
}
