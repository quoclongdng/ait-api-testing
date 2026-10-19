<?php

namespace App\Http\Requests\Specs;

use App\Enums\ApiSpecFormat;
use App\Http\Requests\Concerns\DescribesSpecUploadErrors;
use App\Models\Project;
use App\Services\OpenApi\OpenApiParser;
use Illuminate\Foundation\Http\FormRequest;

class UploadSpecRequest extends FormRequest
{
    use DescribesSpecUploadErrors;

    /**
     * Determine if the user is authorized to make this request.
     *
     * Ownership is checked before validation so a non-owner always gets 403,
     * never validation errors for the upload (docs/DESIGN.md §9).
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            && $this->user()?->can('createSpec', $project) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'version_label' => ['required', 'string', 'min:1', 'max:50'],
            'input_method' => ['required', 'in:file,text'],
            'spec_file' => [
                'bail',
                'required_if:input_method,file',
                'nullable',
                'file',
                'max:5120', // 5 MB
                'extensions:json,yaml,yml',
                // An empty file has MIME application/x-empty: say it is empty, not "wrong format".
                $this->rejectEmptySpecFile(),
                'mimetypes:application/json,text/json,text/plain,application/yaml,text/yaml,text/x-yaml,application/x-yaml,application/schema+json',
            ],
            'spec_text' => [
                'required_if:input_method,text',
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && strlen($value) > 5242880) {
                        $fail('Nội dung đặc tả không được vượt quá 5MB.');
                    }
                },
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'version_label.required' => 'Nhãn phiên bản là bắt buộc.',
            'version_label.max' => 'Nhãn phiên bản không được vượt quá 50 ký tự.',
            'input_method.required' => 'Phương thức nhập đặc tả là bắt buộc.',
            'input_method.in' => 'Phương thức nhập không hợp lệ.',
            'spec_file.required_if' => 'Vui lòng chọn một tệp tin OpenAPI hợp lệ (.json, .yaml, .yml).',
            'spec_file.uploaded' => $this->specUploadFailedMessage('spec_file'),
            'spec_file.max' => 'Kích thước tệp tin không được vượt quá 5MB.',
            'spec_file.extensions' => 'Tệp tin phải có phần mở rộng .json, .yaml hoặc .yml.',
            'spec_file.mimetypes' => 'Tệp tin phải có định dạng .json, .yaml hoặc .yml.',
            'spec_text.required_if' => 'Vui lòng dán nội dung đặc tả JSON hoặc YAML.',
            'spec_text.max' => 'Nội dung đặc tả không được vượt quá 5MB.',
        ];
    }

    /**
     * Get the raw text content of the uploaded specification.
     */
    public function getRawContent(): string
    {
        if ($this->input('input_method') === 'file' && $this->hasFile('spec_file')) {
            $file = $this->file('spec_file');
            if ($file && $file->isValid()) {
                $content = file_get_contents($file->getRealPath());

                return $content !== false ? $content : '';
            }
        }

        // A browser submits a <textarea> with CRLF line breaks whatever was pasted (HTML form
        // submission), so the pasted text of an LF file would never match that file's checksum.
        // Restore the LF line breaks; nothing else is changed (no trimming, S14-06).
        return str_replace("\r\n", "\n", (string) $this->input('spec_text', ''));
    }

    /**
     * Get the source filename of the specification.
     */
    public function getSourceFilename(): string
    {
        if ($this->input('input_method') === 'file' && $this->hasFile('spec_file')) {
            $file = $this->file('spec_file');
            if ($file) {
                return $file->getClientOriginalName();
            }
        }

        $raw = trim(OpenApiParser::withoutBom($this->getRawContent()));
        if (str_starts_with($raw, '{')) {
            return 'pasted-specification.json';
        }

        return 'pasted-specification.yaml';
    }

    /**
     * Detect or resolve the specification format.
     */
    public function resolveFormat(): ?ApiSpecFormat
    {
        $raw = trim(OpenApiParser::withoutBom($this->getRawContent()));
        if (str_starts_with($raw, '{')) {
            return ApiSpecFormat::Json;
        }

        $filename = strtolower($this->getSourceFilename());
        if (str_ends_with($filename, '.json')) {
            return ApiSpecFormat::Json;
        }

        return ApiSpecFormat::Yaml;
    }
}
