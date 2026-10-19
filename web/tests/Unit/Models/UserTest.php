<?php

namespace Tests\Unit\Models;

use App\Enums\UserRole;
use App\Models\User;
use Tests\TestCase;

class UserTest extends TestCase
{
    public function test_user_role_enum_values(): void
    {
        $this->assertSame('ADMIN', UserRole::Admin->value);
        $this->assertSame('TESTER', UserRole::Tester->value);
    }

    public function test_user_role_helpers_and_casts(): void
    {
        $admin = new User([
            'name' => 'Admin User',
            'email' => 'admin@ait.local',
            'password' => 'secret',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($admin->isTester());
        $this->assertTrue($admin->is_active);

        $tester = new User([
            'name' => 'Tester User',
            'email' => 'tester@ait.local',
            'password' => 'secret',
            'role' => UserRole::Tester,
            'is_active' => false,
        ]);

        $this->assertSame(UserRole::Tester, $tester->role);
        $this->assertFalse($tester->isAdmin());
        $this->assertTrue($tester->isTester());
        $this->assertFalse($tester->is_active);
    }
}
