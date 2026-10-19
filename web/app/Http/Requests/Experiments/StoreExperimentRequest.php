<?php

namespace App\Http\Requests\Experiments;

use App\Enums\GenerationStrategy;
use App\Models\Experiment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExperimentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Experiment::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'benchmark_id' => ['required', 'integer', 'exists:benchmarks,id'],
            'strategy' => ['required', 'string', Rule::enum(GenerationStrategy::class)],
            'config' => ['required', 'array:model,seed,seeds,temperature,repetitions'],
            'config.model' => ['sometimes', 'nullable', 'string', 'max:100'],
            'config.seed' => ['required', 'integer', 'min:0', 'max:2147483000'],
            'config.seeds' => ['sometimes', 'nullable', 'array', 'min:1', 'max:10'],
            'config.seeds.*' => ['required', 'integer', 'distinct', 'min:0', 'max:2147483000'],
            'config.temperature' => ['required', 'numeric', 'min:0', 'max:2'],
            'config.repetitions' => ['required', 'integer', 'min:3', 'max:10'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'tên thực nghiệm',
            'benchmark_id' => 'benchmark',
            'strategy' => 'chiến lược',
            'config' => 'cấu hình',
            'config.model' => 'model',
            'config.seed' => 'seed',
            'config.seeds' => 'danh sách seed',
            'config.seeds.*' => 'seed',
            'config.temperature' => 'temperature',
            'config.repetitions' => 'số lần lặp',
        ];
    }
}
