<?php

namespace App\Http\Requests\TestSuites;

use App\Models\TestSuite;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BulkTestCaseActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var TestSuite|null $suite */
        $suite = $this->route('suite');

        return $suite !== null && ($this->user()?->can('update', $suite) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:approve,archive'],
            'test_case_ids' => ['required', 'array', 'min:1', 'max:500'],
            'test_case_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'action' => 'thao tác',
            'test_case_ids' => 'danh sách test case',
        ];
    }
}
