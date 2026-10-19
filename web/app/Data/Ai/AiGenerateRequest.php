<?php

namespace App\Data\Ai;

use App\Enums\GenerationStrategy;
use InvalidArgumentException;

final readonly class AiGenerateRequest
{
    /**
     * @param  list<array<string, mixed>>  $endpoints
     * @param  list<array<string, mixed>>  $businessRules
     * @param  list<array<string, mixed>>  $seedCases
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>|null  $auth  ADR-0006 credential context without tokens (AuthFixture::toPublicArray())
     */
    public function __construct(
        public int $jobId,
        public GenerationStrategy $strategy,
        public array $endpoints,
        public array $businessRules = [],
        public array $seedCases = [],
        public array $options = [],
        public ?array $auth = null,
    ) {
        if ($this->strategy === GenerationStrategy::Hybrid && $this->seedCases === []) {
            throw new InvalidArgumentException('seed_cases must have at least 1 item when strategy is HYBRID');
        }
    }

    /**
     * Convert to array matching ai-service GenerateRequest contract.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $allowedOptionKeys = [
            'max_cases_per_endpoint',
            'techniques',
            'model',
            'temperature',
            'seed',
            'max_repair_attempts',
        ];

        $filteredOptions = array_intersect_key($this->options, array_flip($allowedOptionKeys));

        $payload = [
            'job_id' => $this->jobId,
            'strategy' => $this->strategy->value,
            'endpoints' => $this->endpoints,
            'business_rules' => $this->businessRules,
            'seed_cases' => $this->seedCases,
            'options' => array_merge([
                'max_cases_per_endpoint' => 10,
                'techniques' => ['HAPPY_PATH'],
                'model' => null,
                'temperature' => 0.2,
                'seed' => null,
                'max_repair_attempts' => 2,
            ], $filteredOptions),
        ];
        if ($this->auth !== null) {
            $payload['auth'] = $this->auth;
        }

        return $payload;
    }
}
