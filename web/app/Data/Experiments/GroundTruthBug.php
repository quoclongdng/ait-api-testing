<?php

namespace App\Data\Experiments;

use App\Enums\BenchmarkBugCategory;
use App\Enums\HttpMethod;

/**
 * One seeded bug of a ground-truth file.
 */
final readonly class GroundTruthBug
{
    public function __construct(
        public string $code,
        public HttpMethod $method,
        public string $path,
        public BenchmarkBugCategory $category,
        public string $description,
        public string $toggleFlag,
    ) {}
}
