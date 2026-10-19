<?php

namespace App\Actions\BusinessRules;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\BusinessRule;
use Illuminate\Database\UniqueConstraintViolationException;

class UpdateBusinessRuleAction
{
    /**
     * @param  array{code?: string, endpoint_id?: int|null, description?: string}  $data
     */
    public function execute(BusinessRule $rule, array $data): BusinessRule
    {
        if (isset($data['code']) && BusinessRule::query()
            ->where('project_id', $rule->project_id)
            ->where('code', $data['code'])
            ->whereKeyNot($rule->id)
            ->exists()) {
            throw $this->duplicate($rule->project_id, $data['code']);
        }

        try {
            $rule->update(array_intersect_key($data, array_flip(['code', 'endpoint_id', 'description'])));
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicate($rule->project_id, $data['code'] ?? $rule->code);
        }

        return $rule->refresh();
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
