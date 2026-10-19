<?php

namespace App\Data\Ai;

final readonly class AiGenerationStats
{
    public function __construct(
        public int $generated = 0,
        public int $valid = 0,
        public int $invalid = 0,
        public int $repaired = 0,
        public int $duplicates = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        $requiredKeys = ['generated', 'valid', 'invalid', 'repaired', 'duplicates'];
        ResponseContract::fields($data, $requiredKeys, 'GenerationStats');
        foreach ($requiredKeys as $key) {
            $data[$key] = ResponseContract::nonNegativeInteger($data[$key], "stats.{$key}");
        }

        return new self(
            generated: (int) $data['generated'],
            valid: (int) $data['valid'],
            invalid: (int) $data['invalid'],
            repaired: (int) $data['repaired'],
            duplicates: (int) $data['duplicates'],
        );
    }

    /**
     * @return array{generated: int, valid: int, invalid: int, repaired: int, duplicates: int}
     */
    public function toArray(): array
    {
        return [
            'generated' => $this->generated,
            'valid' => $this->valid,
            'invalid' => $this->invalid,
            'repaired' => $this->repaired,
            'duplicates' => $this->duplicates,
        ];
    }
}
