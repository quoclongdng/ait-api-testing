<?php

namespace App\Http\Requests\BusinessRules;

use App\Http\Requests\Concerns\ValidatesProjectEndpointId;
use App\Models\BusinessRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PATCH /api/v1/business-rules/{businessRuleId} (BusinessRulePatch): partial update, the project
 * comes from the rule itself.
 */
class PatchBusinessRuleRequest extends FormRequest
{
    use ValidatesProjectEndpointId;

    public function authorize(): bool
    {
        $rule = $this->route('businessRule');

        return $rule instanceof BusinessRule && ($this->user()?->can('update', $rule) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        if (is_string($this->input('code'))) {
            $merge['code'] = strtoupper(trim($this->input('code')));
        }
        if (is_string($this->input('description'))) {
            $merge['description'] = trim($this->input('description'));
        }
        $this->merge($merge);
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
                'sometimes',
                'string',
                'max:50',
                'regex:/^[A-Z0-9_\-]+$/',
                // Duplicates are reported as 409 CONFLICT by UpdateBusinessRuleAction (contract).
            ],
            'endpoint_id' => ['sometimes', ...$this->endpointIdRules($projectId)],
            'description' => ['sometimes', 'string', 'min:1', 'max:5000'],
        ];
    }

    /**
     * minProperties: 1.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->hasAny(['code', 'endpoint_id', 'description'])) {
                    $validator->errors()->add('business_rule', 'Cần ít nhất một trong các trường: code, endpoint_id, description.');
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
            'code' => 'mã quy tắc',
            'endpoint_id' => 'endpoint áp dụng',
            'description' => 'mô tả quy tắc',
        ];
    }
}
