<?php

namespace App\Services\Support;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns a caught exception into a message that may be stored in a user-visible column
 * (test_results.error_message, generation_jobs.error, experiments.metrics.error).
 *
 * ApiException messages are written for users and pass through unchanged. Any other throwable
 * (QueryException, TypeError, HTTP client errors...) may carry SQL, class names or internal hosts:
 * the detail is logged and only a generic Vietnamese message is stored, with the raw message
 * appended only when APP_DEBUG=true (docs/DESIGN.md §6: INTERNAL_ERROR hides details without debug).
 */
final class SafeErrorMessage
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function from(Throwable $e, string $generic, array $context = []): string
    {
        if ($e instanceof ApiException) {
            return $e->getMessage();
        }

        Log::error($generic, $context + [
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        if ((bool) config('app.debug')) {
            return $generic.' Chi tiết: '.$e->getMessage();
        }

        return $generic;
    }
}
