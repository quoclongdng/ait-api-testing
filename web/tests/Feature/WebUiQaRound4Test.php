<?php

namespace Tests\Feature;

use App\Contracts\AiGeneratorClient;
use App\Enums\ApiSpecStatus;
use App\Models\AllowedTarget;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Models\Project;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\Ai\FakeAiGeneratorClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression tests for the S13-03 QA findings of area "web-ui" (modals, accessibility, Vietnamese
 * copy and M03/M04/M05/M08 behaviour) before the public deploy.
 */
class WebUiQaRound4Test extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Project, 2: ApiSpec, 3: TestSuite}
     */
    private function ownedSuite(): array
    {
        $tester = User::factory()->tester()->create();
        $project = Project::factory()->for($tester, 'owner')->create();
        $spec = ApiSpec::factory()->for($project)->create(['status' => ApiSpecStatus::Parsed]);
        $suite = TestSuite::factory()->create([
            'project_id' => $project->id,
            'api_spec_id' => $spec->id,
            'created_by' => $tester->id,
        ]);

        return [$tester, $project, $spec, $suite];
    }

    /** Markup of the first role="dialog" element up to the end of its form. */
    private function dialogHtml(string $html, string $labelledBy): string
    {
        $start = strpos($html, 'aria-labelledby="'.$labelledBy.'"');
        $this->assertNotFalse($start, "dialog {$labelledBy} not found");
        $end = strpos($html, '</form>', $start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    // --- Server-side errors are shown inside the reopened modal -----------------------------

    public function test_m03_create_user_error_is_rendered_inside_the_dialog_not_behind_it(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->tester()->create(['email' => 'qa-t1@ait.local']);

        $html = $this->actingAs($admin)->from('/admin/users')->followingRedirects()->post('/admin/users', [
            '_form' => 'create-user',
            'name' => 'QA',
            'email' => 'qa-t1@ait.local',
            'password' => 'secret-pass-123',
            'role' => 'TESTER',
            'is_active' => '1',
        ])->assertOk()->getContent();

        $dialog = $this->dialogHtml((string) $html, 'create-user-modal-title');
        $this->assertStringContainsString('role="alert"', $dialog);
        $this->assertStringContainsString('id="modal-email-error"', $dialog);
        $this->assertMatchesRegularExpression('/id="modal-email"[^>]*aria-invalid="true" aria-describedby="modal-email-error"/s', $dialog);
        // The modal is reopened with the input and "Kích hoạt" kept, the banner behind it is skipped.
        $this->assertStringContainsString('createModalOpen: true', (string) $html);
        $this->assertStringContainsString('value="qa-t1@ait.local"', $dialog);
        $this->assertMatchesRegularExpression('/id="modal-is-active"\s+value="1"\s+checked/s', $dialog);
        $this->assertStringNotContainsString('data-testid="validation-banner"', (string) $html);
    }

    public function test_m03_row_action_error_does_not_open_the_create_modal(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->tester()->create();

        $html = $this->actingAs($admin)->from('/admin/users')->followingRedirects()
            ->post("/admin/users/{$admin->id}/toggle-status", ['_form' => 'toggle-status', 'is_active' => '0'])
            ->assertOk()->getContent();

        $this->assertStringContainsString('createModalOpen: false', (string) $html);
        $this->assertStringContainsString('data-testid="validation-banner"', (string) $html);
        $this->assertStringContainsString('Không thể tự khóa', (string) $html);
        $this->assertStringContainsString('Đã xảy ra lỗi, vui lòng kiểm tra lại:', (string) $html);
        $this->assertStringNotContainsString('data-testid="form-errors"', (string) $html);
        // The lock form now identifies itself, like the role form.
        $this->assertStringContainsString('name="_form" value="toggle-status"', (string) $html);
    }

    public function test_m04_duplicate_host_error_is_rendered_inside_the_dialog(): void
    {
        $admin = User::factory()->admin()->create();
        AllowedTarget::factory()->create(['host' => 'qa-m-target.example', 'port' => 9000, 'created_by' => $admin->id]);

        $html = (string) $this->actingAs($admin)->from('/admin/allowed-targets')->followingRedirects()
            ->post('/admin/allowed-targets', ['_form' => 'create-allowed-target', 'host' => 'qa-m-target.example', 'port' => 9000])
            ->assertOk()->getContent();

        $dialog = $this->dialogHtml($html, 'allowed-target-modal-title');
        $this->assertStringContainsString('Host và cổng này đã có trong allowlist.', $dialog);
        $this->assertStringContainsString('role="alert"', $dialog);
        $this->assertStringContainsString('aria-invalid="true"', $dialog);
        $this->assertStringNotContainsString('data-testid="validation-banner"', $html);
    }

    public function test_m05_m08_m09_modal_errors_are_rendered_inside_the_dialog(): void
    {
        [$tester, $project, $spec] = $this->ownedSuite();

        $html = (string) $this->actingAs($tester)->from('/projects')->followingRedirects()
            ->post('/projects', ['_form' => 'create-project', 'name' => 'P', 'default_target_url' => 'ftp://x'])
            ->assertOk()->getContent();
        $this->assertStringContainsString('id="create-project-target-error"', $this->dialogHtml($html, 'create-project-modal-title'));

        $rules = "/projects/{$project->id}/business-rules";
        $html = (string) $this->actingAs($tester)->from($rules)->followingRedirects()
            ->post($rules, ['_form' => 'create', 'code' => 'mã sai !!', 'description' => 'Mô tả'])
            ->assertOk()->getContent();
        $dialog = $this->dialogHtml($html, 'create-rule-modal-title');
        $this->assertStringContainsString('id="create-code-error"', $dialog);
        $this->assertStringContainsString('role="alert"', $dialog);
        $this->assertStringNotContainsString('data-testid="validation-banner"', $html);

        $suites = "/projects/{$project->id}/suites";
        $html = (string) $this->actingAs($tester)->from($suites)->followingRedirects()
            ->post($suites, ['_form' => 'create-suite', 'api_spec_id' => $spec->id, 'name' => str_repeat('a', 300)])
            ->assertOk()->getContent();
        $dialog = $this->dialogHtml($html, 'create-suite-modal-title');
        $this->assertStringContainsString('id="create-suite-name-error"', $dialog);
        $this->assertMatchesRegularExpression('/id="create-suite-name"[^>]*aria-invalid="true"/s', $dialog);
    }

    // --- Dialog focus management and labels -------------------------------------------------

    public function test_every_modal_moves_and_traps_focus_and_is_labelled(): void
    {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->for($admin, 'owner')->create();
        ApiSpec::factory()->for($project)->create(['status' => ApiSpecStatus::Parsed]);

        $pages = [
            '/projects', "/projects/{$project->id}/suites", "/projects/{$project->id}/business-rules",
            '/admin/users', '/admin/allowed-targets', '/admin/benchmarks',
        ];
        foreach ($pages as $page) {
            $html = (string) $this->actingAs($admin)->get($page)->assertOk()->getContent();
            preg_match_all('/<div[^>]*role="dialog"[^>]*>/s', $html, $dialogs);
            $this->assertNotEmpty($dialogs[0], "no dialog on {$page}");
            foreach ($dialogs[0] as $tag) {
                $this->assertStringContainsString('x-modal-focus=', $tag, "{$page}: dialog without focus management");
                $this->assertSame(1, preg_match('/aria-labelledby="([^"]+)"/', $tag, $label), "{$page}: dialog without aria-labelledby");
                $this->assertStringContainsString('id="'.($label[1] ?? '').'"', $html, "{$page}: aria-labelledby target missing");
            }
        }

        $plugin = (string) file_get_contents(resource_path('js/modal-focus.js'));
        $this->assertStringContainsString("Alpine.directive('modal-focus'", $plugin);
        $this->assertStringContainsString('trapTab', $plugin);
        $this->assertStringContainsString('returnTo.focus()', $plugin);
        $this->assertStringContainsString('Alpine.plugin(modalFocus)', (string) file_get_contents(resource_path('js/app.js')));
    }

    public function test_inline_project_editor_moves_focus_to_the_name_field(): void
    {
        [$tester, $project] = $this->ownedSuite();

        $this->actingAs($tester)->get('/projects')->assertOk()
            ->assertSee('x-ref="editName"', false)
            ->assertSee('@click="editing = true; $nextTick(() => $refs.editName.focus())"', false);
    }

    // --- Layout accessibility ----------------------------------------------------------------

    public function test_layout_has_skip_link_live_regions_and_current_page(): void
    {
        [$tester, $project, , $suite] = $this->ownedSuite();

        $html = (string) $this->actingAs($tester)->withSession(['success' => 'Đã lưu.'])->get("/suites/{$suite->id}")
            ->assertOk()->getContent();
        $this->assertStringContainsString('href="#main"', $html);
        $this->assertStringContainsString('Bỏ qua đến nội dung chính', $html);
        $this->assertStringContainsString('<main id="main"', $html);
        $this->assertMatchesRegularExpression('/role="status" data-testid="flash-success"/', $html);
        // A suite page links the header to its own project's M06/M08 screens.
        $this->assertStringContainsString('href="'.route('projects.specs.index', $project).'"', $html);
        $this->assertStringContainsString('href="'.route('projects.business-rules.index', $project).'"', $html);

        $html = (string) $this->actingAs($tester)->withSession(['error' => 'Lỗi.'])->get('/projects')->getContent();
        $this->assertStringContainsString('role="alert" data-testid="flash-error"', $html);
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('projects.index'), '#').'"\s+aria-current="page"#', $html);
    }

    public function test_layout_does_not_link_a_project_the_user_cannot_view(): void
    {
        [, $project] = $this->ownedSuite();
        $other = User::factory()->tester()->create();

        $this->actingAs($other)->get('/dashboard')->assertOk()
            ->assertDontSee(route('projects.specs.index', $project), false);
    }

    public function test_pagination_disabled_items_carry_no_prohibited_aria(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->count(12)->tester()->create();

        $html = (string) $this->actingAs($admin)->get('/admin/users')->assertOk()->getContent();
        $this->assertStringContainsString('Trang trước (không khả dụng)', $html);
        $this->assertStringNotContainsString('aria-disabled="true" aria-label="Trang trước"', $html);
    }

    // --- M03 polish ---------------------------------------------------------------------------

    public function test_m03_has_no_doubled_plus_bracket_labels_or_wrapping_pills(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->tester()->create();

        $html = (string) $this->actingAs($admin)->get('/admin/users')->assertOk()->getContent();
        $this->assertStringNotContainsString('+ Cấp tài khoản mới', $html);
        $this->assertStringNotContainsString('[Khóa]', $html);
        $this->assertStringNotContainsString('[ Lưu tài khoản ]', $html);
        $this->assertStringContainsString('whitespace-nowrap px-2.5 py-1 rounded-full', $html);
        $this->assertStringContainsString('autocomplete="new-password"', $html);
    }

    public function test_m03_out_of_range_page_redirects_to_the_last_page(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->count(12)->tester()->create();

        $this->actingAs($admin)->get('/admin/users?page=99&role=TESTER')
            ->assertRedirect(route('admin.users.index', ['role' => 'TESTER', 'page' => 2]));
    }

    public function test_m03_row_actions_return_to_the_filtered_list(): void
    {
        $admin = User::factory()->admin()->create();
        $tester = User::factory()->tester()->create(['is_active' => false, 'email' => 'qa-m@ait.local']);
        $filtered = route('admin.users.index', ['search' => 'qa-m', 'status' => '0']);

        $this->actingAs($admin)->from($filtered)
            ->post("/admin/users/{$tester->id}/toggle-status", ['_form' => 'toggle-status', 'is_active' => '1'])
            ->assertRedirect($filtered);

        // A forged Referer outside M03 never becomes the redirect target.
        $this->actingAs($admin)->from('https://evil.example/admin/users')
            ->post("/admin/users/{$tester->id}/toggle-status", ['_form' => 'toggle-status', 'is_active' => '0'])
            ->assertRedirect(route('admin.users.index'));
    }

    public function test_admin_user_api_messages_are_vietnamese(): void
    {
        $admin = User::factory()->admin()->create();
        $tester = User::factory()->tester()->create();

        $this->actingAs($admin)->getJson('/api/v1/admin/users?per_page=1000')
            ->assertStatus(422)
            ->assertJsonPath('error.details.per_page.0', 'Số bản ghi mỗi trang không được lớn hơn 100.');

        $this->actingAs($admin)->patchJson("/api/v1/admin/users/{$tester->id}", ['name' => ''])
            ->assertStatus(422)
            ->assertJsonPath('error.details.name', ['Họ và tên là bắt buộc.']);
    }

    public function test_list_filters_and_ids_use_vietnamese_attribute_names(): void
    {
        [$tester, , , $suite] = $this->ownedSuite();

        $this->actingAs($tester)->getJson('/api/v1/projects?page=abc')
            ->assertStatus(422)
            ->assertJsonPath('error.details.page.0', 'Trang phải là số nguyên.');
        $this->actingAs($tester)->postJson("/api/v1/suites/{$suite->id}/runs", [
            'target_base_url' => 'http://benchmark:8000',
            'test_case_ids' => [1.5],
        ])->assertStatus(422)
            ->assertJsonPath('error.details', fn (array $details): bool => str_starts_with((string) ($details['test_case_ids.0'][0] ?? ''), 'Mã test case'));

        // Spec upload API (S13-03 review): no raw key such as "raw content" in error.details.
        $project = $suite->project;
        $missing = $this->actingAs($tester)->postJson("/api/v1/projects/{$project->id}/specs", [
            'version_label' => 'v1', 'format' => 'yaml',
        ])->assertStatus(422);
        $this->assertNotEmpty($missing->json('error.details.raw_content'));
        $wrongTypes = $this->actingAs($tester)->postJson("/api/v1/projects/{$project->id}/specs", [
            'version_label' => 'v1', 'format' => 'yaml', 'raw_content' => ['x'], 'source_filename' => ['y'],
        ])->assertStatus(422);
        $this->assertStringContainsStringIgnoringCase('nội dung đặc tả', (string) $wrongTypes->json('error.details.raw_content.0'));
        $this->assertStringContainsStringIgnoringCase('tên tệp gốc', (string) $wrongTypes->json('error.details.source_filename.0'));
        foreach ([$missing, $wrongTypes] as $response) {
            $messages = implode(' ', array_merge(...array_values((array) $response->json('error.details'))));
            foreach (['raw content', 'raw_content', 'source filename', 'source_filename'] as $rawKey) {
                $this->assertStringNotContainsString($rawKey, $messages);
            }
        }
    }

    public function test_admin_root_redirects_to_the_admin_dashboard(): void
    {
        $admin = User::factory()->admin()->create();

        // S14-04: the ADMIN dashboard "Tổng quan quản trị" replaced the redirect to M03.
        $this->actingAs($admin)->get('/admin')->assertRedirect('/dashboard');
    }

    // --- LLM disabled (ADR-0007 production profile) -------------------------------------------

    public function test_m04_and_m02_say_the_llm_is_disabled_instead_of_showing_placeholders(): void
    {
        $ai = new FakeAiGeneratorClient;
        $ai->setProvider('disabled');
        $this->app->instance(AiGeneratorClient::class, $ai);
        $admin = User::factory()->admin()->create();

        $html = (string) $this->actingAs($admin)->get('/admin/allowed-targets')->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="m04-llm-disabled-badge"', $html);
        $this->assertStringContainsString('LLM đã tắt trên bản triển khai này', $html);
        $this->assertStringNotContainsString('ai-service trực tuyến', $html);
        $this->assertMatchesRegularExpression('/id="llm-model"[^>]*>—</', $html);
        $this->assertMatchesRegularExpression('/id="llm-temperature-seed"[^>]*>—</', $html);

        $this->actingAs($admin)->get('/dashboard')->assertOk()
            ->assertSee('data-testid="dashboard-llm-disabled"', false)
            ->assertSee('LLM đã tắt (chỉ RULE)')
            ->assertDontSee('OK (disabled)');
    }

    // --- M02 / M05 / M08 -----------------------------------------------------------------------

    public function test_dashboard_copy_is_vietnamese_and_upload_opens_the_latest_project(): void
    {
        [$tester, $project] = $this->ownedSuite();

        $html = (string) $this->actingAs($tester)->get('/dashboard')->assertOk()->getContent();
        $this->assertStringNotContainsString('Projects đang hoạt động', $html);
        $this->assertStringNotContainsString('Cases đạt tiêu chuẩn', $html);
        $this->assertStringNotContainsString('(TEST RUNS)', $html);
        $this->assertStringNotContainsString('+ Tạo Project mới', $html);
        $this->assertMatchesRegularExpression(
            '#href="'.preg_quote(route('projects.specs.index', $project), '#').'" data-testid="quick-upload-openapi"#',
            $html,
        );
    }

    public function test_m05_project_card_is_vietnamese_without_duplicate_links(): void
    {
        [$tester, $project] = $this->ownedSuite();

        $html = (string) $this->actingAs($tester)->get('/projects')->assertOk()->getContent();
        $this->assertStringContainsString('Số đặc tả OpenAPI', $html);
        $this->assertStringContainsString('Số bộ kiểm thử', $html);
        $this->assertStringNotContainsString('OpenAPI Specs', $html);
        $this->assertSame(1, substr_count($html, 'href="'.route('projects.specs.index', $project).'" class="rounded-xl'));
    }

    /**
     * Regression (S13-03 review): `class=""rounded-2xl ...` ended the attribute as an empty string,
     * stripping all styling from every M05 project card and the M02 "Tải lên OpenAPI" button.
     */
    public function test_no_view_has_a_doubled_quote_after_an_attribute(): void
    {
        $offenders = [];
        $views = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS));
        foreach ($views as $file) {
            if (! $file instanceof \SplFileInfo || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            foreach (file($file->getPathname()) ?: [] as $number => $line) {
                if (preg_match('/\b[a-z:@.-]+=""[A-Za-z{]/', $line) === 1) {
                    $offenders[] = $file->getPathname().':'.($number + 1);
                }
            }
        }
        $this->assertSame([], $offenders);

        [$tester, $project] = $this->ownedSuite();
        $this->actingAs($tester)->get('/projects')->assertOk()
            ->assertSee('aria-labelledby="project-title-'.$project->id.'"'."\n".'                    class="rounded-2xl border border-slate-800 bg-slate-800/40 p-5 shadow-xl">', false);
        $this->actingAs($tester)->get('/dashboard')->assertOk()
            ->assertSee('data-testid="quick-upload-openapi" class="inline-flex min-w-0', false);
    }

    /**
     * Regression (S13-03 review): the target input had a static aria-describedby (hint) before the
     * one printed by invalid(); browsers keep the first, so the error was never linked.
     */
    public function test_m05_target_error_and_hint_share_one_aria_describedby(): void
    {
        [$tester, $project] = $this->ownedSuite();

        $html = (string) $this->actingAs($tester)->get('/projects')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input id="create-project-target"[^>]*aria-describedby="create-project-target-hint"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input id="create-project-target"[^>]*aria-invalid/', $html);

        $html = (string) $this->actingAs($tester)->from('/projects')->followingRedirects()
            ->post('/projects', ['_form' => 'create-project', 'name' => 'P', 'default_target_url' => 'ftp://x'])
            ->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<input id="create-project-target"[^>]*>/', $html, $tag));
        $this->assertSame(1, substr_count($tag[0] ?? '', 'aria-describedby='));
        $this->assertStringContainsString('aria-invalid="true" aria-describedby="create-project-target-hint create-project-target-error"', $tag[0] ?? '');

        $html = (string) $this->actingAs($tester)->from('/projects')->followingRedirects()
            ->patch("/projects/{$project->id}", ['_form' => 'edit-project-'.$project->id, 'name' => 'P', 'default_target_url' => 'ftp://x'])
            ->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<input id="edit-target-'.$project->id.'"[^>]*>/', $html, $tag));
        $this->assertSame(1, substr_count($tag[0] ?? '', 'aria-describedby='));
        $this->assertStringContainsString(
            'aria-describedby="edit-target-'.$project->id.'-hint edit-target-'.$project->id.'-error"',
            $tag[0] ?? '',
        );
    }

    public function test_m05_warns_when_the_default_target_is_outside_the_allowlist(): void
    {
        $tester = User::factory()->tester()->create();

        $this->actingAs($tester)->post('/projects', [
            '_form' => 'create-project',
            'name' => 'Metadata',
            'default_target_url' => 'http://169.254.169.254/latest',
        ])->assertRedirect('/projects')->assertSessionHas('warning', fn (string $w): bool => str_contains($w, 'allowlist'));

        $this->actingAs($tester)->post('/projects', [
            '_form' => 'create-project',
            'name' => 'Benchmark',
            'default_target_url' => 'http://benchmark:8000',
        ])->assertRedirect('/projects')->assertSessionMissing('warning');
    }

    public function test_m08_endpoint_options_are_grouped_by_spec_version(): void
    {
        [$tester, $project, $spec] = $this->ownedSuite();
        $spec->update(['version_label' => 'ss31-yaml', 'source_filename' => 'a.yaml']);
        $second = ApiSpec::factory()->for($project)->create([
            'status' => ApiSpecStatus::Parsed, 'version_label' => 'ss31-json', 'source_filename' => 'b.json',
        ]);
        Endpoint::factory()->create(['api_spec_id' => $spec->id, 'method' => 'GET', 'path' => '/categories']);
        Endpoint::factory()->create(['api_spec_id' => $second->id, 'method' => 'GET', 'path' => '/categories']);

        $html = (string) $this->actingAs($tester)->get("/projects/{$project->id}/business-rules")->assertOk()->getContent();
        $this->assertStringContainsString('<optgroup label="Phiên bản ss31-yaml (a.yaml)">', $html);
        $this->assertStringContainsString('<optgroup label="Phiên bản ss31-json (b.json)">', $html);
        // Newest spec first.
        $this->assertLessThan(strpos($html, 'Phiên bản ss31-yaml'), strpos($html, 'Phiên bản ss31-json'));
        $this->assertStringNotContainsString('M08 SCREEN', $html);
    }
}
