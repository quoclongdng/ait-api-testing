<?php

namespace App\Http\Requests\BusinessRules;

use App\Http\Requests\Concerns\ValidatesProjectEndpointId;
use App\Models\BusinessRule;
use App\Models\Project;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateBusinessRuleRequest extends FormRequest
{
    use ValidatesProjectEndpointId;

    public function authorize(): bool
    {
        /** @var BusinessRule|null $rule */
        $rule = $this->route('businessRule');
        $project = $this->route('project');

        // Resolve the rule/project pairing before rules() runs: the uniqueness and endpoint
        // checks are scoped to the route project, so a mismatched (foreign) project must never
        // reach them, or the error messages leak which codes/endpoints exist there.
        if (! $rule instanceof BusinessRule || ! $project instanceof Project || $rule->project_id !== $project->id) {
            throw new NotFoundHttpException;
        }

        return $this->user()?->can('update', $rule) ?? false;
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
        /** @var BusinessRule $rule */
        $rule = $this->route('businessRule');
        $projectId = $rule->project_id;

        return [
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Z0-9_\-]+$/',
                Rule::unique('business_rules', 'code')
                    ->where('project_id', $projectId)
                    ->ignore($rule->id),
            ],
            'endpoint_id' => $this->endpointIdRules($projectId),
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
