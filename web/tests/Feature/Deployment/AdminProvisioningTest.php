<?php

namespace Tests\Feature\Deployment;

use App\Console\Commands\CreateAdminCommand;
use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Console\Seeds\SeedCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * ADR-0007: no default/demo account in production; the first ADMIN comes from ait:admin:create.
 */
class AdminProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CreateAdminCommand::$stdin = null;
        parent::tearDown();
    }

    public function test_generates_a_password_prints_it_once_and_never_logs_it(): void
    {
        $log = Log::spy();
        $this->pipe('');

        $exit = Artisan::call('ait:admin:create', ['--email' => 'Owner@Example.test']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertSame(1, preg_match('/Mật khẩu \(chỉ hiển thị một lần\): (\S+)/u', $output, $match));
        $password = $match[1] ?? '';
        $this->assertSame(24, strlen($password));
        $this->assertSame(1, substr_count($output, $password));

        $user = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check($password, $user->password));
        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency', 'log'] as $level) {
            $log->shouldNotHaveReceived($level);
        }
    }

    public function test_uses_a_password_piped_on_stdin(): void
    {
        $this->pipe("piped-secret-password-1\n");

        $exit = Artisan::call('ait:admin:create', ['--email' => 'ops@example.test', '--name' => 'Ops']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString('piped-secret-password-1', $output);
        $user = User::query()->where('email', 'ops@example.test')->firstOrFail();
        $this->assertSame('Ops', $user->name);
        $this->assertTrue(Hash::check('piped-secret-password-1', $user->password));
    }

    public function test_refuses_a_short_piped_password_and_an_invalid_email(): void
    {
        $this->pipe("short\n");
        $this->assertSame(1, Artisan::call('ait:admin:create', ['--email' => 'ops@example.test']));
        $this->assertStringContainsString('ít nhất 12 ký tự', Artisan::output());

        $this->pipe('');
        $this->assertSame(1, Artisan::call('ait:admin:create', ['--email' => 'not-an-email']));
        $this->assertSame(1, Artisan::call('ait:admin:create'));
        $this->assertSame(0, User::query()->count());
    }

    public function test_refuses_an_existing_account_unless_reset_password(): void
    {
        $existing = User::factory()->tester()->create(['email' => 'ops@example.test', 'password' => Hash::make('old-password-123'), 'is_active' => false]);

        $this->pipe('');
        $this->assertSame(1, Artisan::call('ait:admin:create', ['--email' => 'OPS@example.test']));
        $this->assertStringContainsString('đã tồn tại', Artisan::output());
        $this->assertTrue(Hash::check('old-password-123', $existing->refresh()->password));

        $this->pipe("brand-new-password-9\n");
        $this->assertSame(0, Artisan::call('ait:admin:create', ['--email' => 'ops@example.test', '--reset-password' => true]));
        $existing->refresh();
        $this->assertTrue(Hash::check('brand-new-password-9', $existing->password));
        $this->assertSame(UserRole::Admin, $existing->role);
        $this->assertTrue($existing->is_active);
        $this->assertSame(1, User::query()->count());
    }

    public function test_production_seeding_creates_no_account(): void
    {
        $this->app['env'] = 'production';

        $this->assertSame(0, Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]));

        $this->assertSame(0, User::query()->count());
        $this->assertDatabaseMissing('users', ['email' => AdminUserSeeder::EMAIL]);
    }

    public function test_demo_seeder_refuses_production_without_force(): void
    {
        $this->app['env'] = 'production';
        $output = new BufferedOutput;
        $command = new SeedCommand($this->app['db']);
        $command->setLaravel($this->app);
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));
        $command->setInput(new ArrayInput([], $command->getDefinition()));

        $seeder = $this->app->make(DemoSeeder::class);
        $seeder->setContainer($this->app)->setCommand($command);
        $seeder->__invoke();

        $this->assertStringContainsString('Từ chối tạo dữ liệu demo', $output->fetch());
        $this->assertDatabaseMissing('users', ['email' => DemoSeeder::EMAIL]);
    }

    public function test_local_seeding_still_creates_the_bootstrap_admin(): void
    {
        $this->seed(AdminUserSeeder::class);

        $this->assertDatabaseHas('users', ['email' => AdminUserSeeder::EMAIL, 'role' => 'ADMIN']);
    }

    private function pipe(string $content): void
    {
        $stream = fopen('php://memory', 'r+');
        $this->assertIsResource($stream);
        fwrite($stream, $content);
        rewind($stream);
        CreateAdminCommand::$stdin = $stream;
    }
}
