<?php

namespace Tests\Feature\Experiments;

use App\Actions\Benchmarks\ImportGroundTruthAction;
use App\Models\User;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Regression (S10-01 audit): the M14 import hid stale bugs that experiments now skip.
 * Sandbox reset itself is covered by UrlTemplateBenchmarkSandboxTest and RunExperimentTest.
 */
class BenchmarkSandboxResetTest extends TestCase
{
    public function test_m14_import_reports_stale_bugs(): void
    {
        $this->mock(ImportGroundTruthAction::class, function (MockInterface $mock): void {
            $mock->shouldReceive('execute')->andReturn([[
                'slug' => 'simple-store',
                'benchmark_id' => 1,
                'created' => false,
                'bugs_created' => 0,
                'bugs_updated' => 5,
                'stale_bugs' => ['BUG-099'],
            ]]);
        });
        $admin = (new User)->forceFill(['id' => 1, 'name' => 'Admin', 'email' => 'a@ait.local', 'role' => 'ADMIN', 'is_active' => true]);

        $this->actingAs($admin)->post('/admin/benchmarks/import')
            ->assertRedirect('/admin/benchmarks')
            ->assertSessionHas('warning', fn (string $m): bool => str_contains($m, 'simple-store/BUG-099'));
    }
}
