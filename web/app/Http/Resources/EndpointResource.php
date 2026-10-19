<?php

namespace App\Http\Resources;

use App\Models\Endpoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Endpoint
 */
class EndpointResource extends JsonResource
{
    /**
     * QA S13-03: JsonResource::filter() re-indexes every nested array whose keys are all numeric, so
     * `responses` keyed by status code ({"200": ..., "422": ...}) came out as a JSON list. Raw jsonb
     * columns are passed through with their keys preserved.
     */
    public bool $preserveKeys = true;

    /**
     * Transform the resource into an array matching Endpoint contract.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'api_spec_id' => $this->api_spec_id,
            'method' => $this->method->value,
            'path' => $this->path,
            'operation_id' => $this->operation_id,
            'summary' => $this->summary,
            'description' => $this->description,
            'parameters' => $this->parameters,
            // JsonObject in the contract: an empty or numeric-keyed array must still encode as an object.
            'request_body' => $this->request_body === null ? null : (object) $this->request_body,
            'responses' => (object) $this->responses,
            'security' => $this->security,
        ];
    }
}
