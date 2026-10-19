<?php

namespace Tests\Feature;

use App\Actions\System\BuildAdminOverviewAction;
use App\Data\AdminOverview;
use App\Enums\ApiSpecStatus;
use App\Enums\TestRunStatus;
use App\Models\ApiSpec;
use App\Models\Benchmark;
use App\Models\BenchmarkBug;
use App\Models\Project;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use App\Support\RoleLayout;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * S14-04: separate application shells for ADMIN (indigo, "Khu vực quản trị") and TESTER (teal,
 * "Không gian kiểm thử"), each with a responsive sidebar, plus the ADMIN dashboard
 * "Tổng quan quản trị" with system-wide figures.
 */
class RoleLayoutsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Project, 2: ApiSpec, 3: TestSuite}
     */
    private function ownedSuite(?User $tester = null): array
    {
        $tester ??= User::factory()->tester()->create();
        $project = Project::factory()->for($tester, 'owner')->create(['name' => 'Dự án Sidebar']);
        $spec = ApiSpec::factory()->for($project)->create(['status' => ApiSpecStatus::Parsed]);
        $suite = TestSuite::factory()->create([
            'project_id' => $project->id,
            'api_spec_id' => $spec->id,
            'created_by' => $tester->id,
        ]);

        return [$tester, $project, $spec, $suite];
    }

    /** @param TestResponse<Response> $response */
    private function html(TestResponse $response): string
    {
        return (string) $response->assertOk()->getContent();
    }

    /** Markup of the sidebar <aside> only. */
    private function sidebar(string $html): string
    {
        $start = strpos($html, '<aside id="app-sidebar"');
        $this->assertNotFalse($start, 'sidebar not found');
        $end = strpos($html, '</aside>', $start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    private function assertAdminLinksAbsent(string $html): void
    {
        foreach (['admin.users.index', 'admin.allowed-targets.index', 'admin.benchmarks.index'] as $route) {
            $this->assertStringNotContainsString('href="'.route($route).'"', $html, "{$route} must not be linked for a TESTER");
        }
        $this->assertStringNotContainsString('Khu vực quản trị', $html);
        $this->assertStringNotContainsString('data-testid="nav-group-admin"', $html);
    }

    // --- Layout resolution -------------------------------------------------------------------

    public function test_role_layout_resolves_the_shell_of_the_signed_in_role(): void
    {
        $this->assertSame('layouts.admin', RoleLayout::for(User::factory()->admin()->make()));
        $this->assertSame('layouts.tester', RoleLayout::for(User::factory()->tester()->make()));
        $this->assertSame('layouts.tester', RoleLayout::for(null));
    }

    // --- TESTER shell ------------------------------------------------------------------------

    public function test_tester_pages_render_the_tester_shell_without_admin_links(): void
    {
        [$tester, $project, $spec, $suite] = $this->ownedSuite();

        $pages = [
            '/dashboard',
            '/projects',
            '/experiments',
            route('projects.specs.index', $project),
            route('projects.specs.show', [$project, $spec]),
            route('projects.business-rules.index', $project),
            route('projects.suites.index', $project),
            route('suites.show', $suite),
            route('suites.runs.index', $suite),
        ];

        foreach ($pages as $page) {
            $html = $this->html($this->actingAs($tester)->get($page));

            $this->assertStringContainsString('data-shell="tester"', $html, $page);
            $this->assertStringNotContainsString('data-shell="admin"', $html, $page);
            $this->assertStringContainsString('Không gian kiểm thử', $html, $page);
            $this->assertStringContainsString('data-testid="nav-group-workspace"', $html, $page);
            $this->assertStringContainsString('data-testid="nav-experiments"', $html, $page);
            $this->assertAdminLinksAbsent($html);
        }
    }

    public function test_tester_sidebar_shows_the_project_context_only_inside_a_project(): void
    {
        [$tester, $project, , $suite] = $this->ownedSuite();

        $outside = $this->sidebar($this->html($this->actingAs($tester)->get('/projects')));
        $this->assertStringNotContainsString('data-testid="nav-project-context"', $outside);
        $this->assertStringNotContainsString(route('projects.specs.index', $project), $outside);

        $inside = $this->sidebar($this->html($this->actingAs($tester)->get(route('suites.show', $suite))));
        $this->assertStringContainsString('data-testid="nav-project-context"', $inside);
        $this->assertStringContainsString('Dự án Sidebar', $inside);
        $this->assertStringContainsString('href="'.route('projects.specs.index', $project).'"', $inside);
        $this->assertStringContainsString('href="'.route('projects.business-rules.index', $project).'"', $inside);
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('projects.suites.index', $project), '#').'"\s+aria-current="page"#', $inside);
        $this->assertStringContainsString('href="'.route('suites.runs.index', $suite).'"', $inside);
    }

    public function test_runs_link_opens_the_latest_suite_and_is_hidden_without_suites(): void
    {
        [$tester, $project, $spec] = $this->ownedSuite();
        $latest = TestSuite::factory()->create(['project_id' => $project->id, 'api_spec_id' => $spec->id, 'created_by' => $tester->id]);

        $html = $this->sidebar($this->html($this->actingAs($tester)->get(route('projects.business-rules.index', $project))));
        $this->assertStringContainsString('href="'.route('suites.runs.index', $latest).'"', $html);

        $empty = Project::factory()->for($tester, 'owner')->create();
        $html = $this->sidebar($this->html($this->actingAs($tester)->get(route('projects.business-rules.index', $empty))));
        $this->assertStringContainsString('data-testid="nav-project-context"', $html);
        $this->assertStringNotContainsString('data-testid="nav-project-runs"', $html);
    }

    // --- ADMIN shell -------------------------------------------------------------------------

    public function test_admin_pages_render_the_admin_shell(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (['/admin/users', '/admin/allowed-targets', '/admin/benchmarks', '/dashboard', '/projects', '/experiments'] as $page) {
            $html = $this->html($this->actingAs($admin)->get($page));

            $this->assertStringContainsString('data-shell="admin"', $html, $page);
            $this->assertStringNotContainsString('data-shell="tester"', $html, $page);
            $this->assertStringContainsString('Khu vực quản trị', $html, $page);
            $sidebar = $this->sidebar($html);
            $this->assertStringContainsString('data-testid="nav-group-admin"', $sidebar, $page);
            $this->assertStringContainsString('href="'.route('admin.users.index').'"', $sidebar, $page);
            $this->assertStringContainsString('href="'.route('admin.allowed-targets.index').'"', $sidebar, $page);
            $this->assertStringContainsString('href="'.route('admin.benchmarks.index').'"', $sidebar, $page);
            $this->assertStringContainsString('href="'.route('experiments.index').'"', $sidebar, $page);
            // ADMIN keeps every TESTER right: the testing workspace group links the project list.
            $this->assertStringContainsString('data-testid="nav-group-workspace"', $sidebar, $page);
            $this->assertStringContainsString('href="'.route('projects.index').'"', $sidebar, $page);
        }
    }

    public function test_admin_browsing_a_tester_project_sees_the_admin_shell_with_project_links(): void
    {
        [, $project, , $suite] = $this->ownedSuite();
        $admin = User::factory()->admin()->create();

        $html = $this->html($this->actingAs($admin)->get(route('projects.specs.index', $project)));
        $this->assertStringContainsString('data-shell="admin"', $html);
        $sidebar = $this->sidebar($html);
        $this->assertStringContainsString('data-testid="nav-project-context"', $sidebar);
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('projects.specs.index', $project), '#').'"\s+aria-current="page"#', $sidebar);
        $this->assertStringContainsString('href="'.route('suites.runs.index', $suite).'"', $sidebar);
    }

    public function test_tester_cannot_reach_the_admin_dashboard(): void
    {
        $tester = User::factory()->tester()->create();

        $this->actingAs($tester)->get('/admin')->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(BuildAdminOverviewAction::class)->execute($tester);
    }

    // --- Active item, menu button, user menu -------------------------------------------------

    public function test_active_sidebar_item_carries_aria_current(): void
    {
        $admin = User::factory()->admin()->create();
        $tester = User::factory()->tester()->create();

        $cases = [
            [$admin, '/admin/users', route('admin.users.index')],
            [$admin, '/admin/allowed-targets', route('admin.allowed-targets.index')],
            [$admin, '/admin/benchmarks', route('admin.benchmarks.index')],
            [$admin, '/dashboard', route('dashboard')],
            [$tester, '/dashboard', route('dashboard')],
            [$tester, '/projects', route('projects.index')],
            [$tester, '/experiments', route('experiments.index')],
        ];

        foreach ($cases as [$user, $page, $href]) {
            $sidebar = $this->sidebar($this->html($this->actingAs($user)->get($page)));
            $this->assertMatchesRegularExpression('#href="'.preg_quote($href, '#').'"\s+aria-current="page"#', $sidebar, $page);
            $this->assertSame(1, substr_count($sidebar, 'aria-current="page"'), "{$page}: exactly one active item");
        }
    }

    public function test_mobile_menu_button_and_user_menu_are_accessible(): void
    {
        $tester = User::factory()->tester()->create(['name' => 'Trần Kiểm Thử']);

        $html = $this->html($this->actingAs($tester)->get('/dashboard'));

        // Menu button controlling the off-canvas sidebar, focus trapped while open, closable.
        $this->assertMatchesRegularExpression('/<button[^>]*data-testid="shell-menu-button"[^>]*aria-label="Mở\/đóng menu điều hướng"[^>]*aria-controls="app-sidebar"[^>]*:aria-expanded="mobileOpen.toString\(\)"/', $html);
        $this->assertStringContainsString('x-modal-focus="mobileOpen"', $html);
        $this->assertStringContainsString('aria-label="Đóng menu điều hướng"', $html);
        $this->assertStringContainsString('<nav aria-label="Điều hướng chính"', $html);
        $this->assertStringContainsString('href="#main"', $html);
        $this->assertStringContainsString('<main id="main"', $html);

        // User menu: name, role and logout.
        $this->assertStringContainsString('aria-controls="account-menu"', $html);
        $this->assertMatchesRegularExpression('/data-testid="user-menu-name">Trần Kiểm Thử</', $html);
        $this->assertMatchesRegularExpression('/data-testid="user-menu-role"[^>]*>Kiểm thử viên</', $html);
        $this->assertStringContainsString('action="'.route('logout').'"', $html);
        $this->assertStringContainsString('Đăng xuất', $html);
        // No inline event handlers (CSP, ADR-0007).
        $this->assertDoesNotMatchRegularExpression('/\son(click|submit)=/', $html);
    }

    // --- Dashboards --------------------------------------------------------------------------

    public function test_admin_dashboard_shows_system_wide_figures(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->count(2)->tester()->create();
        User::factory()->tester()->inactive()->create();

        [$tester, , , $suite] = $this->ownedSuite();
        [, , , $otherSuite] = $this->ownedSuite();
        Project::factory()->for($admin, 'owner')->create();

        TestRun::factory()->create(['test_suite_id' => $suite->id, 'triggered_by' => $tester->id, 'status' => TestRunStatus::Completed]);
        TestRun::factory()->create(['test_suite_id' => $otherSuite->id, 'triggered_by' => $tester->id, 'status' => TestRunStatus::Failed]);
        TestRun::factory()->create(['test_suite_id' => $otherSuite->id, 'triggered_by' => $tester->id, 'status' => TestRunStatus::Running]);
        $old = TestRun::factory()->create(['test_suite_id' => $suite->id, 'triggered_by' => $tester->id, 'status' => TestRunStatus::Completed]);
        $old->forceFill(['created_at' => now()->subDays(10)])->save();

        $benchmark = Benchmark::factory()->create();
        BenchmarkBug::factory()->count(3)->create(['benchmark_id' => $benchmark->id]);

        $response = $this->actingAs($admin)->get('/dashboard')->assertOk()
            ->assertViewIs('admin.dashboard')
            ->assertSee('Tổng quan quản trị')
            ->assertViewHas('overview', function (AdminOverview $overview): bool {
                // admin + 2 testers + 2 suite owners active, 1 locked.
                return $overview->activeUsers === 5
                    && $overview->lockedUsers === 1
                    && $overview->adminUsers === 1
                    && $overview->projects === 3
                    && $overview->runsLast7Days === 3
                    && $overview->completedRunsLast7Days === 1
                    && $overview->failedRunsLast7Days === 1
                    && $overview->activeRuns === 1
                    && $overview->benchmarks === 1
                    && $overview->benchmarkBugs === 3;
            });

        $html = (string) $response->getContent();
        $this->assertMatchesRegularExpression('/data-testid="admin-stat-users-active">5</', $html);
        $this->assertMatchesRegularExpression('/data-testid="admin-stat-users-locked">1</', $html);
        $this->assertMatchesRegularExpression('/data-testid="admin-stat-projects-count">3</', $html);
        $this->assertMatchesRegularExpression('/data-testid="admin-stat-runs-count">3</', $html);
        $this->assertMatchesRegularExpression('/data-testid="admin-stat-benchmarks-count">1</', $html);
        $this->assertMatchesRegularExpression('/data-testid="admin-stat-bugs-count">3</', $html);
        $this->assertStringContainsString('data-testid="admin-services"', $html);
        // Latest runs of every project, not only the admin's own.
        $this->assertStringContainsString(route('runs.show', $suite->testRuns()->latest('id')->firstOrFail()), $html);
        $this->assertStringNotContainsString('Tổng quan của bạn', $html);
    }

    public function test_tester_dashboard_keeps_personal_figures_without_admin_figures(): void
    {
        [$tester] = $this->ownedSuite();
        $this->ownedSuite();

        $html = $this->html($this->actingAs($tester)->get('/dashboard')
            ->assertViewIs('dashboard')
            ->assertViewHas('stats', fn (array $stats): bool => $stats['projects_count'] === 1));

        $this->assertStringContainsString('Tổng quan của bạn', $html);
        $this->assertStringNotContainsString('Tổng quan quản trị', $html);
        $this->assertStringNotContainsString('data-testid="admin-stat-users"', $html);
        $this->assertAdminLinksAbsent($html);
    }
}
