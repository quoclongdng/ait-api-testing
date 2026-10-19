<?php

namespace App\Http\Resources;

use App\Models\ApiSpec;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ApiSpec
 */
class ApiSpecResource extends JsonResource
{
    /**
     * Transform the resource into an array matching ApiSpec contract.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'version_label' => $this->version_label,
            'source_filename' => $this->source_filename,
            'format' => $this->format->value,
            'openapi_version' => $this->openapi_version,
            'checksum' => $this->checksum,
            'status' => $this->status->value,
            'parse_errors' => $this->parse_errors,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
