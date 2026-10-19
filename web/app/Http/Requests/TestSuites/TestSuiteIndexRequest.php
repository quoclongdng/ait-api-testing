<?php

namespace App\Http\Requests\TestSuites;

use App\Enums\TestCaseSource;
use App\Enums\TestCaseStatus;
use App\Enums\TestTechnique;
use App\Models\TestSuite;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TestSuiteIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var TestSuite|null $suite */
        $suite = $this->route('suite');

        return $suite !== null && ($this->user()?->can('view', $suite) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'source' => ['sometimes', 'nullable', Rule::enum(TestCaseSource::class)],
            'technique' => ['sometimes', 'nullable', Rule::enum(TestTechnique::class)],
            'status' => ['sometimes', 'nullable', Rule::enum(TestCaseStatus::class)],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:100'],
        ];
    }
}
