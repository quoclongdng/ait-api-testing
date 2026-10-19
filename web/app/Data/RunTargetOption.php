<?php

namespace App\Data;

/**
 * A target base URL suggested on screen M12. Every option is derived from the allowlist (D07);
 * the server still re-checks the chosen URL when the run is created.
 */
final readonly class RunTargetOption
{
    public function __construct(
        public string $url,
        public string $label,
    ) {}
}
