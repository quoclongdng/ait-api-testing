<?php

namespace App\Http;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Support\TraceId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Renders every exception under /api/* into the error envelope defined in docs/DESIGN.md §6:
 * { "error": { "code", "message", "details" }, "trace_id" }.
 *
 * Web (non-/api) routes asked for JSON (login/logout clients, fetch) get the same envelope for HTTP and
 * server errors (S14-06): Laravel's default JSON was English, had no trace_id and echoed the path of an
 * unknown route ("The route … could not be found."). Two exceptions keep the framework response there:
 * a ValidationException keeps Laravel's `{ message, errors }` shape the web forms rely on, and an
 * HttpResponseException already carries the response its thrower chose. HTML requests are untouched.
 */
final class ApiErrorRenderer
{
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if ($request->is('api/*')) {
            return $this->render($e, $request);
        }

        if ($request->expectsJson() && ! $e instanceof ValidationException && ! $e instanceof HttpResponseException) {
            return $this->render($e, $request);
        }

        return null;
    }

    private function render(Throwable $e, Request $request): JsonResponse
    {
        [$code, $message, $details] = match (true) {
            $e instanceof ApiException => [$e->errorCode, $e->getMessage(), $e->details],
            $e instanceof ValidationException => [ErrorCode::ValidationFailed, __('The given data was invalid.'), $e->errors()],
            $e instanceof AuthenticationException => [ErrorCode::Unauthenticated, __('Unauthenticated.'), []],
            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => [ErrorCode::Forbidden, __('This action is unauthorized.'), []],
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => [ErrorCode::NotFound, __('Resource not found.'), []],
            $e instanceof MethodNotAllowedHttpException => [ErrorCode::MethodNotAllowed, __('Method not allowed.'), []],
            $e instanceof ThrottleRequestsException => [ErrorCode::RateLimited, __('Too many requests.'), []],
            // Generic HTTP exceptions: CSRF mismatch (419, converted by prepareException), 413 post too
            // large, abort(400)... are client errors, never INTERNAL_ERROR. Mapped onto §6 codes.
            $e instanceof HttpExceptionInterface => $this->fromHttpStatus($e->getStatusCode()),
            default => [ErrorCode::InternalError, $e->getMessage(), []],
        };

        // INTERNAL_ERROR never leaks its cause (exception message, SQL, paths) unless APP_DEBUG=true,
        // even when raised as an ApiException; the trace_id links the visitor to the server log.
        if ($code === ErrorCode::InternalError && ! (bool) config('app.debug')) {
            [$message, $details] = [__('Internal server error.'), []];
        }

        $response = new JsonResponse([
            'error' => [
                'code' => $code->value,
                'message' => $message,
                'details' => (object) $details,
            ],
            // Same id as the X-Request-Id response header and the log context (AssignTraceId).
            'trace_id' => TraceId::for($request),
        ], $code->httpStatus());

        // HTTP exceptions carry protocol headers the client needs: Retry-After and X-RateLimit-* on a
        // 429 from ThrottleRequests, Allow on a 405 (S13-03).
        if ($e instanceof HttpExceptionInterface) {
            $response->headers->add($e->getHeaders());
        }

        return $response;
    }

    /**
     * @return array{0: ErrorCode, 1: string, 2: array<string, mixed>}
     */
    private function fromHttpStatus(int $status): array
    {
        return match (true) {
            $status === 419 => [ErrorCode::Unauthenticated, __('Session expired. Please reload the page and sign in again.'), []],
            $status === 401 => [ErrorCode::Unauthenticated, __('Unauthenticated.'), []],
            $status === 403 => [ErrorCode::Forbidden, __('This action is unauthorized.'), []],
            $status === 404, $status === 410 => [ErrorCode::NotFound, __('Resource not found.'), []],
            $status === 405 => [ErrorCode::MethodNotAllowed, __('Method not allowed.'), []],
            $status === 409 => [ErrorCode::Conflict, __('Conflict.'), []],
            $status === 413 => [ErrorCode::ValidationFailed, __('The request payload is too large.'), []],
            $status === 429 => [ErrorCode::RateLimited, __('Too many requests.'), []],
            $status >= 400 && $status < 500 => [ErrorCode::ValidationFailed, __('The given data was invalid.'), []],
            default => [ErrorCode::InternalError, __('Internal server error.'), []],
        };
    }
}
