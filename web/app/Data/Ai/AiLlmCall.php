<?php

namespace App\Data\Ai;

use App\Enums\LlmPurpose;

final readonly class AiLlmCall
{
    /**
     * @param  list<array{role: string, content: string}>  $prompt
     */
    public function __construct(
        public LlmPurpose $purpose,
        public string $provider,
        public string $model,
        public array $prompt,
        public string $responseRaw,
        public float $temperature,
        public ?int $seed,
        public ?int $promptTokens,
        public ?int $completionTokens,
        public int $latencyMs,
        public ?string $error = null,
        public ?int $caseIndex = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        // Optional case_index (ai-service contract, S13-03): index in test_cases of the case a
        // REPAIR call worked on; absent in older responses and recorded demo jobs.
        $caseIndex = null;
        if (array_key_exists('case_index', $data)) {
            if ($data['case_index'] !== null) {
                $caseIndex = ResponseContract::nonNegativeInteger($data['case_index'], 'case_index');
            }
            unset($data['case_index']);
        }
        ResponseContract::fields($data, ['purpose', 'provider', 'model', 'prompt', 'response_raw', 'temperature', 'seed', 'prompt_tokens', 'completion_tokens', 'latency_ms', 'error'], 'LlmCall');
        if (! is_string($data['provider']) || trim($data['provider']) === '') {
            throw new \InvalidArgumentException("Missing or invalid required field 'provider' in LlmCall.");
        }

        if (! is_string($data['model']) || trim($data['model']) === '') {
            throw new \InvalidArgumentException("Missing or invalid required field 'model' in LlmCall.");
        }

        if (! is_array($data['prompt']) || ! array_is_list($data['prompt']) || $data['prompt'] === []) {
            throw new \InvalidArgumentException("Missing or invalid required field 'prompt' in LlmCall.");
        }

        if (! array_key_exists('response_raw', $data) || ! is_string($data['response_raw'])) {
            throw new \InvalidArgumentException("Missing or invalid required field 'response_raw' in LlmCall.");
        }

        if (! array_key_exists('latency_ms', $data)) {
            throw new \InvalidArgumentException("Missing or invalid required field 'latency_ms' in LlmCall.");
        }

        if (! is_string($data['purpose']) || ! in_array($data['purpose'], ['GENERATE', 'REPAIR'], true)) {
            throw new \InvalidArgumentException('Invalid purpose in LlmCall.');
        }
        $purpose = LlmPurpose::from($data['purpose']);
        $prompt = [];
        foreach ($data['prompt'] as $message) {
            if (! is_array($message)) {
                throw new \InvalidArgumentException('Invalid ChatMessage in LlmCall prompt.');
            }
            ResponseContract::fields($message, ['role', 'content'], 'ChatMessage');
            if (! in_array($message['role'], ['system', 'user', 'assistant'], true) || ! is_string($message['content'])) {
                throw new \InvalidArgumentException('Invalid ChatMessage role/content.');
            }
            $prompt[] = ['role' => $message['role'], 'content' => $message['content']];
        }
        if ((! is_int($data['temperature']) && ! is_float($data['temperature'])) || ! is_finite((float) $data['temperature']) || $data['temperature'] < 0 || $data['temperature'] > 2) {
            throw new \InvalidArgumentException('temperature must be a number between 0 and 2.');
        }
        if ($data['seed'] !== null && ! is_int($data['seed'])) {
            throw new \InvalidArgumentException('seed must be an integer or null.');
        }
        foreach (['prompt_tokens', 'completion_tokens'] as $key) {
            if ($data[$key] !== null) {
                $data[$key] = ResponseContract::nonNegativeInteger($data[$key], $key);
            }
        }
        if ($data['error'] !== null && ! is_string($data['error'])) {
            throw new \InvalidArgumentException('error must be a string or null.');
        }

        return new self(
            purpose: $purpose,
            provider: $data['provider'],
            model: $data['model'],
            prompt: $prompt,
            responseRaw: $data['response_raw'],
            temperature: (float) $data['temperature'],
            seed: $data['seed'],
            promptTokens: $data['prompt_tokens'],
            completionTokens: $data['completion_tokens'],
            latencyMs: ResponseContract::nonNegativeInteger($data['latency_ms'], 'latency_ms'),
            error: $data['error'],
            caseIndex: $caseIndex,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'purpose' => $this->purpose->value,
            'provider' => $this->provider,
            'model' => $this->model,
            'prompt' => $this->prompt,
            'response_raw' => $this->responseRaw,
            'temperature' => $this->temperature,
            'seed' => $this->seed,
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'latency_ms' => $this->latencyMs,
            'error' => $this->error,
            ...($this->caseIndex !== null ? ['case_index' => $this->caseIndex] : []),
        ];
    }
}
