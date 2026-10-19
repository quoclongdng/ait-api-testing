<?php

namespace App\Http\Requests\AllowedTargets;

use App\Models\AllowedTarget;
use App\Rules\HostOrIp;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreAllowedTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AllowedTarget::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $host = $this->input('host');

        $this->merge([
            'host' => is_string($host) ? strtolower(rtrim(trim($host), '.')) : $host,
            'port' => $this->input('port') === '' ? null : $this->input('port'),
            'note' => $this->input('note') === '' ? null : $this->input('note'),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // bail: a non-string host (crafted API payload) gets one message, not the "string" rule's
            // and HostOrIp's near-duplicates (QA S14-06 round 3).
            'host' => ['bail', 'required', 'string', 'max:255', new HostOrIp],
            'port' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'host.string' => HostOrIp::MESSAGE_NOT_STRING,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'host' => 'host hoặc địa chỉ IP',
            'port' => 'cổng',
            'note' => 'ghi chú',
        ];
    }
}
