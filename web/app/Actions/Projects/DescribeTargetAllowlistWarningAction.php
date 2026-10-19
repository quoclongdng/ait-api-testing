<?php

namespace App\Actions\Projects;

use App\Services\Execution\TargetAllowlistValidator;

/**
 * M05: a project's default target is only a pre-filled value for M12, so any http(s) URL may be
 * saved, but one outside the effective allowlist (ALLOWED_TARGET_HOSTS ∪ allowed_targets, D07)
 * would later be refused with TARGET_NOT_ALLOWED. This returns the Vietnamese warning shown right
 * after saving such a URL, or null when the target is allowed or empty (S13-03).
 */
class DescribeTargetAllowlistWarningAction
{
    public function __construct(private readonly TargetAllowlistValidator $allowlist) {}

    public function execute(?string $url): ?string
    {
        if ($url === null || $url === '' || $this->allowlist->isAllowed($url)) {
            return null;
        }

        return "Target mặc định {$url} chưa thuộc danh sách host được phép (allowlist). Lần chạy tới target này sẽ bị từ chối; hãy nhờ quản trị viên thêm host vào allowlist hoặc chọn target khác.";
    }
}
