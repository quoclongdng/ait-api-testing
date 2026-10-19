<?php

namespace App\Http\Requests\ApiSpecs;

use App\Enums\ApiSpecFormat;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Requests\Concerns\DescribesSpecUploadErrors;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateApiSpecRequest extends FormRequest
{
    use DescribesSpecUploadErrors;

    /**
     * Determine if the user is authorized to make this request.
     *
     * Ownership is checked before validation so a non-owner always gets 403,
     * never a 422 from the upload rules (docs/DESIGN.md §9).
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            && $this->user()?->can('createSpec', $project) === true;
    }

    /**
     * Render authorization failures with the project-specific §6 message.
     */
    protected function failedAuthorization(): void
    {
        throw new ApiException(
            ErrorCode::Forbidden,
            'Bạn không có quyền tải lên đặc tả cho dự án này.'
        );
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->specFileWasSent('file')) {
            return [
                'version_label' => ['required', 'string', 'min:1', 'max:50'],
                'file' => [
                    'bail',
                    'required',
                    'file',
                    'max:5120', // 5 MB in KB
                    // An empty file has MIME application/x-empty: say it is empty, not "wrong format".
                    $this->rejectEmptySpecFile(),
                    'mimetypes:application/json,text/json,text/plain,application/yaml,text/yaml,text/x-yaml,application/x-yaml,application/schema+json',
                    'extensions:json,yaml,yml',
                ],
            ];
        }

        return [
            'version_label' => ['required', 'string', 'min:1', 'max:50'],
            'source_filename' => ['nullable', 'string', 'max:255'],
            'format' => ['required', Rule::enum(ApiSpecFormat::class)],
            'raw_content' => [
                'required',
                'string',
                'min:1',
                // §9 limit is 5 MB in bytes; Laravel's max: counts characters for strings,
                // which would let ~15 MB of multibyte UTF-8 through and get persisted.
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && strlen($value) > 5242880) {
                        $fail('Nội dung đặc tả không được vượt quá 5 MB.');
                    }
                },
            ],
        ];
    }

    /**
     * Vietnamese field names for messages without a custom text ("Trường nội dung đặc tả phải là
     * chuỗi."), so error.details never shows a raw key such as "raw content" (S13-03).
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'version_label' => 'nhãn phiên bản',
            'source_filename' => 'tên tệp gốc',
            'format' => 'định dạng',
            'raw_content' => 'nội dung đặc tả',
            'file' => 'tệp đặc tả',
        ];
    }

    /**
     * Get custom error messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'version_label.required' => 'Nhãn phiên bản là bắt buộc.',
            'version_label.max' => 'Nhãn phiên bản không được vượt quá 50 ký tự.',
            'format.required' => 'Định dạng đặc tả (json/yaml) là bắt buộc.',
            'raw_content.required' => 'Nội dung thô của đặc tả là bắt buộc.',
            'raw_content.max' => 'Nội dung đặc tả không được vượt quá 5 MB.',
            'file.uploaded' => $this->specUploadFailedMessage('file'),
            'file.required' => 'Tệp đặc tả là bắt buộc khi tải lên dạng multipart.',
            'file.max' => 'Kích thước tệp đặc tả không được vượt quá 5 MB.',
            'file.mimes' => 'Tệp đặc tả phải có định dạng .json, .yaml hoặc .yml.',
            'file.mimetypes' => 'Tệp đặc tả phải có định dạng .json, .yaml hoặc .yml.',
            'file.extensions' => 'Tệp đặc tả phải có phần mở rộng .json, .yaml hoặc .yml.',
        ];
    }
}
