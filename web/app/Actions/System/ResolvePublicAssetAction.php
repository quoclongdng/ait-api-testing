<?php

namespace App\Actions\System;

use App\Data\PublicAsset;

/**
 * Resolves a request path to a servable static file under public/ (S13-03, QA infra-deploy).
 *
 * In production `php -S` runs docker/web/php/router.php, which sends every request through Laravel:
 * the built-in server's own static file handler answered build assets, robots.txt and favicon.ico
 * without the SecurityHeaders headers and without the TrustProductionHosts Host check. Only files
 * with an allowlisted extension are served, with an explicit Content-Type (never guessed from the
 * content: nosniff would otherwise block a stylesheet sniffed as text/plain). Dot files (.htaccess),
 * PHP sources, `..` segments and anything resolving outside public/ (symlinks included) are refused.
 */
final class ResolvePublicAssetAction
{
    /** Extension => Content-Type. */
    private const CONTENT_TYPES = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'text/javascript; charset=UTF-8',
        'mjs' => 'text/javascript; charset=UTF-8',
        'json' => 'application/json',
        'map' => 'application/json',
        'txt' => 'text/plain; charset=UTF-8',
        'ico' => 'image/x-icon',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
    ];

    /** Vite output file names carry a content hash, so they never change under the same URL. */
    private const IMMUTABLE_PREFIX = 'build/assets/';

    public function execute(string $path): ?PublicAsset
    {
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')) {
            return null;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || str_starts_with($segment, '.')) {
                return null;
            }
        }

        $contentType = self::CONTENT_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
        if ($contentType === null) {
            return null;
        }

        $root = realpath(public_path());
        $file = realpath(public_path($path));
        if ($root === false || $file === false || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR) || ! is_file($file)) {
            return null;
        }

        $cacheControl = str_starts_with($path, self::IMMUTABLE_PREFIX)
            ? 'public, max-age=31536000, immutable'
            : 'public, max-age=3600';

        return new PublicAsset($file, $contentType, $cacheControl);
    }
}
