<?php

namespace App\Data\Ai;

final readonly class AiGenerateResponse
{
    /**
     * @param  list<AiGeneratedTestCase>  $testCases
     * @param  list<AiLlmCall>  $llmCalls
     */
    public function __construct(
        public array $testCases,
        public array $llmCalls,
        public AiGenerationStats $stats,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        ResponseContract::fields($data, ['test_cases', 'llm_calls', 'stats'], 'GenerateResponse');
        if (! array_key_exists('test_cases', $data) || ! is_array($data['test_cases'])) {
            throw new \InvalidArgumentException("Missing or invalid required field 'test_cases' in GenerateResponse.");
        }

        if (! array_key_exists('llm_calls', $data) || ! is_array($data['llm_calls'])) {
            throw new \InvalidArgumentException("Missing or invalid required field 'llm_calls' in GenerateResponse.");
        }

        if (! array_key_exists('stats', $data) || ! is_array($data['stats'])) {
            throw new \InvalidArgumentException("Missing or invalid required field 'stats' in GenerateResponse.");
        }

        if (! array_is_list($data['test_cases']) || ! array_is_list($data['llm_calls'])) {
            throw new \InvalidArgumentException('test_cases and llm_calls must be lists.');
        }

        // Parse llm_calls first so audit logs are collected independently
        $llmCalls = [];
        foreach ($data['llm_calls'] as $call) {
            if (! is_array($call)) {
                throw new \InvalidArgumentException("Invalid element in 'llm_calls': expected object/array.");
            }
            $llmCalls[] = AiLlmCall::fromArray($call);
        }

        $stats = AiGenerationStats::fromArray($data['stats']);

        $testCases = [];
        foreach ($data['test_cases'] as $case) {
            if (! is_array($case)) {
                throw new \InvalidArgumentException("Invalid element in 'test_cases': expected object/array.");
            }
            $testCases[] = AiGeneratedTestCase::fromArray($case);
        }

        return new self(
            testCases: $testCases,
            llmCalls: $llmCalls,
            stats: $stats,
        );
    }

    /**
     * Parse directly from JSON string preserving typed payloads and schema objects.
     */
    public static function fromJsonString(string $jsonString): self
    {
        $raw = json_decode($jsonString, false);
        if (! $raw instanceof \stdClass) {
            throw new \InvalidArgumentException('GenerateResponse must be a JSON object.');
        }

        $vars = get_object_vars($raw);
        ResponseContract::fields($vars, ['test_cases', 'llm_calls', 'stats'], 'GenerateResponse');

        if (! is_array($raw->test_cases) || ! is_array($raw->llm_calls)) {
            throw new \InvalidArgumentException('test_cases and llm_calls must be lists.');
        }

        if (! $raw->stats instanceof \stdClass) {
            throw new \InvalidArgumentException("Missing or invalid required field 'stats' in GenerateResponse.");
        }

        // 1. Parse llm_calls first
        $llmCalls = [];
        foreach ($raw->llm_calls as $call) {
            if (! $call instanceof \stdClass) {
                throw new \InvalidArgumentException("Invalid element in 'llm_calls': expected object.");
            }
            /** @var array<string, mixed> $callArray */
            $callArray = json_decode((string) json_encode($call), true);
            $llmCalls[] = AiLlmCall::fromArray($callArray);
        }

        // 2. Parse stats
        /** @var array<string, mixed> $statsArray */
        $statsArray = json_decode((string) json_encode($raw->stats), true);
        $stats = AiGenerationStats::fromArray($statsArray);

        // 3. Parse test_cases preserving typed DSL
        $testCases = [];
        foreach ($raw->test_cases as $case) {
            if (! $case instanceof \stdClass) {
                throw new \InvalidArgumentException("Invalid element in 'test_cases': expected object.");
            }
            /** @var array<string, mixed> $caseArray */
            $caseArray = json_decode((string) json_encode($case), true);
            $caseArray['dsl'] = $case->dsl;
            $testCases[] = AiGeneratedTestCase::fromArray($caseArray);
        }

        return new self(
            testCases: $testCases,
            llmCalls: $llmCalls,
            stats: $stats,
        );
    }

    /**
     * Calls not tied to one returned case: GENERATE calls and repairs of dropped cases.
     *
     * @return list<AiLlmCall>
     */
    public function unlinkedLlmCalls(): array
    {
        return array_values(array_filter($this->llmCalls, fn (AiLlmCall $call): bool => $call->caseIndex === null));
    }

    /**
     * REPAIR calls carrying the index of their case in test_cases (S13-03).
     *
     * @return list<AiLlmCall>
     */
    public function linkedLlmCalls(): array
    {
        return array_values(array_filter($this->llmCalls, fn (AiLlmCall $call): bool => $call->caseIndex !== null));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'test_cases' => array_map(fn (AiGeneratedTestCase $c) => $c->toArray(), $this->testCases),
            'llm_calls' => array_map(fn (AiLlmCall $call) => $call->toArray(), $this->llmCalls),
            'stats' => $this->stats->toArray(),
        ];
    }
}
