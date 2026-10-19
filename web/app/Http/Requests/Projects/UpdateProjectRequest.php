<?php

namespace App\Http\Requests\Projects;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            && ($this->user()?->can('update', $project) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        if ($this->has('name')) {
            $normalized['name'] = is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name');
        }

        if ($this->has('default_target_url')) {
            $normalized['default_target_url'] = is_string($this->input('default_target_url'))
                ? rtrim(trim($this->input('default_target_url')), '/')
                : $this->input('default_target_url');
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'default_target_url' => ['sometimes', 'nullable', 'url:http,https', 'max:500'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->hasAny(['name', 'description', 'default_target_url'])) {
                    $validator->errors()->add('project', 'Cần cung cấp ít nhất một trường để cập nhật.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'tên dự án',
            'description' => 'mô tả',
            'default_target_url' => 'target mặc định',
        ];
    }
}
