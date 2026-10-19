<?php

namespace App\Http\Requests\Runs;

use App\Enums\TestVerdict;
use App\Models\TestRun;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListTestResultsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $run = $this->route('run');

        return $run instanceof TestRun && ($this->user()?->can('view', $run) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'verdict' => ['sometimes', 'string', Rule::enum(TestVerdict::class)],
        ];
    }
}
