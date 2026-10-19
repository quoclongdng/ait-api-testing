<?php

namespace App\Http\Requests\Runs;

use App\Enums\ReportFormat;
use App\Models\TestRun;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportRunReportRequest extends FormRequest
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
            'format' => ['required', 'string', Rule::enum(ReportFormat::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'format.required' => 'Vui lòng chọn định dạng xuất (json, csv hoặc html).',
            'format.enum' => 'Định dạng xuất không hợp lệ. Chỉ hỗ trợ json, csv hoặc html.',
        ];
    }

    public function reportFormat(): ReportFormat
    {
        return ReportFormat::from($this->string('format')->toString());
    }
}
