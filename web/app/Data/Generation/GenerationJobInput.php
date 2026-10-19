<?php

namespace App\Data\Generation;

use App\Enums\GenerationStrategy;
use App\Enums\TestTechnique;

/**
 * Validated M11 / POST /suites/{id}/generation-jobs input (GenerationParams contract).
 *
 * LLM parameters (model, temperature, seed, max_repair_attempts) only apply to LLM/HYBRID
 * and are dropped from RULE jobs.
 */
final readonly class GenerationJobInput
{
    public const DEFAULT_MAX_CASES_PER_ENDPOINT = 10;

    public const DEFAULT_TEMPERATURE = 0.2;

    public const DEFAULT_MAX_REPAIR_ATTEMPTS = 2;

    /** Default techniques per strategy when the caller selects none (docs/DESIGN.md §10). */
    public const DEFAULT_LLM_TECHNIQUES = ['HAPPY_PATH', 'BOUNDARY', 'INVALID_INPUT'];

    public const DEFAULT_HYBRID_TECHNIQUES = ['BUSINESS_RULE', 'SEQUENCE', 'AUTH'];

    /**
     * @param  list<int>  $endpointIds  empty = every endpoint of the suite's spec
     * @param  list<string>  $techniques
     */
    public function __construct(
        public GenerationStrategy $strategy,
        public array $endpointIds = [],
        public array $techniques = [],
        public ?int $maxCasesPerEndpoint = null,
        public ?string $model = null,
        public ?float $temperature = null,
        public ?int $seed = null,
        public ?int $maxRepairAttempts = null,
    ) {}

    /**
     * Build from a flat, already validated array (web form fields or the API `params` object).
     *
     * @param  array<string, mixed>  $params
     */
    public static function fromArray(string|GenerationStrategy $strategy, array $params): self
    {
        $strategy = $strategy instanceof GenerationStrategy ? $strategy : GenerationStrategy::from($strategy);

        $endpointIds = [];
        if (is_array($params['endpoint_ids'] ?? null)) {
            foreach ($params['endpoint_ids'] as $id) {
                if (is_numeric($id) && (int) $id > 0 && ! in_array((int) $id, $endpointIds, true)) {
                    $endpointIds[] = (int) $id;
                }
            }
        }

        $techniques = [];
        if (is_array($params['techniques'] ?? null)) {
            foreach ($params['techniques'] as $technique) {
                if (is_string($technique) && TestTechnique::tryFrom($technique) !== null && ! in_array($technique, $techniques, true)) {
                    $techniques[] = $technique;
                }
            }
        }

        $model = is_string($params['model'] ?? null) ? trim($params['model']) : '';

        return new self(
            strategy: $strategy,
            endpointIds: $endpointIds,
            techniques: $techniques,
            maxCasesPerEndpoint: self::intOrNull($params['max_cases_per_endpoint'] ?? null),
            model: $model === '' ? null : $model,
            temperature: is_numeric($params['temperature'] ?? null) ? (float) $params['temperature'] : null,
            seed: self::intOrNull($params['seed'] ?? null),
            maxRepairAttempts: self::intOrNull($params['max_repair_attempts'] ?? null),
        );
    }

    public function usesLlm(): bool
    {
        return $this->strategy !== GenerationStrategy::Rule;
    }

    /**
     * Params stored on generation_jobs.params (GenerationParams contract). For LLM/HYBRID they are
     * forwarded as ai-service `options` by ExecuteAiGenerationAction::buildRequest.
     *
     * @param  list<int>  $resolvedEndpointIds
     * @return array<string, mixed>
     */
    public function toParams(array $resolvedEndpointIds): array
    {
        $techniques = $this->techniques;
        if ($techniques === [] && $this->strategy === GenerationStrategy::Llm) {
            $techniques = self::DEFAULT_LLM_TECHNIQUES;
        } elseif ($techniques === [] && $this->strategy === GenerationStrategy::Hybrid) {
            $techniques = self::DEFAULT_HYBRID_TECHNIQUES;
        }

        $params = [
            'endpoint_ids' => $resolvedEndpointIds,
            'max_cases_per_endpoint' => $this->maxCasesPerEndpoint ?? self::DEFAULT_MAX_CASES_PER_ENDPOINT,
        ];
        if ($techniques !== []) {
            $params['techniques'] = $techniques;
        }

        // RULE ignores every LLM parameter.
        if (! $this->usesLlm()) {
            return $params;
        }

        // model / seed null = ai-service runtime defaults (AI_LLM_MODEL, AI_LLM_SEED).
        $params['model'] = $this->model;
        $params['temperature'] = $this->temperature ?? self::DEFAULT_TEMPERATURE;
        $params['seed'] = $this->seed;
        $params['max_repair_attempts'] = $this->maxRepairAttempts ?? self::DEFAULT_MAX_REPAIR_ATTEMPTS;

        return $params;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
