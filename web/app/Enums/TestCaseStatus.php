<?php

namespace App\Enums;

enum TestCaseStatus: string
{
    case Valid = 'VALID';
    case Invalid = 'INVALID';
    case Duplicate = 'DUPLICATE';
    case Approved = 'APPROVED';
    case Archived = 'ARCHIVED';

    /** Vietnamese label shown in the UI (§8); the code stays in title/filters. */
    public function label(): string
    {
        return match ($this) {
            self::Valid => 'Hợp lệ',
            self::Invalid => 'Không hợp lệ',
            self::Duplicate => 'Trùng lặp',
            self::Approved => 'Đã duyệt',
            self::Archived => 'Đã lưu trữ',
        };
    }
}
