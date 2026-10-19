<?php

namespace Tests\Feature\Security;

use App\Actions\User\ToggleUserStatusAction;
use App\Enums\ErrorCode;
use App\Models\ApiSpec;
use App\Models\BusinessRule;
use App\Models\Endpoint;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Regression tests for the S10-02 web-security audit (round 2).
 */
class WebSecurityAuditRound2Test extends TestCase
{
    use RefreshDatabase;

    // --- Business rule update: route project must own the rule before validation ----

    public function test_business_rule_update_with_foreign_project_in_url_is_404_whatever_the_code(): void
    {
        $attacker = User::factory()->tester()->create();
        $victim = User::factory()->tester()->create();
        $own = Project::factory()->for($attacker, 'owner')->create();
        $foreign = Project::factory()->for($victim, 'owner')->create();
        $rule = BusinessRule::factory()->create(['project_id' => $own->id, 'code' => 'MINE']);
        BusinessRule::factory()->create(['project_id' => $foreign->id, 'code' => 'SECRET-CODE']);

        foreach (['SECRET-CODE', 'NOT-THERE'] as $code) {
            $this->actingAs($attacker)
                ->patch("/projects/{$foreign->id}/business-rules/{$rule->id}", [
                    'code' => $code,
                    'description' => 'probe',
                ])
                ->assertNotFound()
                ->assertSessionHasNoErrors();
        }

        $this->assertSame('MINE', $rule->fresh()?->code);
    }

    public function test_business_rule_update_with_foreign_project_in_url_is_404_whatever_the_endpoint(): void
    {
        $attacker = User::factory()->tester()->create();
        $victim = User::factory()->tester()->create();
        $own = Project::factory()->for($attacker, 'owner')->create();
        $foreign = Project::factory()->for($victim, 'owner')->create();
        $rule = BusinessRule::factory()->create(['project_id' => $own->id, 'code' => 'MINE']);
        $foreignEndpoint = Endpoint::factory()->create([
            'api_spec_id' => ApiSpec::factory()->create(['project_id' => $foreign->id])->id,
        ]);

        foreach ([$foreignEndpoint->id, 999999] as $endpointId) {
            $this->actingAs($attacker)
                ->patch("/projects/{$foreign->id}/business-rules/{$rule->id}", [
                    'code' => 'MINE',
                    'endpoint_id' => $endpointId,
                    'description' => 'probe',
                ])
                ->assertNotFound()
                ->assertSessionHasNoErrors();
        }
    }

    public function test_business_rule_update_on_own_project_still_validates_endpoint_ownership(): void
    {
        $owner = User::factory()->tester()->create();
        $other = User::factory()->tester()->create();
        $own = Project::factory()->for($owner, 'owner')->create();
        $foreign = Project::factory()->for($other, 'owner')->create();
        $rule = BusinessRule::factory()->create(['project_id' => $own->id, 'code' => 'MINE']);
        $foreignEndpoint = Endpoint::factory()->create([
            'api_spec_id' => ApiSpec::factory()->create(['project_id' => $foreign->id])->id,
        ]);

        $this->actingAs($owner)
            ->patch("/projects/{$own->id}/business-rules/{$rule->id}", [
                'code' => 'MINE',
                'endpoint_id' => $foreignEndpoint->id,
                'description' => 'x',
            ])
            ->assertSessionHasErrors('endpoint_id');
    }

    // --- Lock toggle: shares UpdateUserAction locking + last-active-ADMIN guard -------

    public function test_toggle_cannot_lock_the_last_active_admin_when_the_actor_was_locked_concurrently(): void
    {
        $actor = User::factory()->admin()->create(['is_active' => true]);
        $other = User::factory()->admin()->create(['is_active' => true]);

        // A concurrent request by $other has already locked $actor and committed.
        User::query()->whereKey($actor->id)->update(['is_active' => false]);

        try {
            app(ToggleUserStatusAction::class)->execute($actor, $other);
            $this->fail('Locking the last active ADMIN must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('user', $e->errors());
        }

        $this->assertTrue($other->fresh()?->is_active);
    }

    public function test_toggle_with_explicit_target_is_idempotent_against_a_stale_double_submit(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->tester()->create(['is_active' => true]);

        $this->actingAs($admin)
            ->post("/admin/users/{$target->id}/toggle-status", ['is_active' => '0'])
            ->assertRedirect('/admin/users');
        // Second (stale) submit of the same "Khóa" form must not re-unlock the user.
        $this->actingAs($admin)
            ->post("/admin/users/{$target->id}/toggle-status", ['is_active' => '0'])
            ->assertRedirect('/admin/users');

        $this->assertFalse($target->fresh()?->is_active);
    }

    public function test_toggle_without_target_flips_the_locked_row_not_the_stale_model(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->tester()->create(['is_active' => true]);
        $stale = clone $target;
        User::query()->whereKey($target->id)->update(['is_active' => false]);

        $updated = app(ToggleUserStatusAction::class)->execute($admin, $stale);

        $this->assertTrue($updated->is_active);
        $this->assertTrue($target->fresh()?->is_active);
    }

    // --- API spec upload: 5 MB limit is in bytes ---------------------------------------

    public function test_api_spec_raw_content_over_5mb_in_bytes_is_rejected_and_not_stored(): void
    {
        $owner = User::factory()->tester()->create();
        $project = Project::factory()->for($owner, 'owner')->create();
        // 2,000,000 characters (< 5,242,880) but 6,000,000 bytes in UTF-8.
        $content = str_repeat('ệ', 2_000_000);
        $this->assertGreaterThan(5242880, strlen($content));

        $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->id}/specs", [
                'version_label' => 'v1',
                'format' => 'yaml',
                'raw_content' => $content,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ErrorCode::ValidationFailed->value)
            ->assertJsonStructure(['error' => ['details' => ['raw_content']]]);

        $this->assertSame(0, ApiSpec::query()->where('project_id', $project->id)->count());
    }

    // --- Error envelope for framework HTTP exceptions ----------------------------------

    public function test_csrf_token_mismatch_on_api_is_unauthenticated_not_internal_error(): void
    {
        app()->setLocale('en');
        Route::post('/api/_test/csrf', function () {
            throw new TokenMismatchException('CSRF token mismatch.');
        });

        $this->postJson('/api/_test/csrf')
            ->assertStatus(401)
            ->assertJsonPath('error.code', ErrorCode::Unauthenticated->value)
            ->assertJsonPath('error.message', 'Session expired. Please reload the page and sign in again.');
    }

    public function test_post_too_large_on_api_is_a_client_error_not_internal_error(): void
    {
        Route::post('/api/_test/too-large', function () {
            throw new PostTooLargeException;
        });

        $this->postJson('/api/_test/too-large')
            ->assertStatus(422)
            ->assertJsonPath('error.code', ErrorCode::ValidationFailed->value);
    }
}
