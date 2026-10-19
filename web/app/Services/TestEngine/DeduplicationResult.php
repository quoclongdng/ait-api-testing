<?php

namespace App\Services\TestEngine;

class DeduplicationResult
{
    /**
     * @param  list<array<string, mixed>>  $processed
     * @param  list<array<string, mixed>>  $uniques
     * @param  list<array<string, mixed>>  $duplicates
     */
    public function __construct(
        public readonly int $total,
        public readonly int $uniqueCount,
        public readonly int $duplicateCount,
        public readonly float $redundancyRate,
        public readonly array $processed,
        public readonly array $uniques,
        public readonly array $duplicates,
    ) {}

    public function hasDuplicates(): bool
    {
        return $this->duplicateCount > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'unique_count' => $this->uniqueCount,
            'duplicate_count' => $this->duplicateCount,
            'redundancy_rate' => $this->redundancyRate,
            'processed' => $this->processed,
            'uniques' => $this->uniques,
            'duplicates' => $this->duplicates,
        ];
    }
}
