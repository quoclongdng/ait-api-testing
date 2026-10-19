<?php

namespace App\Http\Resources;

use App\Models\TestRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TestRun */
class TestRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'test_suite_id' => $this->test_suite_id,
            'target_base_url' => $this->target_base_url,
            'benchmark_variant' => $this->benchmark_variant,
            'status' => $this->status->value,
            'totals' => $this->totals,
            'triggered_by' => $this->triggered_by,
            'started_at' => $this->started_at?->toISOString(),
            'finished_at' => $this->finished_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
