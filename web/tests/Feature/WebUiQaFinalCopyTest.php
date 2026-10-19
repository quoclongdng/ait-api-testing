<?php

namespace Tests\Feature;

use App\Enums\ApiSpecStatus;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\HttpMethod;
use App\Enums\TestRunStatus;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\TestCase as TestCaseModel;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\Dsl\DslValidator;
use App\Services\Generation\RuleGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression tests for the leftovers of the S13-03 QA final pass (web-ui): no screen codes (Mxx) or
 * service names in TESTER copy, Vietnamese RULE descriptions, translated step names in DSL semantic
 * errors and one primary-button style for the "create" actions.
 */
class WebUiQaFinalCopyTest extends TestCase
{
    use RefreshDatabase;

    private const SCREEN_CODE = '/\bM(0[1-9]|1[0-5])\b/';

    /** Text a user can read, the <title> included: no <script>/<style> blocks, comments or tags. */
    private function visibleText(string $html): string
    {
        $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#si', ' ', $html);
        $html = (string) preg_replace('/<!--.*?-->/s', ' ', $html);
        // placeholder/title attributes are shown to the user as well.
        preg_match_all('/\s(?:placeholder|title|aria-label)="([^"]*)"/', $html, $attributes);

        return html_entity_decode(strip_tags($html).' '.implode(' ', $attributes[1]));
    }

    public function test_tester_pages_show_no_screen_codes_or_service_names(): void
    {
        $tester = User::factory()->tester()->create();
        $project = Project::factory()->for($tester, 'owner')->create();
        $spec = ApiSpec::factory()->for($project)->create(['status' => ApiSpecStatus::Parsed]);
        $suite = TestSuite::factory()->create(['project_id' => $project->id, 'api_spec_id' => $spec->id, 'created_by' => $tester->id]);
        $endpoint = Endpoint::factory()->create([
            'api_spec_id' => $spec->id, 'method' => HttpMethod::Post, 'path' => '/categories',
            'responses' => ['201' => ['description' => 'Created']],
        ]);
        $job = GenerationJob::factory()->create([
            'project_id' => $project->id, 'api_spec_id' => $spec->id, 'test_suite_id' => $suite->id,
            'strategy' => GenerationStrategy::Llm, 'status' => GenerationJobStatus::Succeeded, 'created_by' => $tester->id,
        ]);
        $case = TestCaseModel::factory()->create(['test_suite_id' => $suite->id, 'endpoint_id' => $endpoint->id, 'generation_job_id' => $job->id]);
        $run = TestRun::factory()->create(['test_suite_id' => $suite->id, 'triggered_by' => $tester->id, 'status' => TestRunStatus::Completed]);

        $pages = [
            '/dashboard', '/projects', "/projects/{$project->id}/business-rules", "/projects/{$project->id}/specs",
            "/projects/{$project->id}/specs/{$spec->id}", "/projects/{$project->id}/suites", "/suites/{$suite->id}",
            "/suites/{$suite->id}/generation-jobs/create", "/generation-jobs/{$job->id}", "/test-cases/{$case->id}",
            "/suites/{$suite->id}/runs", "/runs/{$run->id}", "/runs/{$run->id}/report", '/experiments',
        ];
        foreach ($pages as $page) {
            $text = $this->visibleText((string) $this->actingAs($tester)->get($page)->assertOk()->getContent());
            $this->assertDoesNotMatchRegularExpression(self::SCREEN_CODE, $text, "{$page} shows a screen code");
            $this->assertStringNotContainsString('ai-service', $text, "{$page} names the internal ai-service");
        }
    }

    public function test_rule_descriptions_are_vietnamese(): void
    {
        $source = (string) file_get_contents(app_path('Services/Generation/RuleGenerator.php'));
        preg_match_all('/description: "([^"]+)"/', $source, $descriptions);

        $this->assertGreaterThan(30, count($descriptions[1]));
        foreach ($descriptions[1] as $description) {
            $this->assertDoesNotMatchRegularExpression(
                '/^(Verify|Send|Valid|Representative|Numeric|Use a|The operation)\b|\b(returns a 4xx error|to verify|must be rejected)\b/',
                $description,
            );
        }
    }

    public function test_generated_rule_case_carries_a_vietnamese_description(): void
    {
        $cases = (new RuleGenerator)->generateHappyPath([
            'method' => 'GET', 'path' => '/items', 'responses' => ['200' => ['description' => 'OK']],
        ]);

        $happy = $cases[0] ?? null;
        $this->assertIsArray($happy);
        $this->assertSame('Yêu cầu chuẩn hợp lệ với tham số và body đúng đặc tả cho GET /items.', $happy['description']);
    }

    public function test_dsl_semantic_errors_quote_step_names_as_written_in_the_dsl(): void
    {
        $spec = ['paths' => ['/items' => ['post' => ['responses' => ['201' => ['description' => 'Created']]]]]];
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'PUT ngoài đặc tả',
            'endpoint' => ['method' => 'POST', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'MANUAL',
            'steps' => [[
                'name' => 'Execute PUT /products/{id}',
                'request' => ['method' => 'PUT', 'path' => '/products/1'],
                'assertions' => [['type' => 'status', 'equals' => 200]],
            ]],
        ];

        $errors = (new DslValidator)->validate($dsl, $spec)->semanticErrors;

        $this->assertNotEmpty($errors);
        // S14-06 live QA r7: the name the user can find in the DSL JSON, not the StepLabel display form.
        $this->assertStringStartsWith("Bước 1 ('Execute PUT /products/{id}'): ", $errors[0]);
        $this->assertStringNotContainsString('Gọi PUT', implode(' ', $errors));
    }

    public function test_create_buttons_share_one_primary_style(): void
    {
        // S14-04 review: one page-header CTA everywhere (no wrap, not stretched to full width at 375px).
        $primary = 'inline-flex shrink-0 items-center justify-center gap-2 self-start whitespace-nowrap rounded-xl bg-indigo-600 px-4 py-2.5 text-xs font-semibold text-white shadow-lg shadow-indigo-600/25 transition hover:bg-indigo-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-900 sm:self-auto';
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->for($admin, 'owner')->create();

        $pages = [
            '/admin/users' => 'btn-create-user',
            '/admin/allowed-targets' => 'btn-create-allowed-target',
            '/admin/benchmarks' => 'btn-create-benchmark',
            '/experiments' => 'btn-create-experiment',
            "/projects/{$project->id}/business-rules" => 'btn-create-rule',
            "/projects/{$project->id}/suites" => 'btn-create-suite',
            '/projects' => 'btn-create-project',
        ];
        foreach ($pages as $page => $id) {
            $html = (string) $this->actingAs($admin)->get($page)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/id="'.$id.'"[^>]*class="'.preg_quote($primary, '/').'"/s', $html, $page);
            $this->assertStringNotContainsString('bg-gradient-to-r from-indigo-600', $html, $page);
        }
    }
}
