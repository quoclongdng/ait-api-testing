<?php

namespace App\Http\Requests\Experiments;

use App\Enums\ReportFormat;
use App\Models\Experiment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportExperimentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $experiment = $this->route('experiment');

        return $experiment instanceof Experiment && ($this->user()?->can('view', $experiment) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'format' => ['required', 'string', Rule::in([ReportFormat::Json->value, ReportFormat::Csv->value])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'format.required' => 'Vui lòng chọn định dạng xuất (json hoặc csv).',
            'format.in' => 'Định dạng xuất không hợp lệ. Chỉ hỗ trợ json hoặc csv.',
        ];
    }

    public function exportFormat(): ReportFormat
    {
        return ReportFormat::from($this->string('format')->toString());
    }
}
