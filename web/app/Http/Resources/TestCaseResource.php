<?php

namespace App\Http\Resources;

use App\Models\TestCase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TestCase */
class TestCaseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'test_suite_id' => $this->test_suite_id,
            'endpoint_id' => $this->endpoint_id,
            'generation_job_id' => $this->generation_job_id,
            'source' => $this->source->value,
            'technique' => $this->technique->value,
            'title' => $this->title,
            // Encode as a JSON object even when the stored DSL is an empty array, and keep empty
            // objects inside it as objects so the DSL can be submitted back unchanged.
            'dsl' => $this->dslObject(),
            'dsl_version' => $this->dsl_version,
            'fingerprint' => $this->fingerprint,
            'status' => $this->status->value,
            'validation_errors' => $this->normalizeErrors($this->validation_errors),
            'repair_attempts' => $this->repair_attempts,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }

    /**
     * @param  array<array-key, mixed>|null  $errors
     * @return list<string>|null
     */
    private function normalizeErrors(?array $errors): ?array
    {
        if ($errors === null) {
            return null;
        }

        return array_values(array_map(
            fn (mixed $error): string => is_string($error) ? $error : (string) json_encode($error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $errors,
        ));
    }
}
