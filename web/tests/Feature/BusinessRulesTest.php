<?php

namespace Tests\Feature;

use App\Enums\HttpMethod;
use App\Models\ApiSpec;
use App\Models\BusinessRule;
use App\Models\Endpoint;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BusinessRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $otherTester;

    private User $admin;

    private Project $project;

    private Endpoint $endpoint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->tester()->create();
        $this->otherTester = User::factory()->tester()->create();
        $this->admin = User::factory()->admin()->create();

        $this->project = Project::factory()->create([
            'name' => 'Demo E-commerce Project',
            'owner_id' => $this->owner->id,
        ]);

        $apiSpec = ApiSpec::factory()->create([
            'project_id' => $this->project->id,
        ]);

        $this->endpoint = Endpoint::factory()->create([
            'api_spec_id' => $apiSpec->id,
            'method' => HttpMethod::Post,
            'path' => '/api/v1/orders',
            'summary' => 'Tạo đơn hàng mới',
        ]);
    }

    public function test_guest_cannot_access_or_modify_rules(): void
    {
        $this->get("/projects/{$this->project->id}/business-rules")
            ->assertRedirect('/login');

        $this->post("/projects/{$this->project->id}/business-rules", [
            'code' => 'BR-ORD-01',
            'description' => 'Test rule',
        ])->assertRedirect('/login');
    }

    public function test_non_owner_cannot_view_or_modify_rules(): void
    {
        $this->actingAs($this->otherTester)
            ->get("/projects/{$this->project->id}/business-rules")
            ->assertForbidden();

        $this->actingAs($this->otherTester)
            ->post("/projects/{$this->project->id}/business-rules", [
                'code' => 'BR-ORD-01',
                'description' => 'Test rule',
            ])->assertForbidden();

        $rule = BusinessRule::factory()->create([
            'project_id' => $this->project->id,
            'code' => 'BR-TEST-01',
        ]);

        $this->actingAs($this->otherTester)
            ->patch("/projects/{$this->project->id}/business-rules/{$rule->id}", [
                'code' => 'BR-TEST-01',
                'description' => 'Updated by intruder',
            ])->assertForbidden();

        $this->actingAs($this->otherTester)
            ->delete("/projects/{$this->project->id}/business-rules/{$rule->id}")
            ->assertForbidden();
    }

    public function test_owner_can_view_m08_screen_with_stats_and_filters(): void
    {
        // 1 global rule
        BusinessRule::factory()->create([
            'project_id' => $this->project->id,
            'endpoint_id' => null,
            'code' => 'BR-GLB-01',
            'description' => 'Global idempotency rule',
        ]);

        // 1 endpoint-specific rule
        BusinessRule::factory()->create([
            'project_id' => $this->project->id,
            'endpoint_id' => $this->endpoint->id,
            'code' => 'BR-ORD-01',
            'description' => 'Order payment threshold rule',
        ]);

        $response = $this->actingAs($this->owner)
            ->get("/projects/{$this->project->id}/business-rules");

        $response->assertOk()
            ->assertSee('Quy tắc nghiệp vụ')
            ->assertSee('BR-GLB-01')
            ->assertSee('BR-ORD-01')
            ->assertSee('(Áp dụng toàn bộ API)')
            ->assertSee('/api/v1/orders');

        // Test search filter
        $searchResp = $this->actingAs($this->owner)
            ->get("/projects/{$this->project->id}/business-rules?search=idempotency");
        $searchResp->assertSee('BR-GLB-01')
            ->assertDontSee('BR-ORD-01');

        // Test endpoint filter = global
        $globalFilterResp = $this->actingAs($this->owner)
            ->get("/projects/{$this->project->id}/business-rules?endpoint_id=global");
        $globalFilterResp->assertSee('BR-GLB-01')
            ->assertDontSee('BR-ORD-01');

        // Test endpoint filter = specific endpoint
        $endpointFilterResp = $this->actingAs($this->owner)
            ->get("/projects/{$this->project->id}/business-rules?endpoint_id={$this->endpoint->id}");
        $endpointFilterResp->assertSee('BR-ORD-01')
            ->assertDontSee('BR-GLB-01');
    }

    public function test_m08_search_is_case_insensitive_and_literal(): void
    {
        // Regression (S13-03 QA, stack D project 14): "br-w4" did not find BR-W4, while "%", "_" and
        // "BR_W" listed it, because the search used a raw, case-sensitive LIKE.
        BusinessRule::factory()->create([
            'project_id' => $this->project->id, 'endpoint_id' => null,
            'code' => 'BR-W4', 'description' => 'Đơn hàng phải có ít nhất một sản phẩm',
        ]);
        BusinessRule::factory()->create([
            'project_id' => $this->project->id, 'endpoint_id' => null,
            'code' => 'BR-XY', 'description' => 'Giảm giá 100% chỉ dành cho ADMIN',
        ]);
        $search = fn (string $term) => $this->actingAs($this->owner)
            ->get("/projects/{$this->project->id}/business-rules?search=".rawurlencode($term))
            ->assertOk();

        $search('br-w4')->assertSee('BR-W4')->assertDontSee('BR-XY');
        $search('ĐƠN HÀNG')->assertSee('BR-W4')->assertDontSee('BR-XY');
        $search('100%')->assertSee('BR-XY')->assertDontSee('BR-W4');
        // "%" only matches the description that really contains a percent sign.
        $search('%')->assertSee('BR-XY')->assertDontSee('BR-W4');
        foreach (['_', 'BR_W', '\\'] as $wildcard) {
            $search($wildcard)->assertDontSee('BR-W4')->assertDontSee('BR-XY');
        }
    }

    public function test_project_search_treats_like_wildcards_literally(): void
    {
        Project::factory()->create(['name' => 'Kho_A', 'owner_id' => $this->owner->id]);
        Project::factory()->create(['name' => 'KhoXA', 'owner_id' => $this->owner->id]);
        $search = fn (string $term) => $this->actingAs($this->owner)->get('/projects?search='.rawurlencode($term))->assertOk();

        $search('kho_a')->assertSee('Kho_A')->assertDontSee('KhoXA');
        $search('%')->assertDontSee('Kho_A')->assertDontSee('KhoXA')->assertDontSee('Demo E-commerce Project');
    }

    public function test_admin_can_view_and_manage_rules(): void
    {
        $response = $this->actingAs($this->admin)
            ->get("/projects/{$this->project->id}/business-rules");

        $response->assertOk()
            ->assertSee('Quy tắc nghiệp vụ');

        $storeResponse = $this->actingAs($this->admin)
            ->post("/projects/{$this->project->id}/business-rules", [
                'code' => 'BR-ADM-01',
                'description' => 'Admin created rule',
            ]);

        $storeResponse->assertRedirect("/projects/{$this->project->id}/business-rules");
        $this->assertDatabaseHas('business_rules', [
            'project_id' => $this->project->id,
            'code' => 'BR-ADM-01',
        ]);
    }

    public function test_owner_can_create_global_business_rule(): void
    {
        $response = $this->actingAs($this->owner)
            ->post("/projects/{$this->project->id}/business-rules", [
                'code' => 'BR-GLB-01',
                'endpoint_id' => '',
                'description' => 'Mọi request tạo dữ liệu phải kèm idempotency key trên header X-Idempotency-Key',
            ]);

        $response->assertRedirect("/projects/{$this->project->id}/business-rules");
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('business_rules', [
            'project_id' => $this->project->id,
            'endpoint_id' => null,
            'code' => 'BR-GLB-01',
        ]);
    }

    public function test_owner_can_create_endpoint_specific_business_rule(): void
    {
        $response = $this->actingAs($this->owner)
            ->post("/projects/{$this->project->id}/business-rules", [
                'code' => 'BR-ORD-01',
                'endpoint_id' => $this->endpoint->id,
                'description' => 'Tổng giá trị đơn hàng vượt quá 50 triệu bắt buộc phải thanh toán trước.',
            ]);

        $response->assertRedirect("/projects/{$this->project->id}/business-rules");
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('business_rules', [
            'project_id' => $this->project->id,
            'endpoint_id' => $this->endpoint->id,
            'code' => 'BR-ORD-01',
        ]);
    }

    public function test_cannot_create_rule_with_endpoint_belonging_to_another_project(): void
    {
        $otherProject = Project::factory()->create();
        $otherSpec = ApiSpec::factory()->create(['project_id' => $otherProject->id]);
        $otherEndpoint = Endpoint::factory()->create(['api_spec_id' => $otherSpec->id]);

        $response = $this->actingAs($this->owner)
            ->post("/projects/{$this->project->id}/business-rules", [
                'code' => 'BR-ERR-01',
                'endpoint_id' => $otherEndpoint->id,
                'description' => 'Mô tả rule sai endpoint',
            ]);

        $response->assertSessionHasErrors(['endpoint_id']);
        $this->assertDatabaseMissing('business_rules', ['code' => 'BR-ERR-01']);
    }

    public function test_cannot_create_duplicate_rule_code_in_same_project(): void
    {
        BusinessRule::factory()->create([
            'project_id' => $this->project->id,
            'code' => 'BR-DUP-01',
        ]);

        $response = $this->actingAs($this->owner)
            ->post("/projects/{$this->project->id}/business-rules", [
                'code' => 'BR-DUP-01',
                'description' => 'Duplicate code in same project',
            ]);

        $response->assertSessionHasErrors(['code']);
    }

    public function test_can_create_same_rule_code_in_different_projects(): void
    {
        $otherProject = Project::factory()->create(['owner_id' => $this->owner->id]);

        BusinessRule::factory()->create([
            'project_id' => $this->project->id,
            'code' => 'BR-SHARED-01',
        ]);

        $response = $this->actingAs($this->owner)
            ->post("/projects/{$otherProject->id}/business-rules", [
                'code' => 'BR-SHARED-01',
                'description' => 'Same code in different project is allowed',
            ]);

        $response->assertRedirect("/projects/{$otherProject->id}/business-rules");
        $this->assertDatabaseCount('business_rules', 2);
    }

    public function test_rule_code_is_normalized_to_uppercase(): void
    {
        $response = $this->actingAs($this->owner)
            ->post("/projects/{$this->project->id}/business-rules", [
                'code' => 'br-lower-01',
                'description' => 'Lowercase code input test',
            ]);

        $response->assertRedirect("/projects/{$this->project->id}/business-rules");
        $this->assertDatabaseHas('business_rules', [
            'project_id' => $this->project->id,
            'code' => 'BR-LOWER-01',
        ]);
    }

    public function test_invalid_rule_code_format_is_rejected(): void
    {
        $response = $this->actingAs($this->owner)
            ->post("/projects/{$this->project->id}/business-rules", [
                'code' => 'BR INVALID WITH SPACES',
                'description' => 'Test invalid code',
            ]);

        $response->assertSessionHasErrors(['code']);
    }

    public function test_rule_description_exceeding_10kb_bytes_is_rejected(): void
    {
        $largeDescription = str_repeat('a', 10241);

        $response = $this->actingAs($this->owner)
            ->post("/projects/{$this->project->id}/business-rules", [
                'code' => 'BR-LARGE-01',
                'description' => $largeDescription,
            ]);

        $response->assertSessionHasErrors(['description']);
    }

    public function test_owner_can_update_business_rule(): void
    {
        $rule = BusinessRule::factory()->create([
            'project_id' => $this->project->id,
            'endpoint_id' => null,
            'code' => 'BR-UPD-01',
            'description' => 'Original description',
        ]);

        $response = $this->actingAs($this->owner)
            ->patch("/projects/{$this->project->id}/business-rules/{$rule->id}", [
                'code' => 'BR-UPD-01',
                'endpoint_id' => $this->endpoint->id,
                'description' => 'Updated rule description',
            ]);

        $response->assertRedirect("/projects/{$this->project->id}/business-rules");
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('business_rules', [
            'id' => $rule->id,
            'endpoint_id' => $this->endpoint->id,
            'description' => 'Updated rule description',
        ]);
    }

    public function test_owner_can_delete_business_rule(): void
    {
        $rule = BusinessRule::factory()->create([
            'project_id' => $this->project->id,
            'code' => 'BR-DEL-01',
        ]);

        $response = $this->actingAs($this->owner)
            ->delete("/projects/{$this->project->id}/business-rules/{$rule->id}");

        $response->assertRedirect("/projects/{$this->project->id}/business-rules");
        $response->assertSessionHas('success');

        $this->assertDatabaseMissing('business_rules', ['id' => $rule->id]);
    }

    public function test_cannot_update_or_delete_rule_from_mismatched_project(): void
    {
        $otherProject = Project::factory()->create(['owner_id' => $this->owner->id]);
        $ruleOfOther = BusinessRule::factory()->create([
            'project_id' => $otherProject->id,
            'code' => 'BR-OTHER-01',
        ]);

        // Attempting to access ruleOfOther via $this->project route
        $this->actingAs($this->owner)
            ->patch("/projects/{$this->project->id}/business-rules/{$ruleOfOther->id}", [
                'code' => 'BR-OTHER-01',
                'description' => 'Hack update',
            ])->assertNotFound();

        $this->actingAs($this->owner)
            ->delete("/projects/{$this->project->id}/business-rules/{$ruleOfOther->id}")
            ->assertNotFound();
    }

    public function test_rule_with_js_expression_in_description_is_safely_escaped_with_js_directive(): void
    {
        $probe = '${globalThis.__reviewProbe=1}`hack`';
        $rule = BusinessRule::factory()->create([
            'project_id' => $this->project->id,
            'code' => 'BR-PROBE-01',
            'description' => $probe,
        ]);

        $response = $this->actingAs($this->owner)
            ->get("/projects/{$this->project->id}/business-rules");

        $response->assertOk();
        // Ensure no unescaped template literal with backticks in openEdit(...) call
        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringNotContainsString('description: `'.$probe.'`', $content);
        $this->assertStringNotContainsString('openEdit({', $content);
        $this->assertStringContainsString('openEdit(JSON.parse(', $content);
    }

    public function test_query_filter_with_array_returns_validation_error_instead_of_http_500(): void
    {
        // 1. Array in endpoint_id query param must not cause 500, and must return validation error
        $response = $this->actingAs($this->owner)
            ->get("/projects/{$this->project->id}/business-rules?endpoint_id[]=1");

        $response->assertStatus(302);
        $response->assertSessionHasErrors(['endpoint_id']);

        // 2. JSON request with array returns 422
        $jsonResponse = $this->actingAs($this->owner)
            ->getJson("/projects/{$this->project->id}/business-rules?endpoint_id[]=1");

        $jsonResponse->assertStatus(422)
            ->assertJsonValidationErrors(['endpoint_id']);

        // 3. Valid scalar query parameters succeed with 200 OK
        $this->actingAs($this->owner)
            ->get("/projects/{$this->project->id}/business-rules?endpoint_id=global")
            ->assertOk();

        $this->actingAs($this->owner)
            ->get("/projects/{$this->project->id}/business-rules?endpoint_id={$this->endpoint->id}")
            ->assertOk();
    }

    public function test_form_submission_with_array_input_redirects_and_renders_validation_errors_without_500(): void
    {
        // 1. Submit create form with array in endpoint_id
        $response = $this->actingAs($this->owner)
            ->from("/projects/{$this->project->id}/business-rules")
            ->post("/projects/{$this->project->id}/business-rules", [
                '_form' => 'create',
                'code' => 'BR-TEST-ARRAY',
                'description' => 'Valid description',
                'endpoint_id' => [1],
            ]);

        $response->assertStatus(302);
        $response->assertRedirect("/projects/{$this->project->id}/business-rules");
        $response->assertSessionHasErrors(['endpoint_id']);

        // 2. Following redirect (rendering M08 with old inputs as array) must return 200 OK without 500
        $redirectResponse = $this->get("/projects/{$this->project->id}/business-rules");
        $redirectResponse->assertOk();
        $redirectResponse->assertViewIs('business-rules.index');

        // 3. Test edit form with array inputs also does not cause 500 on validation redirect render
        $rule = BusinessRule::factory()->create([
            'project_id' => $this->project->id,
            'code' => 'BR-TEST-EDIT',
            'description' => 'Original description',
        ]);

        $editResponse = $this->actingAs($this->owner)
            ->from("/projects/{$this->project->id}/business-rules")
            ->patch("/projects/{$this->project->id}/business-rules/{$rule->id}", [
                '_form' => 'edit',
                'rule_id' => [$rule->id],
                'code' => ['BR-HACK'],
                'description' => ['Array description'],
                'endpoint_id' => [1],
            ]);

        $editResponse->assertStatus(302);
        $editResponse->assertRedirect("/projects/{$this->project->id}/business-rules");
        $editResponse->assertSessionHasErrors(['code', 'description', 'endpoint_id']);

        $editRedirectResponse = $this->get("/projects/{$this->project->id}/business-rules");
        $editRedirectResponse->assertOk();
        $editRedirectResponse->assertViewIs('business-rules.index');
    }

    /**
     * Regression (QA S13-03): a non-integer endpoint_id still reached the ownership closure, which
     * compared it with a bigint column (SQLSTATE 22P02) and answered 500 INTERNAL_ERROR.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function invalidEndpointIds(): array
    {
        return [
            'text' => ['abc'],
            'parenthesis' => [')'],
            'float' => [1.5],
            'exponent' => [1e20],
            'above int64' => [99999999999999999999],
            'numeric string above int64' => ['99999999999999999999'],
            'zero' => [0],
            'negative' => [-3],
            'boolean' => [true],
        ];
    }

    #[DataProvider('invalidEndpointIds')]
    public function test_api_store_rejects_non_integer_endpoint_id_with_422(mixed $endpointId): void
    {
        $this->actingAs($this->owner)
            ->postJson("/api/v1/projects/{$this->project->id}/business-rules", [
                'code' => 'BR-X',
                'description' => 'x',
                'endpoint_id' => $endpointId,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['endpoint_id']]]);

        $this->assertDatabaseMissing('business_rules', ['code' => 'BR-X']);
    }

    #[DataProvider('invalidEndpointIds')]
    public function test_api_patch_rejects_non_integer_endpoint_id_with_422(mixed $endpointId): void
    {
        $rule = BusinessRule::factory()->create(['project_id' => $this->project->id, 'endpoint_id' => null]);

        $this->actingAs($this->owner)
            ->patchJson("/api/v1/business-rules/{$rule->id}", ['endpoint_id' => $endpointId])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['endpoint_id']]]);

        $this->assertNull($rule->fresh()?->endpoint_id);
    }

    public function test_web_forms_reject_non_integer_endpoint_id_without_500(): void
    {
        $rule = BusinessRule::factory()->create(['project_id' => $this->project->id, 'code' => 'BR-WEB-EDIT']);

        foreach (['abc', '1.5', '99999999999999999999', ' 1'] as $endpointId) {
            $this->actingAs($this->owner)
                ->from("/projects/{$this->project->id}/business-rules")
                ->post("/projects/{$this->project->id}/business-rules", [
                    '_form' => 'create',
                    'code' => 'BR-WEB-X',
                    'description' => 'x',
                    'endpoint_id' => $endpointId,
                ])
                ->assertRedirect("/projects/{$this->project->id}/business-rules")
                ->assertSessionHasErrors(['endpoint_id']);

            $this->actingAs($this->owner)
                ->from("/projects/{$this->project->id}/business-rules")
                ->patch("/projects/{$this->project->id}/business-rules/{$rule->id}", [
                    '_form' => 'edit',
                    'code' => 'BR-WEB-EDIT',
                    'description' => 'x',
                    'endpoint_id' => $endpointId,
                ])
                ->assertRedirect("/projects/{$this->project->id}/business-rules")
                ->assertSessionHasErrors(['endpoint_id']);
        }

        $this->assertDatabaseMissing('business_rules', ['code' => 'BR-WEB-X']);
        $this->get("/projects/{$this->project->id}/business-rules")->assertOk();
    }

    public function test_numeric_string_endpoint_id_from_the_form_is_still_accepted(): void
    {
        $this->actingAs($this->owner)
            ->post("/projects/{$this->project->id}/business-rules", [
                '_form' => 'create',
                'code' => 'BR-WEB-OK',
                'description' => 'x',
                'endpoint_id' => (string) $this->endpoint->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('business_rules', ['code' => 'BR-WEB-OK', 'endpoint_id' => $this->endpoint->id]);
    }
}
