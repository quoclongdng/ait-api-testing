<?php

namespace Tests\Feature\Security;

use App\Enums\ErrorCode;
use App\Models\ApiSpec;
use App\Models\Project;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression (S13-03, security-review §2.3): POST /projects/{project}/specs had no throttle, so the
 * expensive OpenAPI parse (up to 5 MB) could be triggered without limit. The API route and the M06
 * form now share the per-user 'spec-uploads' limiter.
 */
class SpecUploadRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_spec_upload_is_rate_limited_with_the_error_envelope(): void
    {
        $tester = User::factory()->tester()->create();
        $project = Project::factory()->for($tester, 'owner')->create();
        $limit = AppServiceProvider::SPEC_UPLOADS_PER_MINUTE;

        for ($i = 0; $i < $limit; $i++) {
            $this->actingAs($tester)
                ->postJson("/api/v1/projects/{$project->id}/specs", $this->apiPayload($i))
                ->assertCreated();
        }

        $response = $this->actingAs($tester)
            ->withHeader('X-Request-Id', 'trace-spec-429')
            ->postJson("/api/v1/projects/{$project->id}/specs", $this->apiPayload($limit));

        $response->assertStatus(429)
            ->assertJsonPath('error.code', ErrorCode::RateLimited->value)
            ->assertJsonPath('trace_id', 'trace-spec-429')
            ->assertJsonStructure(['error' => ['code', 'message'], 'trace_id']);
        $this->assertSame($limit, ApiSpec::query()->where('project_id', $project->id)->count());

        // The limit is per user: another tester can still upload.
        $other = User::factory()->tester()->create();
        $otherProject = Project::factory()->for($other, 'owner')->create();
        $this->actingAs($other)
            ->postJson("/api/v1/projects/{$otherProject->id}/specs", $this->apiPayload(0))
            ->assertCreated();
    }

    public function test_m06_form_shares_the_api_budget_and_redirects_back_with_an_error(): void
    {
        $tester = User::factory()->tester()->create();
        $project = Project::factory()->for($tester, 'owner')->create();
        $limit = AppServiceProvider::SPEC_UPLOADS_PER_MINUTE;

        for ($i = 0; $i < $limit; $i++) {
            $this->actingAs($tester)
                ->postJson("/api/v1/projects/{$project->id}/specs", $this->apiPayload($i))
                ->assertCreated();
        }

        // The web entry point must not grant a second, independent budget.
        $this->actingAs($tester)
            ->from("/projects/{$project->id}/specs")
            ->post("/projects/{$project->id}/specs", [
                'version_label' => 'web-over-limit',
                'input_method' => 'text',
                'spec_text' => $this->specJson($limit),
            ])
            ->assertRedirect("/projects/{$project->id}/specs")
            ->assertSessionHas('error', 'Bạn đã tải lên quá nhiều đặc tả OpenAPI. Vui lòng thử lại sau ít phút.')
            ->assertSessionHasInput('version_label', 'web-over-limit')
            // The pasted spec (up to 5 MB) is not flashed into the session.
            ->assertSessionMissing('_old_input.spec_text');
        $this->assertSame($limit, ApiSpec::query()->where('project_id', $project->id)->count());

        // JSON callers of the web route receive 429 instead of a redirect.
        $this->actingAs($tester)
            ->postJson("/projects/{$project->id}/specs", [
                'version_label' => 'web-json-over-limit',
                'input_method' => 'text',
                'spec_text' => $this->specJson($limit + 1),
            ])
            ->assertStatus(429);
    }

    /**
     * @return array<string, string>
     */
    private function apiPayload(int $i): array
    {
        return ['version_label' => "v{$i}", 'format' => 'json', 'raw_content' => $this->specJson($i)];
    }

    /** A small valid spec whose checksum differs per $i, so no upload is a 409 duplicate. */
    private function specJson(int $i): string
    {
        return json_encode([
            'openapi' => '3.0.3',
            'info' => ['title' => 'Rate limit API', 'version' => "1.0.{$i}"],
            'paths' => [
                '/items' => [
                    'get' => ['responses' => ['200' => ['description' => 'OK']]],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
