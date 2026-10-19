<?php

namespace Tests\Feature\Api;

use App\Actions\Benchmarks\ImportGroundTruthAction;
use App\Models\Benchmark;
use App\Models\BenchmarkBug;
use App\Models\Experiment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BenchmarkApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_gets_401_and_tester_gets_403(): void
    {
        $this->getJson('/api/v1/admin/benchmarks')->assertStatus(401);

        $tester = User::factory()->tester()->create();
        $this->actingAs($tester)->getJson('/api/v1/admin/benchmarks')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
        $this->actingAs($tester)->postJson('/api/v1/admin/benchmarks', [
            'slug' => 'x', 'name' => 'X', 'base_url' => 'http://benchmark:8000', 'version' => 'v1.0',
        ])->assertStatus(403);
    }

    public function test_admin_lists_benchmarks_with_pagination_meta(): void
    {
        $admin = User::factory()->admin()->create();
        Benchmark::factory()->count(3)->create();

        $this->actingAs($admin)->getJson('/api/v1/admin/benchmarks?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonStructure(['data' => [['id', 'slug', 'name', 'description', 'base_url', 'version', 'created_at', 'updated_at']]]);
    }

    public function test_admin_creates_benchmark_and_ground_truth_bugs_are_attached(): void
    {
        $admin = User::factory()->admin()->create();

        // ground-truth.yaml says `version: "1.0"`: the request may use either spelling (S14-06).
        $this->actingAs($admin)->postJson('/api/v1/admin/benchmarks', [
            'slug' => 'simple-store',
            'name' => 'Simple Store (Docker)',
            'base_url' => 'http://benchmark:8000/',
            'version' => '1.0',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.slug', 'simple-store')
            ->assertJsonPath('data.version', 'v1.0')
            ->assertJsonPath('data.base_url', 'http://benchmark:8000');

        $benchmark = Benchmark::query()->where('version', 'v1.0')->firstOrFail();
        $this->assertSame(10, $benchmark->bugs()->count());
    }

    /**
     * S14-06 regression: "1.0" and "V1.0" passed the (slug, version) check next to the imported
     * "v1.0" and attached a second copy of the same ground-truth bugs.
     */
    public function test_version_spelling_cannot_duplicate_an_imported_benchmark(): void
    {
        $admin = User::factory()->admin()->create();
        app(ImportGroundTruthAction::class)->execute(['simple-store']);
        $benchmarks = Benchmark::query()->count();
        $bugs = BenchmarkBug::query()->count();

        foreach (['1.0', 'V1.0', ' v1.0 '] as $version) {
            $this->actingAs($admin)->postJson('/api/v1/admin/benchmarks', [
                'slug' => 'simple-store', 'name' => 'QA-ADMIN-dup', 'base_url' => 'http://simple-store:8000', 'version' => $version,
            ])
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'CONFLICT')
                ->assertJsonPath('error.message', "Benchmark 'simple-store' phiên bản v1.0 đã tồn tại.");
        }

        $this->assertSame($benchmarks, Benchmark::query()->count());
        $this->assertSame($bugs, BenchmarkBug::query()->count());
    }

    public function test_conflict_is_detected_against_a_row_stored_before_normalisation(): void
    {
        $admin = User::factory()->admin()->create();
        Benchmark::factory()->create(['slug' => 'custom-api', 'version' => '1.0']);

        $this->actingAs($admin)->postJson('/api/v1/admin/benchmarks', [
            'slug' => 'custom-api', 'name' => 'Custom', 'base_url' => 'http://benchmark:9000', 'version' => 'v1.0',
        ])->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');

        $this->assertSame(1, Benchmark::query()->where('slug', 'custom-api')->count());
    }

    public function test_version_of_a_benchmark_without_ground_truth_is_stored_normalised(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/v1/admin/benchmarks', [
            'slug' => 'custom-api', 'name' => 'Custom', 'base_url' => 'http://benchmark:9000', 'version' => '2',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.version', 'v2');

        $this->assertSame(0, Benchmark::query()->where('slug', 'custom-api')->firstOrFail()->bugs()->count());

        // Free text is only trimmed, not turned into "vbeta".
        $this->actingAs($admin)->postJson('/api/v1/admin/benchmarks', [
            'slug' => 'custom-api', 'name' => 'Custom', 'base_url' => 'http://benchmark:9000', 'version' => ' beta ',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.version', 'beta');
    }

    public function test_slug_with_ground_truth_only_accepts_the_ground_truth_version(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (['v2.0', '1.0.0', 'v1.0-docker'] as $version) {
            $response = $this->actingAs($admin)->postJson('/api/v1/admin/benchmarks', [
                'slug' => 'simple-store', 'name' => 'Simple Store', 'base_url' => 'http://simple-store:8000', 'version' => $version,
            ])
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'VALIDATION_FAILED')
                ->assertJsonValidationErrors(['version'], 'error.details');
            $this->assertStringContainsString('phiên bản v1.0', (string) $response->json('error.details.version.0'));
        }

        $this->assertSame(0, Benchmark::query()->count());
        $this->assertSame(0, BenchmarkBug::query()->count());
    }

    public function test_admin_deletes_an_unused_benchmark_with_its_bugs(): void
    {
        $admin = User::factory()->admin()->create();
        $benchmark = Benchmark::factory()->create(['slug' => 'simple-store', 'version' => '1.0']);
        BenchmarkBug::factory()->count(2)->create(['benchmark_id' => $benchmark->id]);
        $kept = Benchmark::factory()->create(['slug' => 'simple-store', 'version' => 'v1.0']);
        BenchmarkBug::factory()->create(['benchmark_id' => $kept->id]);

        $this->actingAs($admin)->deleteJson("/api/v1/admin/benchmarks/{$benchmark->id}")->assertNoContent();

        $this->assertModelMissing($benchmark);
        $this->assertSame(0, BenchmarkBug::query()->where('benchmark_id', $benchmark->id)->count());
        $this->assertModelExists($kept);
        $this->assertSame(1, $kept->bugs()->count());

        $this->actingAs($admin)->deleteJson("/api/v1/admin/benchmarks/{$benchmark->id}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_benchmark_used_by_an_experiment_cannot_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $benchmark = Benchmark::factory()->create();
        BenchmarkBug::factory()->create(['benchmark_id' => $benchmark->id]);
        Experiment::factory()->create(['benchmark_id' => $benchmark->id]);

        $this->actingAs($admin)->deleteJson("/api/v1/admin/benchmarks/{$benchmark->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CONFLICT')
            ->assertJsonPath('error.details.experiments', 1);

        $this->assertModelExists($benchmark);
        $this->assertSame(1, $benchmark->bugs()->count());
    }

    public function test_only_admin_can_delete_a_benchmark(): void
    {
        $benchmark = Benchmark::factory()->create();

        $this->deleteJson("/api/v1/admin/benchmarks/{$benchmark->id}")->assertStatus(401);
        $this->actingAs(User::factory()->tester()->create())->deleteJson("/api/v1/admin/benchmarks/{$benchmark->id}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->assertModelExists($benchmark);
    }

    public function test_duplicate_slug_and_version_returns_conflict(): void
    {
        $admin = User::factory()->admin()->create();
        Benchmark::factory()->create(['slug' => 'custom-api', 'version' => 'v1.0']);

        $this->actingAs($admin)->postJson('/api/v1/admin/benchmarks', [
            'slug' => 'custom-api', 'name' => 'Custom', 'base_url' => 'http://benchmark:9000', 'version' => 'v1.0',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CONFLICT');
    }

    public function test_invalid_payload_returns_validation_envelope(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/v1/admin/benchmarks', [
            'slug' => 'Bad Slug', 'name' => '', 'base_url' => 'ftp://x', 'version' => '',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(['slug', 'name', 'base_url', 'version'], 'error.details');
    }
}
