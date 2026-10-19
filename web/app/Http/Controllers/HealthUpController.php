<?php

namespace App\Http\Controllers;

use App\Actions\System\DiagnoseLivenessAction;
use Illuminate\Http\Response;

/**
 * `GET /up`: liveness probe of the Docker healthchecks and scripts/deploy (S14-06).
 *
 * Replaces the framework `health: '/up'` page, which was an English HTML page loading Tailwind from
 * a CDN that the application's own CSP blocks, and which printed the exception message of a failing
 * check. This answers plain text only: no external asset, no session, no error detail (the trace id
 * in X-Request-Id links a 500 to the server log).
 */
class HealthUpController extends Controller
{
    public function __invoke(DiagnoseLivenessAction $action): Response
    {
        $up = $action->execute();

        return response($up ? 'OK' : 'Lỗi máy chủ', $up ? 200 : 500, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
