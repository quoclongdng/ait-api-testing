<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\ShowRunReportAction;
use App\Data\Reports\RunReportSummary;
use App\Enums\HttpMethod;
use App\Enums\TestRunStatus;
use App\Enums\TestVerdict;
use App\Models\Endpoint;
use App\Models\Project;
use App\Models\TestCase as TestCaseModel;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * QA S14-06: the official experiment records of the demo showcase are COMPLETED runs that store only
 * their totals (no test_results). M12, M13 and the export showed "0/59 case" and an empty summary while
 * the suite run list showed the real totals; they now show run.totals with a note.
 */
class TotalsOnlyRunReportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private TestSuite $suite;

    private TestRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->tester()->create();
        $project = Project::factory()->for($this->owner, 'owner')->create();
        $this->suite = TestSuite::factory()->for($project)->for($this->owner, 'creator')->create();
        $this->run = TestRun::factory()->for($this->suite)->for($this->owner, 'triggerer')->create([
            'status' => TestRunStatus::Completed,
            'target_base_url' => 'http://ecommerce-order-bug-007:8000',
            'benchmark_variant' => 'BUG-007',
            'started_at' => Carbon::parse('2026-10-12 13:00:00'),
            'finished_at' => Carbon::parse('2026-10-12 13:00:03'),
            'totals' => ['total' => 59, 'pass' => 57, 'fail' => 2, 'error' => 0, 'skipped' => 0],
        ]);
    }

    public function test_summary_comes_from_the_stored_totals(): void
    {
        $summary = app(ShowRunReportAction::class)->execute($this->run)->summary;

        $this->assertTrue($summary->totalsOnly);
        $this->assertSame(59, $summary->total);
        $this->assertSame(57, $summary->pass);
        $this->assertSame(2, $summary->fail);
        $this->assertSame(0, $summary->error);
        $this->assertSame(96.6, $summary->passRate);
        $this->assertSame(3000, $summary->wallDurationMs);
        $this->assertNull($summary->averageDurationMs);
        $this->assertFalse($summary->toArray()['results_available']);
    }

    public function test_m13_shows_the_totals_and_the_note(): void
    {
        $this->actingAs($this->owner)->get("/runs/{$this->run->id}/report")
            ->assertOk()
            ->assertSee('data-testid="run-totals-only"', escape: false)
            ->assertSeeText('chỉ lưu số liệu tổng')
            ->assertSeeText('59')
            ->assertSeeText('57')
            ->assertSeeText('96,6%')
            ->assertDontSeeText('Lần chạy này chưa có kết quả nào.')
            ->assertDontSeeText('Không có ca kiểm thử nào thất bại hoặc lỗi.');
    }

    public function test_exports_carry_the_totals_and_say_no_per_case_result_exists(): void
    {
        $json = $this->actingAs($this->owner)->getJson("/api/v1/runs/{$this->run->id}/export?format=json")->assertOk();
        $json->assertJsonPath('summary.total', 59)
            ->assertJsonPath('summary.pass', 57)
            ->assertJsonPath('summary.fail', 2)
            ->assertJsonPath('summary.executed', 59)
            ->assertJsonPath('summary.results_available', false)
            ->assertJsonPath('results', []);

        $csv = (string) $this->actingAs($this->owner)->get("/api/v1/runs/{$this->run->id}/export?format=csv")->assertOk()->getContent();
        $this->assertStringContainsString(RunReportSummary::TOTALS_ONLY_NOTE, $csv);
        $this->assertStringContainsString('Tổng 59: PASS 57, FAIL 2, ERROR 0, SKIPPED 0', $csv);

        $html = (string) $this->actingAs($this->owner)->get("/api/v1/runs/{$this->run->id}/export?format=html")->assertOk()->getContent();
        $this->assertStringContainsString(RunReportSummary::TOTALS_ONLY_NOTE, $html);
        $this->assertStringNotContainsString('Lần chạy này chưa có kết quả nào.', $html);
    }

    /**
     * QA S14-06 round 3: the failures header read "(0)" next to FAIL 2, and an imported record with
     * started_at = finished_at showed "Thời lượng: 0 ms" and "0 ca" response-time buckets.
     */
    public function test_totals_only_report_shows_no_zero_valued_detail_labels(): void
    {
        $this->run->update([
            'started_at' => Carbon::parse('2026-10-12 12:44:16'),
            'finished_at' => Carbon::parse('2026-10-12 12:44:16'),
            'totals' => ['total' => 59, 'pass' => 56, 'fail' => 2, 'error' => 1, 'skipped' => 0],
        ]);
        $report = app(ShowRunReportAction::class)->execute($this->run->refresh());
        $this->assertNull($report->summary->wallDurationMs);
        $this->assertSame(3, $report->failureCount());

        $page = $this->actingAs($this->owner)->get("/runs/{$this->run->id}/report")->assertOk();
        $page->assertSeeText('Ca kiểm thử không đạt (3)')
            ->assertDontSeeText('Ca kiểm thử không đạt (0)')
            ->assertSeeText('Thời lượng: không có số liệu')
            ->assertDontSeeText('0 ms')
            ->assertDontSeeText('0 ca')
            ->assertSeeText(RunReportSummary::TOTALS_ONLY_TIMING_NOTE)
            ->assertDontSeeText('Tổng thời gian thực thi')
            ->assertSeeText('Số ca là FAIL + ERROR của số liệu tổng.')
            ->assertDontSeeText('Bấm vào từng ca để xem chi tiết assertion.');

        // JSON keeps the unknown duration as null (contract: wall_duration_ms is nullable), not 0.
        $this->actingAs($this->owner)->getJson("/api/v1/runs/{$this->run->id}/export?format=json")
            ->assertOk()
            ->assertJsonPath('summary.wall_duration_ms', null)
            ->assertJsonPath('summary.results_available', false);

        $html = (string) $this->actingAs($this->owner)->get("/api/v1/runs/{$this->run->id}/export?format=html")->assertOk()->getContent();
        $this->assertStringContainsString('Ca kiểm thử không đạt (3)', $html);
        $this->assertStringNotContainsString('Ca kiểm thử không đạt (0)', $html);
        $this->assertStringContainsString('Thời lượng: không có số liệu', $html);
        $this->assertStringNotContainsString('0 ms', $html);
        $this->assertStringContainsString(RunReportSummary::TOTALS_ONLY_TIMING_NOTE, $html);
        // The "Toàn bộ kết quả" table is not left as a bare header row.
        $this->assertStringContainsString('data-testid="run-results-empty"', $html);
        $this->assertStringContainsString('Không có kết quả từng ca: lần chạy này chỉ lưu số liệu tổng.', $html);
    }

    public function test_totals_only_report_keeps_a_known_wall_duration(): void
    {
        $this->actingAs($this->owner)->get("/runs/{$this->run->id}/report")
            ->assertOk()
            ->assertSeeText('Thời lượng: 3,0 giây')
            ->assertSeeText('Ca kiểm thử không đạt (2)');
    }

    public function test_m12_receives_the_totals_and_ships_the_totals_only_branch(): void
    {
        $this->actingAs($this->owner)->get("/runs/{$this->run->id}")
            ->assertOk()
            ->assertSee('get totalsOnly()', escape: false)
            // A failed results request must not read as a totals-only run.
            ->assertSee('this.isSettled && this.resultsLoaded && this.resultsTotal === 0', escape: false)
            ->assertSee('data-testid="run-totals-only"', escape: false)
            ->assertSee('processedCount', escape: false)
            ->assertSee(RunReportSummary::TOTALS_ONLY_NOTE)
            // run.totals reaches the Alpine config the counters read.
            ->assertSee('pass\\u0022:57', escape: false);
    }

    public function test_a_run_with_results_keeps_the_result_based_summary(): void
    {
        $endpoint = Endpoint::factory()->create(['api_spec_id' => $this->suite->api_spec_id, 'method' => HttpMethod::Get, 'path' => '/items']);
        $case = TestCaseModel::factory()->for($this->suite, 'suite')->create(['endpoint_id' => $endpoint->id]);
        $run = TestRun::factory()->for($this->suite)->for($this->owner, 'triggerer')->create([
            'status' => TestRunStatus::Completed,
            'started_at' => Carbon::parse('2026-10-12 13:00:00'),
            'finished_at' => Carbon::parse('2026-10-12 13:00:01'),
            'totals' => ['total' => 1, 'pass' => 1, 'fail' => 0, 'error' => 0, 'skipped' => 0],
        ]);
        TestResult::factory()->create([
            'test_run_id' => $run->id,
            'test_case_id' => $case->id,
            'verdict' => TestVerdict::Pass,
            'duration_ms' => 40,
        ]);

        $summary = app(ShowRunReportAction::class)->execute($run)->summary;

        $this->assertFalse($summary->totalsOnly);
        $this->assertSame(1, $summary->total);
        $this->assertSame(['lt_50' => 1, 'between_50_200' => 0, 'gt_200' => 0], $summary->responseTimeBuckets);
        $this->assertTrue($summary->toArray()['results_available']);
        $this->actingAs($this->owner)->get("/runs/{$run->id}/report")->assertOk()->assertDontSee('data-testid="run-totals-only"', escape: false);
    }

    public function test_a_run_without_results_or_totals_stays_empty(): void
    {
        $queued = TestRun::factory()->for($this->suite)->for($this->owner, 'triggerer')->create([
            'status' => TestRunStatus::Queued, 'totals' => null, 'started_at' => null, 'finished_at' => null,
        ]);

        $summary = app(ShowRunReportAction::class)->execute($queued)->summary;

        $this->assertFalse($summary->totalsOnly);
        $this->assertSame(0, $summary->total);
        $this->assertNull($summary->passRate);
    }
}
