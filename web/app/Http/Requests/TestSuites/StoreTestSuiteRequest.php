<?php

namespace App\Http\Requests\TestSuites;

use App\Enums\ApiSpecStatus;
use App\Models\Project;
use App\Models\TestSuite;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTestSuiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Project|null $project */
        $project = $this->route('project');

        return $project !== null && ($this->user()?->can('create', [TestSuite::class, $project]) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'description' => is_string($this->input('description')) ? trim($this->input('description')) : $this->input('description'),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Project $project */
        $project = $this->route('project');

        return [
            // §4.5: only a PARSED spec can be used to generate tests
            'api_spec_id' => [
                'required',
                'integer',
                Rule::exists('api_specs', 'id')
                    ->where('project_id', $project->id)
                    ->where('status', ApiSpecStatus::Parsed->value),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'api_spec_id.exists' => 'Chỉ có thể tạo bộ kiểm thử từ đặc tả OpenAPI đã phân tích thành công (PARSED).',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'api_spec_id' => 'đặc tả OpenAPI',
            'name' => 'tên bộ kiểm thử',
            'description' => 'mô tả',
        ];
    }
}
