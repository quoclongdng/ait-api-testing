<?php

namespace App\Http\Requests\Runs;

use App\Models\TestRun;
use App\Models\TestSuite;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTestRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        $suite = $this->route('suite');

        return $suite instanceof TestSuite
            && ($this->user()?->can('create', [TestRun::class, $suite]) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'target_base_url' => ['required', 'string', 'url:http,https', 'max:500'],
            'benchmark_variant' => ['sometimes', 'nullable', 'string', 'max:100'],
            'test_case_ids' => ['sometimes', 'array', 'min:1'],
            'test_case_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'target_base_url' => 'URL hệ thống đích',
            'benchmark_variant' => 'biến thể benchmark',
            'test_case_ids' => 'danh sách test case',
        ];
    }
}
