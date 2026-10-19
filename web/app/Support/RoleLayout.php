<?php

namespace App\Support;

use App\Models\User;

/**
 * Application shell of the signed-in actor (S14-04). Screens shared by both roles extend
 * `layouts.app`, which resolves here: an ADMIN gets the admin shell (indigo, "Khu vực quản trị",
 * admin links), anyone else the tester shell (teal, "Không gian kiểm thử", no admin links).
 * Admin-only screens (admin/*) extend `layouts.admin` directly.
 */
final class RoleLayout
{
    public const ADMIN = 'layouts.admin';

    public const TESTER = 'layouts.tester';

    public static function for(?User $user): string
    {
        return $user?->isAdmin() === true ? self::ADMIN : self::TESTER;
    }

    /**
     * Accent colour of that shell (indigo: ADMIN, teal: TESTER), so content shared by both shells
     * (dashboard links, run tables) follows the shell it is rendered in.
     *
     * @return 'indigo'|'teal'
     */
    public static function accent(?User $user): string
    {
        return $user?->isAdmin() === true ? 'indigo' : 'teal';
    }
}
