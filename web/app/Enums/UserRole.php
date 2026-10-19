<?php

namespace App\Enums;

/**
 * User roles within the platform.
 * Source of truth: docs/DESIGN.md §1 and docs/contracts/db-schema.md.
 */
enum UserRole: string
{
    case Admin = 'ADMIN';
    case Tester = 'TESTER';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Quản trị viên',
            self::Tester => 'Kiểm thử viên',
        };
    }
}
