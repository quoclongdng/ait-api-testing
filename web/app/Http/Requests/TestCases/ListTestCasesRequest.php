<?php

namespace App\Http\Requests\TestCases;

use App\Enums\TestCaseSource;
use App\Enums\TestCaseStatus;
use App\Enums\TestTechnique;
use App\Models\TestSuite;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListTestCasesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $suite = $this->route('suite');

        return $suite instanceof TestSuite && ($this->user()?->can('view', $suite) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'source' => ['sometimes', 'string', Rule::enum(TestCaseSource::class)],
            'technique' => ['sometimes', 'string', Rule::enum(TestTechnique::class)],
            'status' => ['sometimes', 'string', Rule::enum(TestCaseStatus::class)],
        ];
    }
}
