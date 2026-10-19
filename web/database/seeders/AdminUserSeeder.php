<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    public const EMAIL = 'admin@ait.local';

    /**
     * Run the database seeds.
     *
     * The password comes from ADMIN_DEFAULT_PASSWORD. When it is empty, a random password
     * is generated and printed once, so the bootstrap ADMIN never has a well-known password.
     */
    public function run(): void
    {
        // ADR-0007: production seeds no account; the operator runs php artisan ait:admin:create.
        if (app()->environment('production')) {
            $this->command->warn('APP_ENV=production: không tạo tài khoản '.self::EMAIL.'. Dùng php artisan ait:admin:create --email=<email>.');

            return;
        }

        if (User::query()->where('email', self::EMAIL)->exists()) {
            return;
        }

        $configured = config('ait.admin_default_password');
        $password = is_string($configured) ? $configured : '';
        $generated = $password === '';

        if ($generated) {
            $password = Str::password(24, symbols: false);
        }

        $admin = new User;
        $admin->forceFill([
            'email' => self::EMAIL,
            'name' => 'Administrator',
            'password' => Hash::make($password),
            'role' => UserRole::Admin,
            'is_active' => true,
        ])->save();

        if ($generated) {
            $this->command->warn(
                'ADMIN_DEFAULT_PASSWORD is empty; generated password for '.self::EMAIL.': '.$password
            );
        }
    }
}
