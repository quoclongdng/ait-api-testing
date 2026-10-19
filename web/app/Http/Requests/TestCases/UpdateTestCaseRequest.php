<?php

namespace App\Http\Requests\TestCases;

use App\Models\TestCase;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTestCaseRequest extends FormRequest
{
    /** DSL decoded with JSON objects kept as stdClass (`{}` stays distinct from `[]`). */
    private ?\stdClass $dslObject = null;

    public function authorize(): bool
    {
        /** @var TestCase|null $testCase */
        $testCase = $this->route('testCase');

        return $testCase !== null && ($this->user()?->can('update', $testCase) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $rawDsl = $this->input('dsl');
        if (is_string($rawDsl)) {
            if (strlen($rawDsl) > 5242880) {
                return;
            }
            $decoded = json_decode($rawDsl, true);
            if (is_array($decoded)) {
                $this->merge(['dsl' => $decoded]);
                $object = json_decode($rawDsl, false);
                $this->dslObject = $object instanceof \stdClass ? $object : null;
            }
        } elseif (is_array($rawDsl) && $this->isJson()) {
            // Laravel decodes JSON bodies associatively; re-read the raw body to keep `{}` objects.
            $content = $this->getContent();
            $body = strlen($content) <= 6291456 ? json_decode($content, false) : null;
            if ($body instanceof \stdClass && ($body->dsl ?? null) instanceof \stdClass) {
                $this->dslObject = $body->dsl;
            }
        }

        if (is_string($this->input('title'))) {
            $this->merge(['title' => trim($this->input('title'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Optional: when omitted the action keeps the DSL title (contract TestCasePatch); an empty title is rejected.
            // A changed title is written into dsl.title (S14-06 live QA r7), so it has the DSL schema's
            // length bounds (3-255) and fails here on the title field rather than as a DSL error.
            'title' => ['sometimes', 'required', 'string', 'min:3', 'max:255'],
            'dsl' => [
                'required',
                'array',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $encoded = json_encode($value);
                    if (is_string($encoded) && strlen($encoded) > 5242880) {
                        $fail('Nội dung DSL không được vượt quá 5MB.');
                    }
                },
            ],
        ];
    }

    /**
     * The validated DSL with its JSON object shape preserved, for validation, fingerprint and storage
     * (an associative array would turn `"query": {}` into `[]` and fail the DSL schema).
     */
    public function dslObject(): \stdClass
    {
        if ($this->dslObject !== null) {
            return $this->dslObject;
        }

        $object = json_decode((string) json_encode($this->validated('dsl')), false);

        return $object instanceof \stdClass ? $object : new \stdClass;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'tiêu đề test case',
            'dsl' => 'nội dung DSL',
        ];
    }
}
