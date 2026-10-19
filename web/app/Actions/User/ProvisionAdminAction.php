<?php

namespace App\Actions\User;

use App\Enums\ErrorCode;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Bootstrap ADMIN account for a deployment (ADR-0007, `php artisan ait:admin:create`).
 *
 * Production has no seeded or default account: the operator creates the first ADMIN on the server
 * terminal. The password is either supplied (piped on stdin) or generated here; it is returned so
 * the command can print it once, and is never logged or stored in clear.
 */
class ProvisionAdminAction
{
    public const MIN_PASSWORD_LENGTH = 12;

    public const GENERATED_PASSWORD_LENGTH = 24;

    /**
     * @return array{user: User, created: bool, password: string, generated: bool}
     *
     * @throws ApiException VALIDATION_FAILED (bad email/password) or CONFLICT (account exists without $resetPassword)
     */
    public function execute(string $email, ?string $password, bool $resetPassword = false, string $name = 'Administrator'): array
    {
        $email = Str::lower(trim($email));
        $generated = $password === null || $password === '';
        $password = $generated ? Str::password(self::GENERATED_PASSWORD_LENGTH, symbols: false) : (string) $password;

        $validator = Validator::make(
            ['email' => $email, 'password' => $password, 'name' => $name],
            [
                'email' => ['required', 'string', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:'.self::MIN_PASSWORD_LENGTH, 'max:255'],
                'name' => ['required', 'string', 'max:255'],
            ],
            [
                'email.email' => 'Email không hợp lệ.',
                'password.min' => 'Mật khẩu phải có ít nhất '.self::MIN_PASSWORD_LENGTH.' ký tự.',
            ],
        );
        if ($validator->fails()) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                (string) $validator->errors()->first(),
                $validator->errors()->toArray(),
            );
        }

        return DB::transaction(function () use ($email, $password, $generated, $resetPassword, $name): array {
            $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();

            if ($user !== null && ! $resetPassword) {
                throw new ApiException(
                    ErrorCode::Conflict,
                    "Tài khoản {$email} đã tồn tại. Dùng --reset-password để đặt lại mật khẩu (và vai trò ADMIN).",
                    ['email' => ["Tài khoản {$email} đã tồn tại."]],
                );
            }

            $created = $user === null;
            $user ??= new User(['email' => $email, 'name' => $name]);
            $user->forceFill([
                'password' => Hash::make($password),
                'role' => UserRole::Admin,
                'is_active' => true,
                // A reset also invalidates every "remember me" cookie issued with the old password.
                'remember_token' => Str::random(60),
            ])->save();

            return ['user' => $user, 'created' => $created, 'password' => $password, 'generated' => $generated];
        });
    }
}
