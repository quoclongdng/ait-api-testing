<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session only for visitors who already have one (S14-03, ADR-0008).
 *
 * Used on the public route "/" in place of the web group's StartSession,
 * ShareErrorsFromSession and CSRF middleware: an anonymous first visit (no session cookie, no
 * remember-me cookie) cannot be signed in, so it is served without starting a session. Nothing is
 * written to the `sessions` table (SESSION_DRIVER=database) and no cookie is set, which keeps bots and
 * uptime monitors from filling the table. A visitor who has either cookie gets the normal session, so a
 * signed-in user is still redirected to the dashboard.
 *
 * Composition rather than a StartSession subclass: Route::withoutMiddleware(StartSession::class) also
 * removes subclasses.
 */
class StartSessionWhenCookiePresent
{
    public function __construct(private readonly StartSession $startSession) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->hasSessionOrRememberCookie($request)) {
            /** @var Response */
            return $this->startSession->handle($request, $next);
        }

        // Views of the page never read $errors, but keep the variable defined like the web group does.
        view()->share('errors', new ViewErrorBag);

        return $next($request);
    }

    private function hasSessionOrRememberCookie(Request $request): bool
    {
        if ($request->cookies->has((string) config('session.cookie'))) {
            return true;
        }

        // Remember-me cookie of the session guard: it can sign a visitor in without a session cookie.
        return $request->cookies->has(Auth::guard()->getRecallerName());
    }
}
