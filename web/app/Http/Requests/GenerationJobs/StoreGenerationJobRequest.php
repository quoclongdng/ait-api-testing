<?php

namespace App\Http\Requests\GenerationJobs;

use App\Enums\GenerationStrategy;
use App\Enums\TestTechnique;
use App\Http\Requests\Generation\CreateGenerationJobRequest;
use App\Models\GenerationJob;
use App\Models\TestSuite;
use App\Services\Generation\RuleGenerator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGenerationJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var TestSuite|null $suite */
        $suite = $this->route('suite');

        return $suite !== null && ($this->user()?->can('create', [GenerationJob::class, $suite]) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var TestSuite $suite */
        $suite = $this->route('suite');
        $isRule = $this->input('strategy') === GenerationStrategy::Rule->value;
        $techniques = $isRule
            ? array_map(static fn (TestTechnique $t): string => $t->value, RuleGenerator::SUPPORTED_TECHNIQUES)
            : array_map(static fn (TestTechnique $t): string => $t->value, TestTechnique::cases());

        return [
            'strategy' => ['required', 'string', Rule::enum(GenerationStrategy::class)],
            'endpoint_ids' => ['sometimes', 'nullable', 'array'],
            'endpoint_ids.*' => ['integer', 'distinct', Rule::exists('endpoints', 'id')->where('api_spec_id', $suite->api_spec_id)],
            'techniques' => ['sometimes', 'nullable', 'array'],
            'techniques.*' => ['string', 'distinct', Rule::in($techniques)],
            'max_cases_per_endpoint' => ['sometimes', 'nullable', 'bail', 'integer', 'min:1', 'max:100'],
            // LLM parameters: validated for every strategy, ignored (not stored) for RULE.
            // bail: one message per field ("abc" is not a number, not also out of range).
            'model' => ['sometimes', 'nullable', 'string', 'max:100'],
            'temperature' => ['sometimes', 'nullable', 'bail', 'numeric', 'min:0', 'max:2'],
            'seed' => ['sometimes', 'nullable', 'bail', 'integer', 'min:0'],
            'max_repair_attempts' => ['sometimes', 'nullable', 'bail', 'integer', 'min:0', 'max:10'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'techniques.*.in' => CreateGenerationJobRequest::techniqueMessage($this->input('strategy')),
            'endpoint_ids.*.exists' => 'Endpoint không thuộc đặc tả của bộ kiểm thử này.',
            'strategy.enum' => 'Chiến lược sinh test phải là RULE, LLM hoặc HYBRID.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'strategy' => 'chiến lược sinh test',
            'endpoint_ids' => 'danh sách endpoint',
            'techniques' => 'kỹ thuật kiểm thử',
            'model' => 'mô hình LLM',
            'temperature' => 'nhiệt độ LLM',
            'seed' => 'hạt giống ngẫu nhiên',
            'max_cases_per_endpoint' => 'số case tối đa mỗi endpoint',
            'max_repair_attempts' => 'số lần tự sửa tối đa',
        ];
    }
}
