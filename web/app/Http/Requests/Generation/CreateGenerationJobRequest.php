<?php

namespace App\Http\Requests\Generation;

use App\Enums\GenerationStrategy;
use App\Enums\TestTechnique;
use App\Models\GenerationJob;
use App\Models\TestSuite;
use App\Services\Generation\RuleGenerator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/suites/{suite}/generation-jobs — body GenerationJobCreate (web-api.openapi.yaml).
 */
class CreateGenerationJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        $suite = $this->route('suite');

        return $suite instanceof TestSuite
            && ($this->user()?->can('create', [GenerationJob::class, $suite]) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isRule = $this->input('strategy') === GenerationStrategy::Rule->value;
        $techniques = $isRule
            ? array_map(static fn (TestTechnique $t): string => $t->value, RuleGenerator::SUPPORTED_TECHNIQUES)
            : array_map(static fn (TestTechnique $t): string => $t->value, TestTechnique::cases());

        return [
            'strategy' => ['required', 'string', Rule::enum(GenerationStrategy::class)],
            'params' => ['required', 'array:endpoint_ids,techniques,max_cases_per_endpoint,model,temperature,seed,max_repair_attempts'],
            'params.endpoint_ids' => ['required', 'array', 'min:1'],
            'params.endpoint_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'params.techniques' => ['sometimes', 'array'],
            'params.techniques.*' => ['required', 'string', Rule::in($techniques), 'distinct'],
            // bail: "abc" is reported once as "not a number", not also as out of range (S14-06).
            'params.max_cases_per_endpoint' => ['sometimes', 'bail', 'integer', 'min:1', 'max:100'],
            'params.model' => ['sometimes', 'nullable', 'string', 'max:100'],
            'params.temperature' => ['sometimes', 'bail', 'numeric', 'min:0', 'max:2'],
            'params.seed' => ['sometimes', 'nullable', 'bail', 'integer', 'min:0'],
            'params.max_repair_attempts' => ['sometimes', 'bail', 'integer', 'min:0', 'max:10'],
        ];
    }

    /**
     * Vietnamese messages for every rule (docs/DESIGN.md §8): M11 shows error.details verbatim and the
     * repo ships no `lang/vi` translations, so the framework defaults would be English.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'strategy.required' => 'Vui lòng chọn chiến lược sinh test.',
            'strategy.string' => 'Chiến lược sinh test phải là RULE, LLM hoặc HYBRID.',
            'strategy.enum' => 'Chiến lược sinh test phải là RULE, LLM hoặc HYBRID.',
            'params.required' => 'Thiếu cấu hình sinh test (params).',
            'params.array' => 'Cấu hình sinh test (params) chứa trường không được hỗ trợ.',
            'params.endpoint_ids.required' => 'Vui lòng chọn ít nhất một endpoint.',
            'params.endpoint_ids.array' => 'Danh sách endpoint không hợp lệ.',
            'params.endpoint_ids.min' => 'Vui lòng chọn ít nhất một endpoint.',
            'params.endpoint_ids.*.required' => 'Mã endpoint không được để trống.',
            'params.endpoint_ids.*.integer' => 'Mã endpoint phải là số nguyên.',
            'params.endpoint_ids.*.min' => 'Mã endpoint phải là số nguyên dương.',
            'params.endpoint_ids.*.distinct' => 'Danh sách endpoint có phần tử bị trùng.',
            'params.techniques.array' => 'Danh sách kỹ thuật kiểm thử không hợp lệ.',
            'params.techniques.*.required' => 'Kỹ thuật kiểm thử không được để trống.',
            'params.techniques.*.string' => 'Kỹ thuật kiểm thử không hợp lệ.',
            'params.techniques.*.in' => self::techniqueMessage($this->input('strategy')),
            'params.techniques.*.distinct' => 'Danh sách kỹ thuật kiểm thử có phần tử bị trùng.',
            'params.max_cases_per_endpoint.integer' => 'Số case tối đa mỗi endpoint phải là số nguyên.',
            'params.max_cases_per_endpoint.min' => 'Số case tối đa mỗi endpoint phải từ 1 đến 100.',
            'params.max_cases_per_endpoint.max' => 'Số case tối đa mỗi endpoint phải từ 1 đến 100.',
            'params.model.string' => 'Mô hình LLM phải là chuỗi ký tự.',
            'params.model.max' => 'Tên mô hình LLM không được dài quá 100 ký tự.',
            'params.temperature.numeric' => 'Nhiệt độ LLM phải là số.',
            'params.temperature.min' => 'Nhiệt độ LLM phải nằm trong khoảng 0 đến 2.',
            'params.temperature.max' => 'Nhiệt độ LLM phải nằm trong khoảng 0 đến 2.',
            'params.seed.integer' => 'Seed phải là số nguyên.',
            'params.seed.min' => 'Seed phải là số nguyên không âm.',
            'params.max_repair_attempts.integer' => 'Số lần tự sửa tối đa phải là số nguyên.',
            'params.max_repair_attempts.min' => 'Số lần tự sửa tối đa phải từ 0 đến 10.',
            'params.max_repair_attempts.max' => 'Số lần tự sửa tối đa phải từ 0 đến 10.',
        ];
    }

    /**
     * Message for a technique outside the allowed list of the chosen strategy (S14-06): the RULE
     * wording ("RULE chỉ dùng 6 kỹ thuật luật") was also shown for LLM/HYBRID.
     */
    public static function techniqueMessage(mixed $strategy): string
    {
        if ($strategy === GenerationStrategy::Rule->value) {
            return 'Kỹ thuật kiểm thử không được hỗ trợ cho chiến lược RULE (chỉ nhận: '
                .implode(', ', array_map(static fn (TestTechnique $t): string => $t->value, RuleGenerator::SUPPORTED_TECHNIQUES)).').';
        }

        return 'Kỹ thuật kiểm thử không hợp lệ (chỉ nhận: '
            .implode(', ', array_map(static fn (TestTechnique $t): string => $t->value, TestTechnique::cases())).').';
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'strategy' => 'chiến lược sinh test',
            'params' => 'cấu hình sinh test',
            'params.endpoint_ids' => 'danh sách endpoint',
            'params.endpoint_ids.*' => 'mã endpoint',
            'params.techniques' => 'kỹ thuật kiểm thử',
            'params.techniques.*' => 'kỹ thuật kiểm thử',
            'params.max_cases_per_endpoint' => 'số case tối đa mỗi endpoint',
            'params.model' => 'mô hình LLM',
            'params.temperature' => 'nhiệt độ LLM',
            'params.seed' => 'hạt giống ngẫu nhiên',
            'params.max_repair_attempts' => 'số lần tự sửa tối đa',
        ];
    }
}
