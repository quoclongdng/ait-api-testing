<?php

namespace App\Data\Reports;

/**
 * A rendered report file ready to be downloaded.
 */
final readonly class ExportedFile
{
    public function __construct(
        public string $content,
        public string $contentType,
        public string $filename,
    ) {}
}
