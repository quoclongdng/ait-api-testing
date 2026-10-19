<?php

namespace Tests\Feature\Admin;

use App\Actions\Benchmarks\ImportGroundTruthAction;
use App\Actions\Demo\ImportOfficialExperimentsAction;
use App\Models\Benchmark;
use App\Models\BenchmarkBug;
use App\Models\Experiment;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S14-06: a duplicate benchmark registered by mistake on M14 can be deleted (when no experiment uses
 * it), and the official experiments of the showcase always attach to the ground-truth version.
 */
class BenchmarkManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_m14_shows_delete_only_for_benchmarks_without_experiments(): void
    {
        $admin = User::factory()->admin()->create();
        $unused = Benchmark::factory()->create(['slug' => 'simple-store', 'name' => 'QA-ADMIN-dup', 'version' => '1.0']);
        $used = Benchmark::factory()->create(['slug' => 'simple-store', 'version' => 'v1.0']);
        Experiment::factory()->create(['benchmark_id' => $used->id]);

        $this->actingAs($admin)->get('/admin/benchmarks')
            ->assertOk()
            ->assertSee('data-testid="benchmark-delete-'.$unused->id.'"', false)
            ->assertSee(route('admin.benchmarks.destroy', $unused), false)
            ->assertSee('data-confirm="Xóa benchmark QA-ADMIN-dup (1.0)', false)
            ->assertDontSee('data-testid="benchmark-delete-'.$used->id.'"', false)
            ->assertSee('data-testid="benchmark-delete-locked-'.$used->id.'"', false)
            ->assertSeeText('Đã có 1 thực nghiệm dùng benchmark này nên không xóa được.');
    }

    public function test_admin_deletes_an_unused_benchmark_from_m14(): void
    {
        $admin = User::factory()->admin()->create();
        $benchmark = Benchmark::factory()->create(['name' => 'QA-ADMIN-dup', 'version' => '1.0']);
        BenchmarkBug::factory()->count(3)->create(['benchmark_id' => $benchmark->id]);

        $this->actingAs($admin)->delete(route('admin.benchmarks.destroy', $benchmark))
            ->assertRedirect(route('admin.benchmarks.index'))
            ->assertSessionHas('success', 'Đã xóa benchmark QA-ADMIN-dup (1.0) cùng các seeded bug của nó.');

        $this->assertModelMissing($benchmark);
        $this->assertSame(0, BenchmarkBug::query()->count());
    }

    public function test_m14_refuses_to_delete_a_benchmark_used_by_an_experiment(): void
    {
        $admin = User::factory()->admin()->create();
        $benchmark = Benchmark::factory()->create(['slug' => 'simple-store', 'version' => 'v1.0']);
        Experiment::factory()->count(2)->create(['benchmark_id' => $benchmark->id]);

        $this->actingAs($admin)->delete(route('admin.benchmarks.destroy', $benchmark))
            ->assertRedirect(route('admin.benchmarks.index'))
            ->assertSessionHas('error', "Không thể xóa benchmark 'simple-store' phiên bản v1.0 vì đã có 2 thực nghiệm dùng benchmark này.");

        $this->assertModelExists($benchmark);
    }

    public function test_tester_cannot_delete_a_benchmark_from_m14(): void
    {
        $benchmark = Benchmark::factory()->create();

        $this->delete(route('admin.benchmarks.destroy', $benchmark))->assertRedirect('/login');
        $this->actingAs(User::factory()->tester()->create())->delete(route('admin.benchmarks.destroy', $benchmark))->assertForbidden();

        $this->assertModelExists($benchmark);
    }

    public function test_ground_truth_version_is_normalised_on_import(): void
    {
        $results = app(ImportGroundTruthAction::class)->execute(['simple-store']);

        // benchmark/simple-store/ground-truth.yaml declares `version: "1.0"`.
        $this->assertSame('v1.0', Benchmark::query()->findOrFail($results[0]['benchmark_id'])->version);
    }

    /**
     * ImportOfficialExperimentsAction took the newest row of the slug, so a duplicate registered on
     * M14 after the import received the official experiments.
     */
    public function test_official_experiments_attach_to_the_ground_truth_version_not_the_newest_row(): void
    {
        $admin = User::factory()->admin()->create();
        app(ImportGroundTruthAction::class)->execute(['simple-store']);
        $official = Benchmark::query()->where('slug', 'simple-store')->where('version', 'v1.0')->firstOrFail();
        $newer = Benchmark::factory()->create(['slug' => 'simple-store', 'version' => 'v2.0']);
        $this->assertGreaterThan($official->id, $newer->id);

        $report = app(ImportOfficialExperimentsAction::class)->execute($admin, ['simple-store' => TestSuite::factory()->create()]);

        $this->assertNotSame([], $report);
        $this->assertSame(count($report), Experiment::query()->where('benchmark_id', $official->id)->count());
        $this->assertSame(0, Experiment::query()->where('benchmark_id', $newer->id)->count());
    }
}
