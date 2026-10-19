<?php

namespace App\Http\Resources;

use App\Data\Generation\GenerationJobSummary;
use App\Models\GenerationJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GenerationJob */
class GenerationJobResource extends JsonResource
{
    /** Keys allowed by GenerationParams (additionalProperties: false) in web-api.openapi.yaml. */
    public const PARAM_KEYS = [
        'endpoint_ids',
        'techniques',
        'max_cases_per_endpoint',
        'model',
        'temperature',
        'seed',
        'max_repair_attempts',
        // ADR-0006: set by experiments (auth without tokens, business rules snapshot).
        'auth',
        'business_rules',
    ];

    public function __construct(GenerationJob $resource, private ?GenerationJobSummary $summary = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'api_spec_id' => $this->api_spec_id,
            'test_suite_id' => $this->test_suite_id,
            'strategy' => $this->strategy->value,
            'status' => $this->status->value,
            // Internal keys (e.g. seed_cases, options) are not part of the contract.
            'params' => array_intersect_key($this->params, array_flip(self::PARAM_KEYS)),
            'stats' => $this->stats,
            'error' => $this->error,
            'started_at' => $this->started_at?->toISOString(),
            'finished_at' => $this->finished_at?->toISOString(),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
            // M11 progress & statistics (S6-04); only on GET /generation-jobs/{id}.
            'summary' => $this->when($this->summary !== null, fn (): ?array => $this->summary?->toArray()),
        ];
    }
}
