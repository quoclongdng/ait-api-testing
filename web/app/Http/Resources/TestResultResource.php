<?php

namespace App\Http\Resources;

use App\Models\TestResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TestResult */
class TestResultResource extends JsonResource
{
    /**
     * QA S13-03: keep the keys of the raw request/response logs and assertion results. Otherwise
     * JsonResource::filter() turns any nested object whose keys are all numeric (a target response
     * body such as {"1": ..., "2": ...}, a schema keyed by status code) into a list.
     */
    public bool $preserveKeys = true;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'test_run_id' => $this->test_run_id,
            'test_case_id' => $this->test_case_id,
            'verdict' => $this->verdict->value,
            'duration_ms' => $this->duration_ms,
            'request_log' => $this->request_log,
            'response_log' => $this->response_log,
            'assertion_results' => $this->assertion_results,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
