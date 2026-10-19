<?php

namespace Tests\Feature;

use App\Enums\ApiSpecStatus;
use App\Enums\TestCaseStatus;
use App\Models\ApiSpec;
use App\Models\Project;
use App\Models\TestCase as TestCaseModel;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S8-02: M10 review actions (edit DSL, approve, archive) wired to /api/v1.
 */
class TestCaseReviewScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private TestSuite $suite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->tester()->create();
        $project = Project::factory()->for($this->owner, 'owner')->create();
        $spec = ApiSpec::factory()->for($project)->create(['status' => ApiSpecStatus::Parsed]);
        $this->suite = TestSuite::factory()->create([
            'project_id' => $project->id,
            'api_spec_id' => $spec->id,
            'created_by' => $this->owner->id,
        ]);
    }

    public function test_owner_and_admin_see_review_actions_for_valid_case(): void
    {
        $case = $this->makeCase(TestCaseStatus::Valid);
        $admin = User::factory()->admin()->create();

        foreach ([$this->owner, $admin] as $user) {
            $this->actingAs($user)->get("/test-cases/{$case->id}")
                ->assertOk()
                ->assertSee('Duyệt test case (Approve)')
                ->assertSee('Lưu trữ (Archive)')
                ->assertSee('CHỈNH SỬA DSL TRỰC TIẾP')
                ->assertSee('Lưu thay đổi DSL')
                ->assertSee('phải được duyệt lại')
                // @js() escapes slashes inside the Alpine config
                ->assertSee(addcslashes(route('api.v1.test-cases.approve', $case), '/'), false)
                ->assertSee(addcslashes(route('api.v1.test-cases.archive', $case), '/'), false)
                ->assertSee(addcslashes(route('api.v1.test-cases.update', $case), '/'), false);
        }
    }

    public function test_approved_case_can_only_be_archived_or_edited(): void
    {
        $case = $this->makeCase(TestCaseStatus::Approved);

        $this->actingAs($this->owner)->get("/test-cases/{$case->id}")
            ->assertOk()
            ->assertDontSee('Duyệt test case (Approve)')
            ->assertSee('Lưu trữ (Archive)')
            ->assertSee('Lưu thay đổi DSL');
    }

    public function test_invalid_case_shows_editor_but_no_approve_or_archive(): void
    {
        $case = $this->makeCase(TestCaseStatus::Invalid, ['validation_errors' => ['Endpoint POST /missing không có trong spec']]);

        $this->actingAs($this->owner)->get("/test-cases/{$case->id}")
            ->assertOk()
            ->assertDontSee('Duyệt test case (Approve)')
            ->assertDontSee('Lưu trữ (Archive)')
            ->assertSee('Lưu thay đổi DSL')
            ->assertSee('Endpoint POST /missing không có trong spec');
    }

    public function test_archived_and_duplicate_cases_are_read_only(): void
    {
        foreach ([TestCaseStatus::Archived, TestCaseStatus::Duplicate] as $status) {
            $case = $this->makeCase($status);

            $this->actingAs($this->owner)->get("/test-cases/{$case->id}")
                ->assertOk()
                ->assertSee('chỉ được xem, không thể sửa DSL')
                ->assertDontSee('Lưu thay đổi DSL')
                ->assertDontSee('Duyệt test case (Approve)')
                ->assertDontSee('Lưu trữ (Archive)');
        }
    }

    public function test_untrusted_dsl_is_escaped_on_m10(): void
    {
        $case = $this->makeCase(TestCaseStatus::Invalid, ['title' => '<script>alert(1)</script>']);

        $this->actingAs($this->owner)->get("/test-cases/{$case->id}")
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_other_tester_is_forbidden_and_guest_redirected(): void
    {
        $case = $this->makeCase(TestCaseStatus::Valid);
        $other = User::factory()->tester()->create();

        $this->actingAs($other)->get("/test-cases/{$case->id}")->assertForbidden();
        $this->actingAs($other)->post("/test-cases/{$case->id}/approve")->assertForbidden();
        $this->assertSame(TestCaseStatus::Valid, $case->refresh()->status);

        auth()->logout();
        $this->get("/test-cases/{$case->id}")->assertRedirect('/login');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeCase(TestCaseStatus $status, array $attributes = []): TestCaseModel
    {
        return TestCaseModel::factory()->create(array_merge([
            'test_suite_id' => $this->suite->id,
            'status' => $status,
        ], $attributes));
    }
}
