<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\ListRecentRunsAction;
use App\Enums\TestRunStatus;
use App\Models\Project;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QA S14-06: M02 "Các lần chạy gần đây" ordered runs by id, so the 315 official experiment records the
 * demo showcase imports after the demo runs filled it although they ran days earlier.
 */
class RecentRunsOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_recent_runs_follow_time_not_insertion_order(): void
    {
        $tester = User::factory()->tester()->create();
        $suite = TestSuite::factory()->for(Project::factory()->for($tester, 'owner'))->for($tester, 'creator')->create();
        $run = fn (array $attributes): TestRun => TestRun::factory()->for($suite)->for($tester, 'triggerer')->create($attributes);

        $own = $run([
            'status' => TestRunStatus::Completed,
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(9),
        ]);
        // Imported later (higher id) but executed two days earlier, dated when it ran.
        $record = $run([
            'status' => TestRunStatus::Completed,
            'started_at' => now()->subDays(2),
            'finished_at' => now()->subDays(2)->addMinute(),
            'created_at' => now()->subDays(2),
            'totals' => ['total' => 59, 'pass' => 57, 'fail' => 2, 'error' => 0, 'skipped' => 0],
        ]);
        $queued = $run(['status' => TestRunStatus::Queued, 'started_at' => null, 'finished_at' => null, 'totals' => null]);
        $this->assertGreaterThan($own->id, $record->id);

        $ids = app(ListRecentRunsAction::class)->execute($tester)->pluck('id')->all();

        $this->assertSame([$queued->id, $own->id, $record->id], $ids);

        $page = (string) $this->actingAs($tester)->get('/dashboard')->assertOk()->getContent();
        $ownAt = strpos($page, "#{$own->id}");
        $recordAt = strpos($page, "#{$record->id}");
        $this->assertNotFalse($ownAt);
        $this->assertNotFalse($recordAt);
        $this->assertLessThan($recordAt, $ownAt, 'the tester\'s own run is listed before the older record');
    }
}
