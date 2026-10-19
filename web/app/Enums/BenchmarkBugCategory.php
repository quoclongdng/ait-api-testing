<?php

namespace App\Enums;

enum BenchmarkBugCategory: string
{
    case ValidationMissing = 'VALIDATION_MISSING';
    case StatusCodeWrong = 'STATUS_CODE_WRONG';
    case SchemaMismatch = 'SCHEMA_MISMATCH';
    case BoundaryError = 'BOUNDARY_ERROR';
    case BusinessRuleViolation = 'BUSINESS_RULE_VIOLATION';
    case SequenceError = 'SEQUENCE_ERROR';
    case AuthError = 'AUTH_ERROR';

    /** Vietnamese label shown in the UI (§8, §11 bug categories). */
    public function label(): string
    {
        return match ($this) {
            self::ValidationMissing => 'Thiếu validation',
            self::StatusCodeWrong => 'Sai mã trạng thái',
            self::SchemaMismatch => 'Sai schema response',
            self::BoundaryError => 'Lỗi biên',
            self::BusinessRuleViolation => 'Vi phạm business rule',
            self::SequenceError => 'Lỗi chuỗi nhiều bước',
            self::AuthError => 'Lỗi phân quyền',
        };
    }
}
