<?php

namespace App\Actions\BusinessRules;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\BusinessRule;
use App\Models\Project;
use Illuminate\Database\UniqueConstraintViolationException;

class CreateBusinessRuleAction
{
    /**
     * @param  array{code: string, endpoint_id?: int|null, description: string}  $data
     */
    public function execute(Project $project, array $data): BusinessRule
    {
        if ($project->businessRules()->where('code', $data['code'])->exists()) {
            throw $this->duplicate($project->id, $data['code']);
        }

        try {
            return $project->businessRules()->create([
                'code' => $data['code'],
                'endpoint_id' => $data['endpoint_id'] ?? null,
                'description' => $data['description'],
            ]);
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicate($project->id, $data['code']);
        }
    }

    private function duplicate(int $projectId, string $code): ApiException
    {
        return new ApiException(
            ErrorCode::Conflict,
            'Mã quy tắc này đã tồn tại trong dự án.',
            ['code' => ["Mã quy tắc {$code} đã tồn tại trong dự án #{$projectId}."]],
        );
    }
}
