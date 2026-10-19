<?php

namespace Tests\Feature\Security;

use App\Enums\ApiSpecStatus;
use App\Enums\ErrorCode;
use App\Enums\HttpMethod;
use App\Models\AllowedTarget;
use App\Models\ApiSpec;
use App\Models\BusinessRule;
use App\Models\Endpoint;
use App\Models\Project;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Regression tests for the S10-02 web-security audit (round 1).
 */
class WebSecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    /** A name that breaks out of a single-quoted JS string inside an inline handler. */
    private const XSS_NAME = "x');window.__pwned=1;('";

    // --- Spec upload: authorize before validate -------------------------------------

    public function test_api_spec_upload_by_non_owner_with_invalid_body_returns_403_not_422(): void
    {
        $owner = User::factory()->tester()->create();
        $other = User::factory()->tester()->create();
        $project = Project::factory()->for($owner, 'owner')->create();

        $this->actingAs($other)
            ->postJson("/api/v1/projects/{$project->id}/specs", [])
            ->assertStatus(403)
            ->assertJsonPath('error.code', ErrorCode::Forbidden->value)
            ->assertJsonPath('error.message', 'Bạn không có quyền tải lên đặc tả cho dự án này.');
    }

    public function test_api_spec_upload_by_owner_with_invalid_body_still_returns_422(): void
    {
        $owner = User::factory()->tester()->create();
        $project = Project::factory()->for($owner, 'owner')->create();

        $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->id}/specs", [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ErrorCode::ValidationFailed->value);
    }

    public function test_web_spec_upload_by_non_owner_with_invalid_body_is_forbidden(): void
    {
        $owner = User::factory()->tester()->create();
        $other = User::factory()->tester()->create();
        $project = Project::factory()->for($owner, 'owner')->create();

        $this->actingAs($other)
            ->post("/projects/{$project->id}/specs", [])
            ->assertForbidden();
    }

    // --- Locked user on the JSON API -----------------------------------------------

    public function test_locked_user_api_request_returns_401_unauthenticated(): void
    {
        $locked = User::factory()->tester()->create(['is_active' => false]);

        $this->actingAs($locked)
            ->getJson('/api/v1/projects')
            ->assertStatus(401)
            ->assertJsonPath('error.code', ErrorCode::Unauthenticated->value);

        $this->assertGuest();
    }

    public function test_locked_user_api_request_without_json_accept_gets_401_envelope(): void
    {
        $locked = User::factory()->tester()->create(['is_active' => false]);

        $this->actingAs($locked)
            ->get('/api/v1/projects')
            ->assertStatus(401)
            ->assertJsonPath('error.code', ErrorCode::Unauthenticated->value);
    }

    public function test_locked_user_web_request_still_redirects_to_login(): void
    {
        $locked = User::factory()->tester()->create(['is_active' => false]);

        $this->actingAs($locked)
            ->get('/projects')
            ->assertRedirect(route('login'));
    }

    // --- Stored XSS through inline confirm() handlers ------------------------------

    public function test_project_name_is_not_interpolated_into_inline_handler(): void
    {
        $tester = User::factory()->tester()->create();
        Project::factory()->for($tester, 'owner')->create(['name' => self::XSS_NAME]);

        $response = $this->actingAs($tester)->get('/projects')->assertOk();

        $this->assertNoInlineHandlerContains($response, self::XSS_NAME);
        $response->assertSee('data-confirm="Bạn có chắc muốn xóa dự án '.e(self::XSS_NAME).'?"', false);
    }

    public function test_admin_sees_tester_project_name_safely(): void
    {
        $tester = User::factory()->tester()->create();
        Project::factory()->for($tester, 'owner')->create(['name' => "Bob's API"]);
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/projects')->assertOk();

        $this->assertNoInlineHandlerContains($response, "Bob's API");
    }

    public function test_suite_name_is_not_interpolated_into_inline_handler(): void
    {
        $tester = User::factory()->tester()->create();
        $project = Project::factory()->for($tester, 'owner')->create();
        $spec = ApiSpec::factory()->for($project)->create(['status' => ApiSpecStatus::Parsed]);
        TestSuite::factory()->create([
            'project_id' => $project->id,
            'api_spec_id' => $spec->id,
            'created_by' => $tester->id,
            'name' => self::XSS_NAME,
        ]);

        $response = $this->actingAs($tester)->get("/projects/{$project->id}/suites")->assertOk();

        $this->assertNoInlineHandlerContains($response, self::XSS_NAME);
    }

    public function test_business_rule_code_is_not_interpolated_into_inline_handler(): void
    {
        $tester = User::factory()->tester()->create();
        $project = Project::factory()->for($tester, 'owner')->create();
        BusinessRule::factory()->for($project)->create(['code' => "BR-'X"]);

        $response = $this->actingAs($tester)->get("/projects/{$project->id}/business-rules")->assertOk();

        $this->assertNoInlineHandlerContains($response, "BR-'X");
    }

    public function test_user_email_and_target_host_are_not_interpolated_into_inline_handlers(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->tester()->create(['email' => "o'brien@example.com"]);
        User::factory()->tester()->create(['email' => "o'neil@example.com", 'is_active' => false]);
        AllowedTarget::factory()->create(['host' => "evil'host", 'created_by' => $admin->id]);

        $users = $this->actingAs($admin)->get('/admin/users')->assertOk();
        $this->assertNoInlineHandlerContains($users, "o'brien@example.com");
        $this->assertNoInlineHandlerContains($users, "o'neil@example.com");

        $targets = $this->actingAs($admin)->get('/admin/allowed-targets')->assertOk();
        $this->assertNoInlineHandlerContains($targets, "evil'host");
    }

    // --- Shared generation-job rate limit across web and API ------------------------

    public function test_generation_job_limit_is_shared_between_web_and_api_routes(): void
    {
        Queue::fake();
        $tester = User::factory()->tester()->create();
        $project = Project::factory()->for($tester, 'owner')->create();
        $spec = ApiSpec::factory()->for($project)->create(['status' => ApiSpecStatus::Parsed]);
        $suite = TestSuite::factory()->create([
            'project_id' => $project->id,
            'api_spec_id' => $spec->id,
            'created_by' => $tester->id,
        ]);
        $endpoint = Endpoint::factory()->create([
            'api_spec_id' => $spec->id,
            'method' => HttpMethod::Get,
            'path' => '/items',
            'responses' => ['200' => ['description' => 'OK']],
        ]);
        $payload = ['strategy' => 'RULE', 'params' => ['endpoint_ids' => [$endpoint->id]]];

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($tester)
                ->postJson("/api/v1/suites/{$suite->id}/generation-jobs", $payload)
                ->assertAccepted();
        }

        // The web entry point must not grant a second, independent budget.
        $this->actingAs($tester)
            ->postJson("/suites/{$suite->id}/generation-jobs", ['strategy' => 'RULE'])
            ->assertStatus(429);
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function assertNoInlineHandlerContains(TestResponse $response, string $value): void
    {
        $html = (string) $response->getContent();
        $needle = preg_quote(e($value), '/');

        $this->assertDoesNotMatchRegularExpression(
            '/\son[a-z]+="[^"]*'.$needle.'/i',
            $html,
            'User-controlled data must not be interpolated into inline JS event handlers.'
        );
    }
}
