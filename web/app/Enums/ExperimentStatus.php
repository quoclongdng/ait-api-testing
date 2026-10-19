<?php

namespace App\Enums;

enum ExperimentStatus: string
{
    case Planned = 'PLANNED';
    case Running = 'RUNNING';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';

    /** Vietnamese label shown in the UI (§8); COMPLETED reads like TestRunStatus (S14-06). */
    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Đã lập kế hoạch',
            self::Running => 'Đang chạy',
            self::Completed => 'Hoàn thành',
            self::Failed => 'Thất bại',
        };
    }
}
