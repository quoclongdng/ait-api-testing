<?php

namespace Tests\Feature\Runs;

use App\Actions\Generation\ExecuteRuleGenerationAction;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\TestRunStatus;
use App\Enums\TestVerdict;
use App\Models\GenerationJob;
use App\Models\TestCase as TestCaseModel;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Regressions (QA S13-03): started_at/finished_at of test_runs and generation_jobs were stored with
 * whole seconds, so M13 showed "Thời lượng: 0 ms" next to "Tổng thời gian thực thi: 106 ms" and the
 * §11 generation time of RULE jobs was 0 or 1000 ms.
 */
class TimingPrecisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_and_generation_timestamps_keep_milliseconds(): void
    {
        $run = TestRun::factory()->create([
            'started_at' => Carbon::parse('2026-10-13 08:00:00.100'),
            'finished_at' => Carbon::parse('2026-10-13 08:00:00.206'),
        ])->fresh();
        $this->assertNotNull($run);
        $this->assertNotNull($run->started_at);
        $this->assertSame(106, (int) round($run->started_at->diffInMilliseconds($run->finished_at)));

        $job = GenerationJob::factory()->create([
            'started_at' => Carbon::parse('2026-10-13 08:00:00.250'),
            'finished_at' => Carbon::parse('2026-10-13 08:00:00.287'),
        ])->fresh();
        $this->assertNotNull($job);
        $this->assertNotNull($job->started_at);
        $this->assertSame(37, (int) round($job->started_at->diffInMilliseconds($job->finished_at)));
    }

    public function test_report_wall_duration_is_not_zero_for_a_sub_second_run(): void
    {
        $owner = User::factory()->tester()->create();
        $run = $this->runWithResults($owner, '2026-10-13 08:00:00.100', '2026-10-13 08:00:00.230', [60, 46]);

        $response = $this->actingAs($owner)->getJson("/api/v1/runs/{$run->id}/export?format=json")->assertOk();
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame(130, $data['summary']['wall_duration_ms']);
        $this->assertSame(106, $data['summary']['total_duration_ms']);

        $this->actingAs($owner)->get("/runs/{$run->id}/report")
            ->assertOk()
            ->assertSee('Thời lượng: <span class="text-slate-200">130 ms</span>', false);
    }

    public function test_report_of_a_run_recorded_with_whole_seconds_shows_at_least_the_executed_time(): void
    {
        $owner = User::factory()->tester()->create();
        $run = $this->runWithResults($owner, '2026-10-13 08:00:00', '2026-10-13 08:00:00', [60, 46]);

        $this->actingAs($owner)->get("/runs/{$run->id}/report")
            ->assertOk()
            ->assertSee('Thời lượng: <span class="text-slate-200">106 ms</span>', false)
            ->assertDontSee('Thời lượng: <span class="text-slate-200">0 ms</span>', false);
    }

    public function test_rule_generation_records_a_sub_second_duration(): void
    {
        $job = GenerationJob::factory()->create([
            'strategy' => GenerationStrategy::Rule,
            'status' => GenerationJobStatus::Queued,
        ]);

        $job = app(ExecuteRuleGenerationAction::class)->execute($job->id)->fresh();
        $this->assertNotNull($job);
        $this->assertNotNull($job->started_at);
        $this->assertNotNull($job->finished_at);
        // Two Carbon::now() calls a few milliseconds apart are no longer both cut to the second.
        $this->assertNotSame(0, $job->started_at->micro + $job->finished_at->micro);
    }

    /**
     * @param  list<int>  $durations
     */
    private function runWithResults(User $owner, string $startedAt, string $finishedAt, array $durations): TestRun
    {
        $suite = TestSuite::factory()->create(['created_by' => $owner->id]);
        $suite->project->update(['owner_id' => $owner->id]);
        $run = TestRun::factory()->for($suite)->for($owner, 'triggerer')->create([
            'status' => TestRunStatus::Completed,
            'started_at' => Carbon::parse($startedAt),
            'finished_at' => Carbon::parse($finishedAt),
            'totals' => ['total' => count($durations), 'pass' => count($durations), 'fail' => 0, 'error' => 0, 'skipped' => 0],
        ]);
        foreach ($durations as $duration) {
            TestResult::factory()->create([
                'test_run_id' => $run->id,
                'test_case_id' => TestCaseModel::factory()->create(['test_suite_id' => $suite->id])->id,
                'verdict' => TestVerdict::Pass,
                'duration_ms' => $duration,
            ]);
        }

        return $run;
    }
}
