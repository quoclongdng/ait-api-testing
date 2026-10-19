<?php

namespace App\Enums;

enum TestTechnique: string
{
    case HappyPath = 'HAPPY_PATH';
    case Boundary = 'BOUNDARY';
    case Equivalence = 'EQUIVALENCE';
    case InvalidInput = 'INVALID_INPUT';
    case MissingRequired = 'MISSING_REQUIRED';
    case TypeMismatch = 'TYPE_MISMATCH';
    case Auth = 'AUTH';
    case BusinessRule = 'BUSINESS_RULE';
    case Sequence = 'SEQUENCE';

    /** Vietnamese label shown in the UI (§8); the code stays in title/filters. */
    public function label(): string
    {
        return match ($this) {
            self::HappyPath => 'Luồng chuẩn',
            self::Boundary => 'Giá trị biên',
            self::Equivalence => 'Phân vùng tương đương',
            self::InvalidInput => 'Đầu vào không hợp lệ',
            self::MissingRequired => 'Thiếu trường bắt buộc',
            self::TypeMismatch => 'Sai kiểu dữ liệu',
            self::Auth => 'Xác thực & phân quyền',
            self::BusinessRule => 'Ràng buộc nghiệp vụ',
            self::Sequence => 'Chuỗi nhiều bước',
        };
    }
}
