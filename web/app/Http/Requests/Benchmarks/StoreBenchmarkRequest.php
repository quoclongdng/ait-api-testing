<?php

namespace App\Http\Requests\Benchmarks;

use App\Models\Benchmark;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBenchmarkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Benchmark::class) ?? false;
    }

    /**
     * Validate the stored spelling of the version ("1.0" -> "v1.0", S14-06), so `max:20` matches
     * the column and the 409 message names the canonical version.
     */
    protected function prepareForValidation(): void
    {
        $version = $this->input('version');
        if (is_string($version) && trim($version) !== '') {
            $this->merge(['version' => Benchmark::normalizeVersion($version)]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'base_url' => ['required', 'string', 'max:500', 'url:http,https'],
            'version' => ['required', 'string', 'min:1', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'slug' => 'mã benchmark',
            'name' => 'tên benchmark',
            'description' => 'mô tả',
            'base_url' => 'base URL',
            'version' => 'phiên bản',
        ];
    }
}
