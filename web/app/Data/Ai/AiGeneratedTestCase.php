<?php

namespace App\Data\Ai;

use App\Support\JsonPreserver;

final readonly class AiGeneratedTestCase
{
    /**
     * @param  array<string, mixed>  $dsl
     * @param  list<string>  $validationErrors
     */
    public function __construct(
        public mixed $dsl,
        public string $status,
        public array $validationErrors = [],
        public int $repairAttempts = 0,
        public ?string $fingerprint = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        ResponseContract::fields($data, ['dsl', 'status', 'validation_errors', 'repair_attempts', 'fingerprint'], 'GeneratedTestCase');
        if ((! is_array($data['dsl']) && ! is_object($data['dsl'])) || (is_array($data['dsl']) && array_is_list($data['dsl']) && $data['dsl'] !== [])) {
            throw new \InvalidArgumentException("Missing or invalid required field 'dsl' in GeneratedTestCase.");
        }

        if (! in_array($data['status'], ['VALID', 'INVALID', 'DUPLICATE'], true)) {
            throw new \InvalidArgumentException("Missing or invalid required field 'status' in GeneratedTestCase.");
        }

        $rawErrors = $data['validation_errors'];
        if (! is_array($rawErrors) || ! array_is_list($rawErrors)) {
            throw new \InvalidArgumentException('validation_errors must be an array of strings.');
        }
        foreach ($rawErrors as $error) {
            if (! is_string($error)) {
                throw new \InvalidArgumentException('validation_errors must contain only strings.');
            }
        }
        if ($data['fingerprint'] !== null && (! is_string($data['fingerprint']) || preg_match('/\A[a-f0-9]{64}\z/', $data['fingerprint']) !== 1)) {
            throw new \InvalidArgumentException('fingerprint must be null or a lowercase SHA-256 hash.');
        }
        /** @var list<string> $validationErrors */
        $validationErrors = $rawErrors;

        $dsl = JsonPreserver::preserveDslPayload($data['dsl']);
        if ($dsl === [] || (is_object($dsl) && get_object_vars((object) $dsl) === [])) {
            $dsl = (object) [];
        }

        return new self(
            dsl: $dsl,
            status: $data['status'],
            validationErrors: $validationErrors,
            repairAttempts: ResponseContract::nonNegativeInteger($data['repair_attempts'], 'repair_attempts'),
            fingerprint: $data['fingerprint'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dsl' => $this->dsl,
            'status' => $this->status,
            'validation_errors' => $this->validationErrors,
            'repair_attempts' => $this->repairAttempts,
            'fingerprint' => $this->fingerprint,
        ];
    }
}
