<?php

namespace Tests\Feature;

use App\Actions\Experiments\BuildExperimentComparisonAction;
use App\Contracts\AiGeneratorClient;
use App\Enums\ApiSpecStatus;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\HttpMethod;
use App\Enums\TestRunStatus;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Models\GenerationJob;
use App\Models\LlmCallLog;
use App\Models\Project;
use App\Models\TestCase as TestCaseModel;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\Ai\FakeAiGeneratorClient;
use App\Services\Dsl\DslValidator;
use App\Support\StepLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Regression tests for the S13-03 QA findings of area "web-ui": Vietnamese copy (no internal
 * references, translated validator/assertion messages), contrast, scrollable regions, M06/M07 parse
 * errors, M10 LLM logs, M11 progress and M12 cancel progress.
 */
class WebUiQaRound4CopyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Project, 2: ApiSpec, 3: TestSuite, 4: Endpoint}
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
        $endpoint = Endpoint::factory()->create([
            'api_spec_id' => $spec->id,
            'method' => HttpMethod::Post,
            'path' => '/categories',
            'responses' => ['201' => ['description' => 'Created']],
        ]);

        return [$tester, $project, $spec, $suite, $endpoint];
    }

    /** Text a user can read: no <script>/<style> blocks, comments or tags. */
    private function visibleText(string $html): string
    {
        $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#si', ' ', $html);
        $html = (string) preg_replace('/<!--.*?-->/s', ' ', $html);

        return html_entity_decode(strip_tags($html));
    }

    // --- Contrast & scrollable regions ---------------------------------------------------------

    public function test_views_do_not_use_low_contrast_slate_text_on_the_dark_ui(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))) as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php') || $file->getFilename() === 'run-export.blade.php') {
                continue;
            }
            if (preg_match('/\btext-slate-(500|600)\b/', (string) file_get_contents($file->getPathname())) === 1) {
                $offenders[] = $file->getPathname();
            }
        }

        // text-slate-500/600 on slate-800/900 is 2.3–3.7:1, below WCAG AA 4.5:1 for small text.
        $this->assertSame([], $offenders);
    }

    public function test_horizontal_scroll_containers_are_keyboard_focusable_regions(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))) as $file) {
            $name = $file->getPathname();
            if (! $file->isFile() || ! str_ends_with($name, '.blade.php') || str_ends_with($name, 'generation/create.blade.php')) {
                continue;
            }
            preg_match_all('/<div\b[^>]*\boverflow-[xy]-auto\b[^>]*>/', (string) file_get_contents($name), $matches);
            foreach ($matches[0] as $tag) {
                if (str_contains($tag, 'fixed inset-0')) {
                    continue;
                }
                if (! str_contains($tag, 'tabindex="0"') || ! str_contains($tag, 'role="region"') || ! str_contains($tag, 'aria-label=')) {
                    $offenders[] = $name.': '.$tag;
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_status_badges_do_not_wrap_into_a_circle(): void
    {
        $this->assertStringContainsString('whitespace-nowrap', (string) file_get_contents(resource_path('views/components/status-badge.blade.php')));
        $this->assertStringContainsString('whitespace-nowrap', (string) file_get_contents(resource_path('views/components/run-status-badge.blade.php')));
    }

    // --- Login --------------------------------------------------------------------------------

    public function test_login_form_has_autocomplete_and_announced_errors(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('autocomplete="username"', false)
            ->assertSee('autocomplete="current-password"', false);

        $html = (string) $this->from('/login')->followingRedirects()
            ->post('/login', ['email' => 'nobody@ait.local', 'password' => 'wrong-password'])
            ->getContent();
        $this->assertStringContainsString('id="login-errors" role="alert"', $html);
        $this->assertMatchesRegularExpression('/id="email"[^>]*aria-invalid="true" aria-describedby="login-errors"/s', $html);
    }

    // --- No internal references or configuration names in end-user copy ------------------------

    public function test_tester_pages_show_no_internal_references_on_the_public_profile(): void
    {
        $ai = new FakeAiGeneratorClient;
        $ai->setProvider('disabled');
        $this->app->instance(AiGeneratorClient::class, $ai);
        config(['ait.experiments.enabled' => false]);
        [$tester, $project, , $suite, $endpoint] = $this->ownedSuite();
        $job = GenerationJob::factory()->create([
            'project_id' => $project->id, 'api_spec_id' => $suite->api_spec_id, 'test_suite_id' => $suite->id,
            'strategy' => GenerationStrategy::Llm, 'status' => GenerationJobStatus::Queued, 'created_by' => $tester->id,
        ]);
        $case = TestCaseModel::factory()->create(['test_suite_id' => $suite->id, 'endpoint_id' => $endpoint->id]);

        $pages = [
            '/dashboard', '/projects', "/projects/{$project->id}/business-rules", "/projects/{$project->id}/specs",
            "/suites/{$suite->id}", "/suites/{$suite->id}/generation-jobs/create", "/generation-jobs/{$job->id}",
            "/test-cases/{$case->id}", '/experiments',
        ];
        foreach ($pages as $page) {
            $text = $this->visibleText((string) $this->actingAs($tester)->get($page)->assertOk()->getContent());
            foreach (['§', 'docs/DESIGN.md', 'AI_LLM_', 'EXPERIMENTS_ENABLED', 'make experiment', 'ai-service/.env', 'SCREEN', '(D03)', '(D14)'] as $needle) {
                $this->assertStringNotContainsString($needle, $text, "{$page} shows {$needle}");
            }
        }
    }

    public function test_m15_metric_names_have_a_vietnamese_gloss(): void
    {
        $rows = BuildExperimentComparisonAction::ROWS;

        $this->assertSame('Tỉ lệ biến thể bị phát hiện (Mutation Score)', $rows['mutation_score'][0]);
        $this->assertSame('Độ phủ endpoint (Endpoint Coverage)', $rows['endpoint_coverage'][0]);
        $this->assertSame('Token đầu vào (prompt)', $rows['prompt_tokens'][0]);
    }

    // --- Validator and assertion messages -------------------------------------------------------

    public function test_dsl_schema_errors_are_vietnamese_with_the_location(): void
    {
        $dsl = json_decode((string) file_get_contents(base_path('../docs/contracts/examples/valid-boundary-price-limit.json')), true);
        $this->assertIsArray($dsl);
        $dsl['steps'][0]['assertions'][] = ['type' => 'json_path', 'path' => '$.x', 'equals' => 1];
        $count = count($dsl['steps'][0]['assertions']) - 1;

        $result = (new DslValidator)->validate($dsl);

        $this->assertSame(["Bước 1 ('get items at boundary limits'), assertion #".($count + 1).': thiếu trường bắt buộc op'], $result->schemaErrors);

        $dsl['steps'][0]['assertions'][$count] = ['type' => 'jsonpath', 'path' => '$.x'];
        $unknown = (new DslValidator)->validate($dsl)->schemaErrors;
        $this->assertCount(1, $unknown);
        $this->assertStringContainsString('loại "jsonpath" không hợp lệ, chỉ chấp nhận: ', $unknown[0]);
        $this->assertStringNotContainsString('The data', implode(' ', $unknown));
    }

    public function test_rule_titles_and_step_labels_are_vietnamese(): void
    {
        $this->assertSame('Gọi GET /items', StepLabel::display('Execute GET /items'));
        $this->assertSame('Bước tự đặt', StepLabel::display('Bước tự đặt'));

        $source = (string) file_get_contents(app_path('Services/Generation/RuleGenerator.php'));
        preg_match_all('/title: "([^"]+)"/', $source, $titles);
        $this->assertNotEmpty($titles[1]);
        foreach ($titles[1] as $title) {
            $this->assertDoesNotMatchRegularExpression('/\b(Happy Path|Missing required|Type mismatch|Boundary|Equivalence|Invalid input|without credentials)\b| in \{\$method\}/', $title);
        }
    }

    // --- M06 / M07 parse errors -----------------------------------------------------------------

    public function test_m06_shows_why_a_spec_could_not_be_parsed(): void
    {
        [$tester, $project] = $this->ownedSuite();
        $specs = "/projects/{$project->id}/specs";

        $html = (string) $this->actingAs($tester)->from($specs)->followingRedirects()->post($specs, [
            'input_method' => 'text',
            'version_label' => 'broken',
            'spec_text' => "openapi: 3.0.3\ninfo: {title: x}\npaths: [1,2",
        ])->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="spec-parse-failure"', $html);
        $this->assertStringContainsString('Lỗi cú pháp YAML ở dòng', $html);
        $this->assertStringNotContainsString('Malformed', $html);

        $failed = ApiSpec::query()->where('version_label', 'broken')->firstOrFail();
        $this->assertSame(ApiSpecStatus::ParseFailed, $failed->status);
        $this->assertSame('3.0.3', $failed->openapi_version);

        $history = (string) $this->actingAs($tester)->get($specs)->assertOk()->getContent();
        $this->assertStringContainsString('Xem lý do', $history);
        $this->assertStringContainsString('data-testid="spec-parse-errors"', $history);

        $this->actingAs($tester)->get("/projects/{$project->id}/specs/{$failed->id}")->assertOk()
            ->assertSee('data-testid="spec-parse-failed"', false)
            ->assertSee('Lỗi cú pháp YAML ở dòng');
    }

    public function test_failed_spec_without_declared_version_shows_a_dash(): void
    {
        [$tester, $project] = $this->ownedSuite();
        $specs = "/projects/{$project->id}/specs";

        $this->actingAs($tester)->from($specs)->post($specs, [
            'input_method' => 'text',
            'version_label' => 'garbage',
            'spec_text' => '{"swagger": "2.0", "info": {',
        ]);

        $failed = ApiSpec::query()->where('version_label', 'garbage')->firstOrFail();
        $this->assertSame(ApiSpec::UNKNOWN_OPENAPI_VERSION, $failed->openapi_version);

        $this->actingAs($tester)->get("/projects/{$project->id}/specs/{$failed->id}")->assertOk()
            ->assertSee('Phiên bản OpenAPI: <span data-testid="spec-openapi-version" class="text-teal-300 font-mono font-semibold">—</span>', false)
            ->assertDontSee('>unknown<', false);
    }

    // --- M10 LLM logs, tabs and JSON parse message ----------------------------------------------

    public function test_m10_lists_this_endpoints_llm_calls_first_and_the_rest_collapsed(): void
    {
        [$tester, $project, , $suite, $endpoint] = $this->ownedSuite();
        $job = GenerationJob::factory()->create([
            'project_id' => $project->id, 'api_spec_id' => $suite->api_spec_id, 'test_suite_id' => $suite->id,
            'strategy' => GenerationStrategy::Llm, 'status' => GenerationJobStatus::Succeeded, 'created_by' => $tester->id,
        ]);
        $case = TestCaseModel::factory()->create([
            'test_suite_id' => $suite->id, 'endpoint_id' => $endpoint->id, 'generation_job_id' => $job->id,
        ]);
        $prompt = fn (string $method, string $path): array => [
            ['role' => 'system', 'content' => 'Generate tests'],
            ['role' => 'user', 'content' => "- Every case uses \"endpoint\": {\"method\": \"{$method}\", \"path\": \"{$path}\"} and its first step calls it."],
        ];
        LlmCallLog::factory()->create(['generation_job_id' => $job->id, 'prompt' => $prompt('POST', '/categories'), 'response_raw' => 'OWN-RESPONSE']);
        LlmCallLog::factory()->create(['generation_job_id' => $job->id, 'prompt' => $prompt('GET', '/categories'), 'response_raw' => 'OTHER-RESPONSE']);

        $html = (string) $this->actingAs($tester)->get("/test-cases/{$case->id}")->assertOk()->getContent();

        $related = strpos($html, 'data-testid="llm-logs-related"');
        $others = strpos($html, 'data-testid="llm-logs-others"');
        $this->assertNotFalse($related);
        $this->assertNotFalse($others);
        $this->assertGreaterThan($related, strpos($html, 'OWN-RESPONSE'));
        $this->assertLessThan($others, strpos($html, 'OWN-RESPONSE'));
        $this->assertGreaterThan($others, strpos($html, 'OTHER-RESPONSE'));
        $this->assertStringContainsString('Các lời gọi khác của tiến trình (1, cho endpoint khác)', $html);

        // Tabs are a complete ARIA tab pattern; the raw JSON.parse text is not shown.
        $this->assertStringContainsString('aria-controls="tabpanel-logs"', $html);
        $this->assertStringContainsString('id="tabpanel-logs" role="tabpanel" aria-labelledby="tab-logs"', $html);
        $this->assertStringNotContainsString('[e.message]', $html);
    }

    // --- M11 progress ----------------------------------------------------------------------------

    public function test_m11_progress_follows_polled_start_time_and_uses_vietnamese_numbers(): void
    {
        [$tester, $project, , $suite, $endpoint] = $this->ownedSuite();
        $job = GenerationJob::factory()->create([
            'project_id' => $project->id, 'api_spec_id' => $suite->api_spec_id, 'test_suite_id' => $suite->id,
            'strategy' => GenerationStrategy::Rule, 'status' => GenerationJobStatus::Succeeded, 'created_by' => $tester->id,
            'started_at' => Carbon::parse('2026-10-13 01:02:03', 'UTC'), 'finished_at' => Carbon::parse('2026-10-13 01:02:09', 'UTC'),
        ]);
        TestCaseModel::factory()->count(3)->create([
            'test_suite_id' => $suite->id, 'endpoint_id' => $endpoint->id, 'generation_job_id' => $job->id,
        ]);

        $html = (string) $this->actingAs($tester)->get("/generation-jobs/{$job->id}")->assertOk()->getContent();

        $this->assertStringContainsString('x-text="formatTime(startedAt) ?? \'Chưa chạy\'"', $html);
        $this->assertStringContainsString('this.startedAt = body.data.started_at ?? this.startedAt;', $html);
        $this->assertMatchesRegularExpression('/startedAt[^,]{0,40}2026-10-13T01:02:03/', $html);
        $this->assertMatchesRegularExpression('/id="job-status"\s+role="status"\s+aria-live="polite"/', $html);
        $this->assertStringContainsString('100,0%', $html);
        $this->assertStringNotContainsString('100.0%', $html);
    }

    // --- M12 cancel of a queued run ----------------------------------------------------------------

    public function test_m12_progress_counts_processed_cases_for_a_run_cancelled_before_start(): void
    {
        [$tester, , , $suite] = $this->ownedSuite();
        $run = TestRun::factory()->create([
            'test_suite_id' => $suite->id, 'triggered_by' => $tester->id, 'status' => TestRunStatus::Cancelled, 'totals' => null,
        ]);

        $html = (string) $this->actingAs($tester)->get("/runs/{$run->id}")->assertOk()->getContent();

        $this->assertStringContainsString('return Math.min(100, Math.round(this.resultsTotal / this.expectedTotal * 100));', $html);
        $this->assertStringContainsString('data-testid="run-cancelled-before-start"', $html);
        $this->assertStringContainsString('Lần chạy chưa bắt đầu nên sẽ không có test case nào được thực thi.', $html);
    }

    // --- Dependencies ------------------------------------------------------------------------------

    public function test_dev_dependency_shell_quote_is_pinned_to_a_fixed_release(): void
    {
        $package = json_decode((string) file_get_contents(base_path('package.json')), true);
        $lock = json_decode((string) file_get_contents(base_path('package-lock.json')), true);

        // GHSA-pqg4-j6r4-53mv: shell-quote 1.8.4–1.10.0 (pulled in by concurrently) is vulnerable.
        $this->assertSame('1.11.0', $package['overrides']['shell-quote'] ?? null);
        $this->assertSame('1.11.0', $lock['packages']['node_modules/shell-quote']['version'] ?? null);
    }
}
