<?php

namespace App\Data;

/**
 * A static file under public/ that Laravel serves itself in production (docker/web/php/router.php).
 */
final readonly class PublicAsset
{
    public function __construct(
        public string $absolutePath,
        public string $contentType,
        public string $cacheControl,
    ) {}
}
