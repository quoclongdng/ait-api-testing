<?php

namespace Tests\Feature\Security;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Tests\TestCase;

/**
 * S10-02 audit round 3 (security-review §5 proposal 1): §9 requires auth on every route
 * except login and /up. This test walks the whole route table so a route added outside
 * the auth groups in routes/web.php or routes/api.php fails CI instead of becoming public.
 * "/" only redirects (to /login or the dashboard) and is the only public page besides login.
 */
class RouteAuthCoverageTest extends TestCase
{
    /**
     * Routes that are public by design, as "METHOD uri" (HEAD ignored).
     *
     * @var list<string>
     */
    private const PUBLIC_ROUTES = [
        'GET /',                      // redirects a guest to /login and a signed-in user to the dashboard
        'GET login',
        'POST login',
        'GET up',                     // plain-text liveness probe, HealthUpController (S14-06)
        'GET api/v1/health',          // public health contract (§6)
        'GET storage/{path}',         // framework local-disk serving, rejects unsigned URLs
        'PUT storage/{path}',         // framework local-disk upload, rejects unsigned URLs
        'GET {path}',                 // public/ static files only (build/*, robots.txt, favicon.ico), PublicAssetController
    ];

    /**
     * @return list<RoutingRoute>
     */
    private function routes(): array
    {
        $router = app('router');
        $this->assertInstanceOf(Router::class, $router);

        return array_values($router->getRoutes()->getRoutes());
    }

    /**
     * @return list<string>
     */
    private function keys(RoutingRoute $route): array
    {
        return array_map(
            fn (string $method): string => $method.' '.$route->uri(),
            array_values(array_diff($route->methods(), ['HEAD'])),
        );
    }

    private function requiresAuth(RoutingRoute $route): bool
    {
        $router = app('router');
        $this->assertInstanceOf(Router::class, $router);

        $middleware = array_merge(
            array_map(strval(...), $route->gatherMiddleware()),
            array_map(strval(...), $router->gatherRouteMiddleware($route)),
        );

        foreach ($middleware as $name) {
            if ($name === 'auth' || str_starts_with($name, 'auth:') || str_starts_with($name, Authenticate::class)) {
                return true;
            }
        }

        return false;
    }

    public function test_every_non_public_route_requires_authentication(): void
    {
        $unprotected = [];

        foreach ($this->routes() as $route) {
            $keys = $this->keys($route);
            if (array_diff($keys, self::PUBLIC_ROUTES) === []) {
                continue;
            }

            if (! $this->requiresAuth($route)) {
                $unprotected[] = implode(', ', $keys);
            }
        }

        $this->assertSame([], $unprotected, 'Routes without the auth middleware (§9).');
    }

    public function test_public_route_allowlist_has_no_stale_entries(): void
    {
        $existing = [];
        foreach ($this->routes() as $route) {
            $existing = array_merge($existing, $this->keys($route));
        }

        $this->assertSame([], array_values(array_diff(self::PUBLIC_ROUTES, $existing)));
    }

    public function test_every_admin_web_route_requires_the_admin_role(): void
    {
        $adminRoutes = 0;
        $missing = [];

        foreach ($this->routes() as $route) {
            $name = $route->getName();
            if ($name === null || ! str_starts_with($name, 'admin.')) {
                continue;
            }

            $adminRoutes++;
            if (! in_array('role:ADMIN', $route->gatherMiddleware(), true)) {
                $missing[] = $name;
            }
        }

        $this->assertGreaterThan(0, $adminRoutes);
        $this->assertSame([], $missing, 'admin.* routes without role:ADMIN.');
    }

    public function test_framework_storage_routes_reject_unsigned_requests(): void
    {
        $this->get('/storage/anything.txt')->assertForbidden();
        $this->put('/storage/anything.txt')->assertForbidden();
    }
}
