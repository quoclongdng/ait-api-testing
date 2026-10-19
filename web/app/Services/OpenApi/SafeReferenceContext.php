<?php

namespace App\Services\OpenApi;

use cebe\openapi\exceptions\UnresolvableReferenceException;
use cebe\openapi\ReferenceContext;

/**
 * Strict reference context prohibiting any external file or network I/O.
 *
 * Enforces docs/DESIGN.md §9 security constraints against SSRF and local file inclusion.
 */
class SafeReferenceContext extends ReferenceContext
{
    /**
     * Disable external file reading completely for security.
     *
     * @param  string  $uri
     *
     * @throws UnresolvableReferenceException
     */
    public function fetchReferencedFile($uri): mixed
    {
        throw new UnresolvableReferenceException('Truy xuất tệp tham chiếu ngoài ($ref) bị vô hiệu hóa vì lý do bảo mật.');
    }

    /**
     * Only allow local fragment references (#/...).
     *
     * @throws UnresolvableReferenceException
     */
    public function resolveRelativeUri(string $uri): string
    {
        if (! str_starts_with($uri, '#/')) {
            throw new UnresolvableReferenceException('Chỉ chấp nhận tham chiếu nội bộ dạng #/...');
        }

        return parent::resolveRelativeUri($uri);
    }
}
