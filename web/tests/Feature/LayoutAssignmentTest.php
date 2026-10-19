<?php

namespace Tests\Feature;

use App\Enums\ApiSpecStatus;
use App\Models\ApiSpec;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The login page (M01) uses the blank auth layout, every page of a signed-in user uses a role
 * shell (layouts.app → layouts.admin / layouts.tester, or layouts.admin for admin/* screens), and
 * all of them share the same <head> core (layouts.partials.head).
 */
class LayoutAssignmentTest extends TestCase
{
    use RefreshDatabase;

    /** Full pages that are not rendered inside a web layout on purpose. */
    private const STANDALONE_VIEWS = [
        'reports.run-export', // self-contained HTML file downloaded from M13 (own CSP, no app assets)
        'errors.page',        // self-contained error page
    ];

    /** Pages on the blank auth layout (no site header, navigation or footer). */
    private const AUTH_VIEWS = ['auth.login'];

    /**
     * Every view a controller renders as a full page.
     *
     * @return list<string>
     */
    private function controllerViews(): array
    {
        $views = [];
        foreach (File::allFiles(app_path()) as $file) {
            preg_match_all("/\\bview\\(\\s*'([a-z0-9_.\\-]+)'/", $file->getContents(), $matches);
            array_push($views, ...$matches[1]);
        }
        $views = array_values(array_unique($views));
        sort($views);

        return $views;
    }

    private function extendedLayout(string $view): ?string
    {
        $path = resource_path('views/'.str_replace('.', '/', $view).'.blade.php');
        $this->assertFileExists($path, "view {$view}");

        if (preg_match("/@extends\\(\\s*'([^']+)'\\s*\\)/", (string) file_get_contents($path), $match) === 1) {
            return $match[1];
        }

        return null;
    }

    public function test_every_page_view_extends_the_layout_of_its_audience(): void
    {
        $views = $this->controllerViews();
        $this->assertContains('auth.login', $views);
        $this->assertContains('dashboard', $views);
        $this->assertContains('admin.dashboard', $views);

        foreach ($views as $view) {
            if (in_array($view, self::STANDALONE_VIEWS, true)) {
                continue;
            }

            $layout = $this->extendedLayout($view);

            if (in_array($view, self::AUTH_VIEWS, true)) {
                $this->assertSame('layouts.auth', $layout, "{$view} uses the blank auth layout");
            } elseif (str_starts_with($view, 'admin.')) {
                $this->assertSame('layouts.admin', $layout, "{$view} is an admin screen");
            } else {
                $this->assertSame('layouts.app', $layout, "{$view} must pick the shell of the signed-in role");
            }
        }
    }

    public function test_guest_and_role_layouts_share_the_head_partial(): void
    {
        foreach (['auth', 'base'] as $layout) {
            $source = (string) file_get_contents(resource_path("views/layouts/{$layout}.blade.php"));
            $this->assertStringContainsString("@include('layouts.partials.head'", $source, $layout);
            $this->assertStringNotContainsString('<x-vite-assets', $source, "{$layout} must load assets through the head partial only");
            $this->assertStringNotContainsString('fonts.bunny.net', $source, "{$layout} must load fonts through the head partial only");
        }

        // Only the two shells extend the base; screens never extend it (or a shell) directly.
        foreach (File::allFiles(resource_path('views')) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $source = $file->getContents();
            if (! in_array($relative, ['layouts/admin.blade.php', 'layouts/tester.blade.php'], true)) {
                $this->assertDoesNotMatchRegularExpression("/@extends\\(\\s*'layouts\\.base'/", $source, $relative);
            }
            if ($relative !== 'layouts/app.blade.php') {
                $this->assertDoesNotMatchRegularExpression("/@extends\\(\\s*'layouts\\.tester'/", $source, $relative);
            }
        }
    }

    public function test_home_sends_a_guest_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect(route('login'));

        $user = User::factory()->tester()->create();
        $this->actingAs($user)->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_login_page_renders_the_auth_layout_with_the_shared_head(): void
    {
        foreach (['/login' => 'auth'] as $page => $layout) {
            $this->get($page)
                ->assertOk()
                ->assertSee('data-layout="'.$layout.'"', escape: false)
                ->assertDontSee('data-layout="app"', escape: false)
                ->assertDontSee('id="app-sidebar"', escape: false)
                ->assertDontSee('name="csrf-token"', escape: false)
                ->assertSee('<meta name="viewport" content="width=device-width, initial-scale=1">', escape: false)
                ->assertSee('<link rel="icon" href="'.asset('favicon.ico').'" sizes="any">', escape: false)
                ->assertSee('https://fonts.bunny.net/css?family=inter:400,500,600,700', escape: false);
        }
    }

    public function test_login_uses_a_blank_layout_without_site_navigation(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('data-layout="auth"', escape: false)
            ->assertDontSee('<header', escape: false)
            ->assertDontSee('<footer', escape: false)
            ->assertSee('id="login-button"', escape: false);
    }

    public function test_signed_in_pages_render_a_role_shell_with_the_shared_head(): void
    {
        $tester = User::factory()->tester()->create();
        $admin = User::factory()->admin()->create();

        $cases = [
            [$tester, '/dashboard', 'tester'],
            [$tester, '/projects', 'tester'],
            [$admin, '/dashboard', 'admin'],
            [$admin, '/projects', 'admin'],
            [$admin, '/admin/users', 'admin'],
        ];

        foreach ($cases as [$user, $page, $shell]) {
            $this->actingAs($user)->get($page)
                ->assertOk()
                ->assertSee('data-layout="app" data-shell="'.$shell.'"', escape: false)
                ->assertDontSee('data-layout="guest"', escape: false)
                ->assertSee('id="app-sidebar"', escape: false)
                ->assertSee('name="csrf-token"', escape: false)
                ->assertSee('<meta name="viewport" content="width=device-width, initial-scale=1">', escape: false)
                ->assertSee('<link rel="icon" href="'.asset('favicon.ico').'" sizes="any">', escape: false)
                ->assertSee('https://fonts.bunny.net/css?family=inter:400,500,600,700', escape: false);
        }
    }

    public function test_page_title_escapes_a_dynamic_name_exactly_once(): void
    {
        $tester = User::factory()->tester()->create();
        $project = Project::factory()->for($tester, 'owner')->create(['name' => 'R&D <Kho> "API"']);
        ApiSpec::factory()->for($project)->create(['status' => ApiSpecStatus::Parsed]);

        $this->actingAs($tester)->get(route('projects.specs.index', $project))
            ->assertOk()
            ->assertSee('<title>Đặc tả OpenAPI — R&amp;D &lt;Kho&gt; &quot;API&quot; — '.e(config('app.name')).'</title>', escape: false)
            ->assertDontSee('&amp;amp;', escape: false)
            ->assertDontSee('<Kho>', escape: false);
    }
}
