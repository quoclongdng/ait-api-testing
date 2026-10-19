<?php

namespace Tests\Feature\Security;

use App\Enums\TestRunStatus;
use App\Models\Project;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * S14-06 QA: authenticated pages carried Laravel's default `Cache-Control: no-cache, private`, so the
 * browser restored them from its back/forward cache and Back after logout showed the previous user's
 * dashboard or project list. SecurityHeaders now marks every response rendered for a signed-in user
 * `no-store`; guest pages and public assets keep their caching.
 */
class AuthenticatedNoStoreTest extends TestCase
{
    use RefreshDatabase;

    /** @param TestResponse<Response> $response */
    private function cacheControl(TestResponse $response): string
    {
        return (string) $response->headers->get('Cache-Control');
    }

    public function test_authenticated_dashboard_pages_and_api_are_no_store(): void
    {
        $tester = User::factory()->tester()->create();

        foreach (['/dashboard', '/projects'] as $uri) {
            $response = $this->actingAs($tester)->get($uri)->assertOk();
            $this->assertStringContainsString('no-store', $this->cacheControl($response), $uri);
            $this->assertStringContainsString('private', $this->cacheControl($response), $uri);
            $this->assertStringContainsString('no-transform', $this->cacheControl($response), $uri);
            $response->assertHeader('Pragma', 'no-cache');
        }

        $api = $this->actingAs($tester)->getJson('/api/v1/projects')->assertOk();
        $this->assertStringContainsString('no-store', $this->cacheControl($api));
    }

    public function test_authenticated_pages_stay_no_store_when_security_headers_are_disabled(): void
    {
        config(['ait.security.headers_enabled' => false]);
        $tester = User::factory()->tester()->create();

        $response = $this->actingAs($tester)->get('/dashboard')->assertOk()
            ->assertHeaderMissing('Content-Security-Policy');
        $this->assertStringContainsString('no-store', $this->cacheControl($response));
    }

    public function test_guest_pages_are_not_marked_no_store(): void
    {
        $this->assertStringNotContainsString('no-store', $this->cacheControl($this->get('/login')->assertOk()));
    }

    public function test_public_assets_keep_their_cache_policy(): void
    {
        $public = sys_get_temp_dir().'/ait-no-store-'.bin2hex(random_bytes(4));
        mkdir($public, 0777, true);
        file_put_contents($public.'/robots.txt', "User-agent: *\nDisallow:\n");
        $this->app->usePublicPath($public);

        try {
            $robots = $this->get('/robots.txt')->assertOk();
            $this->assertStringNotContainsString('no-store', $this->cacheControl($robots));
            $this->assertStringContainsString('max-age=3600', $this->cacheControl($robots));
        } finally {
            (new Filesystem)->deleteDirectory($public);
        }
    }

    /** Cloudflare Web Analytics must not inject its beacon into the downloaded HTML report (S14-06). */
    public function test_html_run_export_forbids_transformation_by_intermediaries(): void
    {
        $tester = User::factory()->tester()->create();
        $project = Project::factory()->create(['owner_id' => $tester->id]);
        $suite = TestSuite::factory()->create(['project_id' => $project->id]);
        $run = TestRun::factory()->create([
            'test_suite_id' => $suite->id,
            'triggered_by' => $tester->id,
            'status' => TestRunStatus::Completed,
            'totals' => ['total' => 0, 'pass' => 0, 'fail' => 0, 'error' => 0, 'skipped' => 0],
        ]);

        $export = $this->actingAs($tester)->get("/api/v1/runs/{$run->id}/export?format=html")->assertOk();

        $this->assertStringContainsString('attachment', (string) $export->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-transform', $this->cacheControl($export));
        $this->assertStringContainsString('no-store', $this->cacheControl($export));
    }
}
