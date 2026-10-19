<?php

namespace App\Enums;

enum TestRunStatus: string
{
    case Queued = 'QUEUED';
    case Running = 'RUNNING';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';

    /** Vietnamese label shown in the UI and exports (§8). */
    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Đang chờ',
            self::Running => 'Đang chạy',
            self::Completed => 'Hoàn thành',
            self::Failed => 'Thất bại',
            self::Cancelled => 'Đã hủy',
        };
    }

    /** True once the run reached a terminal state (§4.3) and its report is final. */
    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }
}
