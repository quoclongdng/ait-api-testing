<?php

namespace App\Console\Commands;

use App\Actions\User\ProvisionAdminAction;
use App\Exceptions\ApiException;
use Illuminate\Console\Command;

/**
 * php artisan ait:admin:create --email=<email> [--name=<name>] [--reset-password]
 *
 * Creates the bootstrap ADMIN of a deployment (ADR-0007): production seeds no account. A password
 * piped on stdin (`printf '%s' "$PW" | php artisan ait:admin:create --email=...`) is used as is;
 * otherwise a random one is generated and printed ONCE on the terminal. The password is never
 * logged (no Log call, no exception message carries it).
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'ait:admin:create
        {--email= : Email đăng nhập của ADMIN}
        {--name=Administrator : Tên hiển thị}
        {--reset-password : Tài khoản đã tồn tại thì đặt lại mật khẩu, vai trò ADMIN và mở khóa}';

    protected $description = 'Tạo tài khoản ADMIN (mật khẩu đọc từ stdin hoặc sinh ngẫu nhiên, in ra một lần)';

    /**
     * Stream the piped password is read from; tests replace it. Null = php://stdin.
     *
     * @var resource|null
     */
    public static $stdin = null;

    /** Seconds to wait for piped data on a non-interactive stdin before generating a password. */
    private const STDIN_WAIT_SECONDS = 1;

    public function handle(ProvisionAdminAction $action): int
    {
        $email = trim((string) $this->option('email'));
        if ($email === '') {
            $this->error('Thiếu --email=<email>.');

            return self::FAILURE;
        }

        try {
            $result = $action->execute(
                $email,
                $this->pipedPassword(),
                (bool) $this->option('reset-password'),
                trim((string) $this->option('name')) ?: 'Administrator',
            );
        } catch (ApiException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(($result['created'] ? 'Đã tạo' : 'Đã đặt lại mật khẩu cho').' ADMIN '.$result['user']->email.'.');
        if ($result['generated']) {
            // Printed once on the operator's terminal only; store it in a password manager now.
            $this->line('Mật khẩu (chỉ hiển thị một lần): '.$result['password']);
            $this->warn('Hãy lưu mật khẩu này ngay; hệ thống không lưu bản rõ và không thể hiển thị lại.');
        } else {
            $this->line('Đã dùng mật khẩu nhận từ stdin.');
        }

        return self::SUCCESS;
    }

    /**
     * First line piped on stdin, or null when stdin is an interactive terminal / empty / silent.
     * A terminal is never read (no blocking prompt); a pipe is read only if data arrives quickly.
     */
    private function pipedPassword(): ?string
    {
        $stream = self::$stdin ?? (defined('STDIN') ? STDIN : null);
        if (! is_resource($stream)) {
            return null;
        }
        if (self::$stdin === null) {
            if (stream_isatty($stream)) {
                return null;
            }
            $read = [$stream];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, self::STDIN_WAIT_SECONDS) !== 1) {
                return null;
            }
        }

        $line = fgets($stream);
        if ($line === false) {
            return null;
        }
        $password = rtrim($line, "\r\n");

        return $password === '' ? null : $password;
    }
}
