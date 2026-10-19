<?php

namespace App\Enums;

enum GenerationJobStatus: string
{
    case Queued = 'QUEUED';
    case Running = 'RUNNING';
    case Succeeded = 'SUCCEEDED';
    case Partial = 'PARTIAL';
    case Failed = 'FAILED';

    /** Vietnamese label shown in the UI (§8). */
    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Đang chờ',
            self::Running => 'Đang chạy',
            self::Succeeded => 'Thành công',
            self::Partial => 'Thành công một phần',
            self::Failed => 'Thất bại',
        };
    }
}
