<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class MigrationRollbackTest extends TestCase
{
    public function test_migrations_can_rollback_and_rerun_cleanly(): void
    {
        // Rollback all migrations
        $rollbackExitCode = Artisan::call('migrate:reset', ['--force' => true]);
        $this->assertSame(0, $rollbackExitCode, 'Migration rollback failed: '.Artisan::output());

        // Re-run all migrations
        $migrateExitCode = Artisan::call('migrate', ['--force' => true]);
        $this->assertSame(0, $migrateExitCode, 'Migration re-run failed: '.Artisan::output());
    }
}
