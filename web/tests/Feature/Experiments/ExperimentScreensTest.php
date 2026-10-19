<?php

namespace Tests\Feature\Experiments;

use App\Enums\ExperimentStatus;
use App\Enums\GenerationStrategy;
use App\Models\Benchmark;
use App\Models\BenchmarkBug;
use App\Models\Experiment;
use App\Models\ExperimentBugDetection;
use App\Models\Project;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExperimentScreensTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $experiment = Experiment::factory()->create();

        $this->get('/experiments')->assertRedirect('/login');
        $this->get("/experiments/{$experiment->id}")->assertRedirect('/login');
        $this->get('/admin/benchmarks')->assertRedirect('/login');
        $this->post('/admin/benchmarks/import')->assertRedirect('/login');
    }

    /**
     * Regression: an active experiment must not reload M15 while the TESTER edits or submits the
     * form, and a partially created strategy batch is reported and retried for the rest only.
     */
    public function test_m15_auto_refresh_is_guarded_and_batch_submit_tracks_partial_failure(): void
    {
        Benchmark::factory()->create();
        Experiment::factory()->create(['status' => ExperimentStatus::Running]);
        $tester = User::factory()->tester()->create();

        $this->actingAs($tester)->get('/experiments')
            ->assertOk()
            ->assertSee('hasActive: true', false)
            ->assertSee('return !this.formOpen && !this.submitting && !this.batchLocked && !this.reloading;', false)
            ->assertSee('if (this.canAutoReload())', false)
            ->assertDontSee('setTimeout(() => window.location.reload(), 10000)', false)
            ->assertSee(':disabled="submitting || reloading"', false)
            ->assertSee('this.form.strategies = this.form.strategies.filter((s) => s !== strategy);', false)
            ->assertSee('Đã đưa vào hàng đợi: ')
            ->assertSee('chưa được tạo: ');
    }

    public function test_tester_and_admin_see_m15_with_comparison_table(): void
    {
        $benchmark = Benchmark::factory()->create(['name' => 'Simple Store CRUD']);
        $bug = BenchmarkBug::factory()->create(['benchmark_id' => $benchmark->id, 'code' => 'BUG-001']);
        $run = TestRun::factory()->create();

        $rule = Experiment::factory()->create([
            'name' => 'Đợt so sánh 1',
            'benchmark_id' => $benchmark->id,
            'strategy' => GenerationStrategy::Rule,
            'status' => ExperimentStatus::Completed,
            'metrics' => ['validity_rate' => 0.95, 'bug_detection_rate' => 0.5, 'generation_time_ms' => 2100, 'stddev' => ['validity_rate' => 0.012, 'bug_detection_rate' => 0.1]],
        ]);
        Experiment::factory()->create([
            'name' => 'Đợt so sánh 1',
            'benchmark_id' => $benchmark->id,
            'strategy' => GenerationStrategy::Hybrid,
            'status' => ExperimentStatus::Running,
        ]);
        ExperimentBugDetection::factory()->create([
            'experiment_id' => $rule->id,
            'benchmark_bug_id' => $bug->id,
            'repetition' => 1,
            'detected' => true,
            'clean_test_run_id' => $run->id,
            'bug_test_run_id' => $run->id,
        ]);

        foreach ([User::factory()->tester()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)->get('/experiments')
                ->assertOk()
                ->assertSee('<span>Thực nghiệm</span>', false)
                ->assertSee('BẢNG SO SÁNH CHỈ SỐ — Đợt so sánh 1')
                ->assertSee('Tỉ lệ hợp lệ (Validity Rate)')
                ->assertSee('95,0%')
                ->assertSee('± 1,2%', false)
                ->assertSee('2,10 giây')
                ->assertSee('Ma trận phát hiện bug theo từng lần lặp')
                ->assertSee('BUG-001')
                ->assertSee('Đang chạy')
                ->assertSee('Tạo thực nghiệm');
        }
    }

    public function test_m15_renders_without_any_experiment(): void
    {
        $this->actingAs(User::factory()->tester()->create())->get('/experiments')
            ->assertOk()
            ->assertSee('Chưa có thực nghiệm nào.')
            ->assertSee('Chưa có benchmark nào.');
    }

    public function test_experiment_detail_page(): void
    {
        $bug = BenchmarkBug::factory()->create(['code' => 'BUG-007']);
        $experiment = Experiment::factory()->create([
            'name' => 'Đợt chi tiết',
            'benchmark_id' => $bug->benchmark_id,
            'status' => ExperimentStatus::Failed,
            'metrics' => ['error' => 'Không tìm thấy openapi.yaml.'],
        ]);

        foreach ([User::factory()->tester()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)->get("/experiments/{$experiment->id}")
                ->assertOk()
                ->assertSee('Thực nghiệm #'.$experiment->id.': Đợt chi tiết')
                ->assertSee('Thực nghiệm thất bại')
                ->assertSee('Không tìm thấy openapi.yaml.')
                ->assertSee('MA TRẬN PHÁT HIỆN BUG')
                ->assertSee('BUG-007');
        }
    }

    public function test_experiment_names_are_escaped(): void
    {
        $experiment = Experiment::factory()->create(['name' => '<script>alert(1)</script>']);

        $this->actingAs(User::factory()->tester()->create())->get("/experiments/{$experiment->id}")
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_tester_cannot_open_experiment_workspace_of_another_user(): void
    {
        $owner = User::factory()->tester()->create();
        $other = User::factory()->tester()->create();
        $project = Project::factory()->for($owner, 'owner')->create(['name' => 'Thực nghiệm: Simple Store (v1.0)']);
        $suite = TestSuite::factory()->create(['project_id' => $project->id, 'created_by' => $owner->id]);

        $this->actingAs($other)->get("/projects/{$project->id}/suites")->assertForbidden();
        $this->actingAs($other)->get("/suites/{$suite->id}")->assertForbidden();
    }

    public function test_admin_sees_m14_and_tester_is_forbidden(): void
    {
        $benchmark = Benchmark::factory()->create(['name' => 'Simple Store CRUD']);
        BenchmarkBug::factory()->create(['benchmark_id' => $benchmark->id, 'code' => 'BUG-003', 'toggle_flag' => 'BUG_003']);

        $this->actingAs(User::factory()->admin()->create())->get('/admin/benchmarks')
            ->assertOk()
            ->assertSee('<span>Benchmark</span>', false)
            ->assertSee('Simple Store CRUD')
            ->assertSee('BUG-003')
            ->assertSee('BUG_003=1')
            ->assertSee('simple-store')
            ->assertSee('Thêm benchmark');

        $tester = User::factory()->tester()->create();
        $this->actingAs($tester)->get('/admin/benchmarks')->assertForbidden();
        $this->actingAs($tester)->post('/admin/benchmarks/import')->assertForbidden();
    }

    /**
     * ADR-0006: M14 shows the auth roles and business rules of ground-truth.yaml read only, never
     * the sandbox tokens.
     */
    public function test_m14_shows_auth_roles_and_business_rules_without_tokens(): void
    {
        Benchmark::factory()->create(['slug' => 'ecommerce-order', 'name' => 'E-Commerce Order']);
        Benchmark::factory()->create(['slug' => 'simple-store', 'name' => 'Simple Store']);

        $this->actingAs(User::factory()->admin()->create())->get('/admin/benchmarks')
            ->assertOk()
            ->assertSee('FIXTURE THỰC NGHIỆM (CHỈ ĐỌC)')
            ->assertSee('customer_bob')
            ->assertSee('GET /admin/orders')
            ->assertSee('BR-EO-006')
            ->assertSee('BR-SS-001')
            ->assertSee('Benchmark không có endpoint yêu cầu xác thực.')
            ->assertDontSee('customer-alice-token')
            ->assertDontSee('admin-token');
    }

    public function test_admin_imports_ground_truth_from_benchmark_directory(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/admin/benchmarks/import')
            ->assertRedirect('/admin/benchmarks')
            ->assertSessionHas('success');

        $this->assertSame(3, Benchmark::query()->count());
        $this->assertSame(32, BenchmarkBug::query()->count());
        $this->assertDatabaseHas('benchmark_bugs', ['code' => 'BUG-032', 'toggle_flag' => 'BUG_032']);
    }
}
