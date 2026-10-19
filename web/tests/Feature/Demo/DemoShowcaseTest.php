<?php

namespace Tests\Feature\Demo;

use App\Actions\Demo\ImportOfficialExperimentsAction;
use App\Actions\Experiments\BuildExperimentComparisonAction;
use App\Actions\Runs\ListRecentRunsAction;
use App\Contracts\TargetHostResolver;
use App\Contracts\TargetHttpClient;
use App\Enums\ExperimentStatus;
use App\Enums\UserRole;
use App\Models\Benchmark;
use App\Models\BenchmarkBug;
use App\Models\Experiment;
use App\Models\ExperimentBugDetection;
use App\Models\GenerationJob;
use App\Models\LlmCallLog;
use App\Models\Project;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\Demo\DemoShowcaseFixture;
use App\Services\Target\FakeTargetHostResolver;
use App\Services\Target\FakeTargetHttpClient;
use App\Support\Demo\DemoShowcase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * S14-05: public demo showcase of the production site (ADR-0007 addendum 2026-10-13).
 */
class DemoShowcaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(TargetHostResolver::class, new FakeTargetHostResolver([
            'simple-store' => ['127.0.0.1'],
            'ecommerce-order' => ['127.0.0.1'],
            'appointment-booking' => ['127.0.0.1'],
        ]));
        $this->app->instance(TargetHttpClient::class, new FakeTargetHttpClient);
    }

    private function command(string $command): PendingCommand
    {
        $pending = $this->artisan($command);
        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    private function enable(): void
    {
        config(['ait.demo_showcase.enabled' => true]);
    }

    public function test_it_refuses_and_creates_nothing_when_the_showcase_is_disabled(): void
    {
        config(['ait.demo_showcase.enabled' => false]);

        $this->command('ait:demo:showcase')->assertFailed();
        $this->command('ait:demo:showcase --restore-accounts')->assertFailed();

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Project::query()->count());
    }

    public function test_restore_accounts_creates_the_four_public_demo_accounts(): void
    {
        $this->enable();

        $this->command('ait:demo:showcase --restore-accounts')->assertSuccessful();

        $this->assertSame(4, User::query()->count());
        foreach (DemoShowcase::accounts() as $account) {
            $user = User::query()->where('email', $account['email'])->firstOrFail();
            $this->assertSame($account['role'], $user->role);
            $this->assertTrue($user->is_active);
            $this->assertTrue(Hash::check('123456', $user->password));
        }
        $this->assertSame(UserRole::Admin, User::query()->where('email', 'admin@demo.ait.local')->value('role'));
        $this->assertSame(0, Project::query()->count(), '--restore-accounts touches no data');
    }

    public function test_restore_undoes_password_role_and_lock_changes_and_signs_sessions_out(): void
    {
        $this->enable();
        $this->command('ait:demo:showcase --restore-accounts')->assertSuccessful();

        $admin = User::query()->where('email', 'admin@demo.ait.local')->firstOrFail();
        $admin->forceFill(['password' => 'changed-by-a-visitor', 'role' => UserRole::Tester])->save();
        $tester = User::query()->where('email', 'tester2@demo.ait.local')->firstOrFail();
        $tester->forceFill(['is_active' => false])->save();
        DB::table('sessions')->insert(['id' => 'visitor-session', 'user_id' => $admin->id, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => time()]);
        $other = User::factory()->tester()->create(['password' => 'untouched-password-1']);

        $this->command('ait:demo:showcase --restore-accounts')->assertSuccessful();

        $admin->refresh();
        $tester->refresh();
        $this->assertTrue(Hash::check('123456', $admin->password));
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertTrue($tester->is_active);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $admin->id)->count());
        $this->assertTrue(Hash::check('untouched-password-1', (string) User::query()->findOrFail($other->id)->password), 'other accounts are untouched');
        $this->assertSame(5, User::query()->count(), 'no duplicate demo accounts');
    }

    public function test_demo_accounts_can_sign_in_with_the_public_password(): void
    {
        $this->enable();
        $this->command('ait:demo:showcase --restore-accounts')->assertSuccessful();

        $this->post('/login', ['email' => 'admin@demo.ait.local', 'password' => '123456'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs(User::query()->where('email', 'admin@demo.ait.local')->firstOrFail());
    }

    public function test_login_page_lists_the_demo_accounts_only_when_enabled(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('data-testid="demo-accounts"', escape: false)->assertDontSee('admin@demo.ait.local');

        $this->enable();
        $this->get('/login')
            ->assertOk()
            ->assertSee('data-testid="demo-accounts"', escape: false)
            ->assertSeeText('Tài khoản demo')
            ->assertSeeText('admin@demo.ait.local')
            ->assertSeeText('tester3@demo.ait.local')
            ->assertSeeText('123456');
    }

    public function test_full_showcase_seeds_projects_recorded_llm_data_and_official_experiments_idempotently(): void
    {
        $this->enable();

        $this->command('ait:demo:showcase')->assertSuccessful();

        $this->assertSame(3, Benchmark::query()->count());
        $this->assertSame(32, BenchmarkBug::query()->count());

        foreach (DemoShowcase::projects() as $plan) {
            $project = Project::query()->where('name', $plan['name'])->firstOrFail();
            $this->assertSame($plan['owner'], $project->owner->email);
            $this->assertTrue(TestSuite::query()->where('project_id', $project->id)->where('name', DemoShowcase::RULE_SUITE_NAME)->exists());
            $this->assertTrue(TestSuite::query()->where('project_id', $project->id)->where('name', DemoShowcase::RECORDED_SUITE_NAME)->exists());
        }
        $this->assertGreaterThan(0, LlmCallLog::query()->count(), 'recorded LLM calls are visible in M10');

        // Secured benchmark: with the auth fixture RULE covers every technique, not only AUTH cases.
        $orderRuleSuite = TestSuite::query()->where('name', DemoShowcase::RULE_SUITE_NAME)
            ->whereHas('project', fn ($q) => $q->where('name', 'Demo – E-commerce Order'))->firstOrFail();
        $techniques = \App\Models\TestCase::query()->where('test_suite_id', $orderRuleSuite->id)->distinct()->pluck('technique')
            ->map(static fn ($t): string => $t instanceof \BackedEnum ? (string) $t->value : (string) $t)->all();
        $this->assertContains('HAPPY_PATH', $techniques);
        $this->assertContains('AUTH', $techniques);

        $experiments = Experiment::query()->get();
        $this->assertCount(9, $experiments);
        $this->assertTrue($experiments->every(fn (Experiment $e): bool => $e->status === ExperimentStatus::Completed));
        $this->assertTrue($experiments->every(fn (Experiment $e): bool => str_ends_with($e->name, ImportOfficialExperimentsAction::NAME_SUFFIX)));
        $this->assertGreaterThan(0, ExperimentBugDetection::query()->count());

        // Official figures are kept verbatim from the fixture.
        $fixture = app(DemoShowcaseFixture::class);
        $official = collect($fixture->experiments('simple-store'))->firstWhere('strategy', 'HYBRID');
        $this->assertIsArray($official);
        $imported = Experiment::query()->where('strategy', 'HYBRID')->whereHas('benchmark', fn ($q) => $q->where('slug', 'simple-store'))->firstOrFail();
        $this->assertEquals($official['metrics'], $imported->metrics);
        $detections = is_array($official['bug_detections'] ?? null) ? $official['bug_detections'] : [];
        $this->assertSame(
            count(array_filter($detections, static fn (mixed $d): bool => is_array($d) && ($d['detected'] ?? false) === true)),
            ExperimentBugDetection::query()->where('experiment_id', $imported->id)->where('detected', true)->count(),
        );

        $counts = [Project::query()->count(), TestSuite::query()->count(), TestRun::query()->count(), Experiment::query()->count(), LlmCallLog::query()->count(), User::query()->count()];
        $this->command('ait:demo:showcase')->assertSuccessful();
        $this->assertSame($counts, [Project::query()->count(), TestSuite::query()->count(), TestRun::query()->count(), Experiment::query()->count(), LlmCallLog::query()->count(), User::query()->count()], 'second run creates nothing new');
    }

    /**
     * QA S14-06: the three strategies of a benchmark share one name, so M15 compares RULE/LLM/HYBRID side
     * by side (3 comparison boards instead of 9 one-column boards).
     */
    public function test_official_experiments_of_a_benchmark_share_one_name_and_fill_one_comparison(): void
    {
        $this->enable();
        $this->command('ait:demo:showcase')->assertSuccessful();

        $experiments = Experiment::query()->with('benchmark')->get();
        $this->assertCount(9, $experiments);
        $names = $experiments->groupBy(fn (Experiment $e): string => $e->benchmark->slug)
            ->map(fn ($group): array => $group->pluck('name')->unique()->values()->all());
        $this->assertCount(3, $names);
        foreach ($names as $slug => $unique) {
            $this->assertCount(1, $unique, "one name for the 3 strategies of {$slug}");
            $this->assertStringContainsString((string) $slug, $unique[0]);
            $this->assertStringEndsWith(ImportOfficialExperimentsAction::NAME_SUFFIX, $unique[0]);
            $this->assertStringNotContainsString(' · RULE', $unique[0]);
        }

        $groups = app(BuildExperimentComparisonAction::class)->execute();
        $this->assertCount(3, $groups);
        foreach ($groups as $group) {
            $validity = collect($group['rows'])->firstWhere('key', 'validity_rate');
            $this->assertIsArray($validity);
            foreach (['RULE', 'LLM', 'HYBRID'] as $strategy) {
                $this->assertInstanceOf(Experiment::class, $group['experiments'][$strategy], "{$group['name']}: {$strategy}");
                $this->assertNotNull($validity['cells'][$strategy]['mean'], "{$group['name']}: {$strategy} validity");
            }
        }

        $tester = User::query()->where('email', 'tester@demo.ait.local')->firstOrFail();
        $page = $this->actingAs($tester)->get('/experiments')->assertOk();
        foreach ($names as $unique) {
            $page->assertSeeText($unique[0]);
        }
    }

    /**
     * QA S14-06: production already holds the data of the first showcase. The next deploy run converges it
     * without duplicates: per-strategy names, descriptions with screen codes, record runs dated at import
     * time and replayed jobs without their marker.
     */
    public function test_rerun_converges_data_of_an_earlier_showcase_without_duplicates(): void
    {
        $this->enable();
        $this->command('ait:demo:showcase')->assertSuccessful();

        // Bring the data back to what the first showcase (S14-05) stored.
        foreach (Experiment::query()->get() as $experiment) {
            $official = substr($experiment->name, 0, -strlen(ImportOfficialExperimentsAction::NAME_SUFFIX));
            $experiment->update(['name' => $official.' · '.$experiment->strategy->value.ImportOfficialExperimentsAction::NAME_SUFFIX]);
        }
        $recordSuiteIds = TestSuite::query()->whereIn('name', array_map(
            static fn (string $s): string => 'Thực nghiệm '.$s.ImportOfficialExperimentsAction::NAME_SUFFIX,
            ['RULE', 'LLM', 'HYBRID'],
        ))->pluck('id');
        $this->assertCount(9, $recordSuiteIds);
        TestSuite::query()->whereIn('id', $recordSuiteIds)->update(['description' => ImportOfficialExperimentsAction::LEGACY_SUITE_DESCRIPTIONS[0]]);
        TestSuite::query()->where('name', DemoShowcase::RECORDED_SUITE_NAME)->update(['description' => DemoShowcase::LEGACY_SUITE_DESCRIPTIONS[0]]);
        $custom = TestSuite::query()->where('name', DemoShowcase::RULE_SUITE_NAME)->firstOrFail();
        $custom->update(['description' => 'Mô tả do người xem sửa.']);
        $recordRuns = TestRun::query()->whereIn('test_suite_id', $recordSuiteIds)->pluck('id');
        $this->assertGreaterThan(300, $recordRuns->count());
        TestRun::query()->whereIn('id', $recordRuns)->toBase()->update(['created_at' => now(), 'updated_at' => now()]);
        foreach (GenerationJob::query()->get() as $job) {
            $params = $job->params;
            unset($params[GenerationJob::RECORDED_SOURCE_PARAM]);
            $job->update(['params' => $params]);
        }
        $count = fn (): array => [Experiment::query()->count(), TestSuite::query()->count(), TestRun::query()->count(), GenerationJob::query()->count()];
        $before = $count();

        $this->command('ait:demo:showcase')->assertSuccessful();

        $this->assertSame($before, $count(), 'no duplicate');
        $this->assertCount(3, app(BuildExperimentComparisonAction::class)->execute());
        $this->assertSame(0, TestSuite::query()->whereIn('description', [
            ...ImportOfficialExperimentsAction::LEGACY_SUITE_DESCRIPTIONS, ...DemoShowcase::LEGACY_SUITE_DESCRIPTIONS,
        ])->count());
        $this->assertSame('Mô tả do người xem sửa.', $custom->refresh()->description, 'a visitor\'s description is kept');

        foreach (TestRun::query()->whereIn('id', $recordRuns)->get() as $run) {
            $this->assertNotNull($run->started_at);
            $this->assertSame($run->started_at->getTimestamp(), $run->created_at->getTimestamp(), "run #{$run->id} dated when it ran");
        }

        $replays = GenerationJob::query()->whereIn('strategy', ['LLM', 'HYBRID'])->get();
        $this->assertCount(6, $replays);
        $this->assertTrue($replays->every(fn (GenerationJob $job): bool => $job->isReplayed()));
    }

    public function test_demo_texts_show_no_screen_code_and_record_runs_date_from_the_official_run(): void
    {
        $this->enable();
        $this->command('ait:demo:showcase')->assertSuccessful();

        foreach (TestSuite::query()->get() as $suite) {
            $this->assertDoesNotMatchRegularExpression('/\bM\d{2}\b/', (string) $suite->description, "suite {$suite->name}");
        }

        $imported = Experiment::query()->where('strategy', 'RULE')->whereHas('benchmark', fn ($q) => $q->where('slug', 'simple-store'))->firstOrFail();
        $cleanRun = TestRun::query()->findOrFail((int) ($imported->repetitions_data[0]['clean_test_run_id'] ?? 0));
        $this->assertNotNull($cleanRun->started_at);
        $this->assertSame($cleanRun->started_at->getTimestamp(), $cleanRun->created_at->getTimestamp());
        $this->assertLessThan(now()->subHour()->getTimestamp(), $cleanRun->created_at->getTimestamp(), 'not the import time');

        // The demo TESTER's own runs (with results) outrank the imported record runs on M02.
        $tester = User::query()->where('email', 'tester@demo.ait.local')->firstOrFail();
        $recent = app(ListRecentRunsAction::class)->execute($tester);
        $this->assertNotEmpty($recent);
        $first = $recent->first();
        $this->assertInstanceOf(TestRun::class, $first);
        $this->assertTrue($first->testResults()->exists(), 'the newest run on M02 is a real run with results');
    }

    public function test_imported_experiments_are_visible_read_only_in_m15(): void
    {
        $this->enable();
        config(['ait.experiments.enabled' => false]);
        $this->command('ait:demo:showcase')->assertSuccessful();

        $tester = User::query()->where('email', 'tester@demo.ait.local')->firstOrFail();
        $experiment = Experiment::query()->firstOrFail();

        $this->actingAs($tester)->get('/experiments')->assertOk()->assertSeeText(ImportOfficialExperimentsAction::NAME_SUFFIX);
        $this->actingAs($tester)->get('/experiments/'.$experiment->id)->assertOk()->assertSeeText($experiment->name);
    }
}
