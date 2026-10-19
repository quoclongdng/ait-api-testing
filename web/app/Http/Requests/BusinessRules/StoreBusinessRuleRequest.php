<?php

namespace App\Http\Requests\BusinessRules;

use App\Http\Requests\Concerns\ValidatesProjectEndpointId;
use App\Models\BusinessRule;
use App\Models\Project;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBusinessRuleRequest extends FormRequest
{
    use ValidatesProjectEndpointId;

    public function authorize(): bool
    {
        /** @var Project|null $project */
        $project = $this->route('project');

        return $project !== null && ($this->user()?->can('create', [BusinessRule::class, $project]) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $code = $this->input('code');
        $desc = $this->input('description');
        $endpointId = $this->input('endpoint_id');

        $this->merge([
            'code' => is_string($code) ? strtoupper(trim($code)) : $code,
            'description' => is_string($desc) ? trim($desc) : $desc,
            'endpoint_id' => ($endpointId === '' || $endpointId === 'null') ? null : $endpointId,
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
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Z0-9_\-]+$/',
                // M08 shows a field error; /api/v1 reports duplicates as 409 CONFLICT (CreateBusinessRuleAction).
                ...($this->is('api/*') ? [] : [Rule::unique('business_rules', 'code')->where('project_id', $project->id)]),
            ],
            'endpoint_id' => $this->endpointIdRules($project->id),
            'description' => [
                'required',
                'string',
                'max:5000',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && strlen($value) > 10240) {
                        $fail('Mô tả quy tắc không được vượt quá 10KB.');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Mã quy tắc nghiệp vụ là bắt buộc.',
            'code.max' => 'Mã quy tắc không được vượt quá 50 ký tự.',
            'code.regex' => 'Mã quy tắc chỉ được chứa chữ hoa, số, dấu gạch nối (-) hoặc gạch dưới (_).',
            'code.unique' => 'Mã quy tắc này đã tồn tại trong dự án.',
            'description.required' => 'Mô tả quy tắc nghiệp vụ là bắt buộc.',
            'description.max' => 'Mô tả quy tắc không được vượt quá 5.000 ký tự.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'mã quy tắc',
            'endpoint_id' => 'endpoint áp dụng',
            'description' => 'mô tả quy tắc',
        ];
    }
}
