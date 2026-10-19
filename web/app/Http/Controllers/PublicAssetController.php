<?php

namespace App\Http\Controllers;

use App\Actions\System\ResolvePublicAssetAction;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Static files under public/ (Vite build, robots.txt, favicon.ico) served through Laravel, so they
 * get the same global middleware as every page: TrustProductionHosts, X-Request-Id and
 * SecurityHeaders. Registered without the web group (bootstrap/app.php): no session, no cookie.
 * Only reached in production (docker/web/php/router.php); in development `php -S` with Laravel's
 * server.php still serves existing files directly.
 */
class PublicAssetController extends Controller
{
    public function __invoke(string $path, ResolvePublicAssetAction $action): BinaryFileResponse
    {
        $asset = $action->execute($path);
        abort_if($asset === null, 404);

        return response()->file($asset->absolutePath, [
            'Content-Type' => $asset->contentType,
            'Cache-Control' => $asset->cacheControl,
        ]);
    }
}
