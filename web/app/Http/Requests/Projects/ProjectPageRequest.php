<?php

namespace App\Http\Requests\Projects;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Pagination of a project's child collection (GET /projects/{id}/business-rules, /suites).
 * Authorization is done in the controller with the resource's own policy.
 */
class ProjectPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('project') instanceof Project;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
