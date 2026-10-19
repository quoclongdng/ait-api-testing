<?php

namespace App\Enums;

enum LlmPurpose: string
{
    case Generate = 'GENERATE';
    case Repair = 'REPAIR';

    /** Vietnamese label shown in the UI (§8). */
    public function label(): string
    {
        return match ($this) {
            self::Generate => 'Sinh test',
            self::Repair => 'Tự sửa',
        };
    }
}
