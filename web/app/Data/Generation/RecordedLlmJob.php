<?php

namespace App\Data\Generation;

use App\Data\Ai\AiGenerateResponse;
use App\Enums\GenerationStrategy;
use App\Support\RecordedRepairLinker;
use InvalidArgumentException;
use JsonException;

/**
 * A generation job of the official experiment, read back from its database dump (S13-03 demo
 * fallback) or from the compact showcase fixture derived from it (S14-05): the ai-service response
 * it received (cases + verbatim LLM calls + stats) and the parameters it ran with.
 */
final readonly class RecordedLlmJob
{
    /**
     * `auth` is the public credential context of the job (role names, never tokens, ADR-0006): the web
     * validation accepts `{{auth_token_<role>}}` in the cases only when the job carries it.
     *
     * @param  array{model: string|null, temperature: float|null, seed: int|null, max_cases_per_endpoint: int|null, auth?: array<string, mixed>|null}  $params
     */
    public function __construct(
        public int $sourceJobId,
        public GenerationStrategy $strategy,
        public array $params,
        public AiGenerateResponse $response,
    ) {}

    /**
     * Builds a recorded job from its JSON-ready record (RecordedLlmJobLoader::record() or the fixture).
     * The response goes through JSON so every `{}` of a DSL stays an object (same path as a live response).
     * Its REPAIR calls are linked to their case first when the record predates `case_index`
     * (RecordedRepairLinker), so M10 lists each case's own repairs only.
     *
     * @param  array<string, mixed>  $params  Source job params.
     *
     * @throws InvalidArgumentException When the record is not a valid ai-service response.
     */
    public static function fromRecord(int $sourceJobId, GenerationStrategy $strategy, array $params, string $responseJson): self
    {
        try {
            $response = AiGenerateResponse::fromJsonString(RecordedRepairLinker::link($responseJson));
        } catch (JsonException $e) {
            throw new InvalidArgumentException($e->getMessage(), 0, $e);
        }

        return new self(
            sourceJobId: $sourceJobId,
            strategy: $strategy,
            params: [
                'model' => is_string($params['model'] ?? null) ? $params['model'] : null,
                'temperature' => is_numeric($params['temperature'] ?? null) ? (float) $params['temperature'] : null,
                'seed' => is_int($params['seed'] ?? null) ? $params['seed'] : null,
                'max_cases_per_endpoint' => is_int($params['max_cases_per_endpoint'] ?? null) ? $params['max_cases_per_endpoint'] : null,
                'auth' => is_array($params['auth'] ?? null) ? $params['auth'] : null,
            ],
            response: $response,
        );
    }
}
