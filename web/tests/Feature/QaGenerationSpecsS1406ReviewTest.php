<?php

namespace Tests\Feature;

use App\Actions\Generation\SummarizeGenerationJobAction;
use App\Enums\ApiSpecStatus;
use App\Enums\ErrorCode;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\HttpMethod;
use App\Enums\LlmPurpose;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Models\GenerationJob;
use App\Models\LlmCallLog;
use App\Models\Project;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\Support\GenerationJobError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Review of S14-06: paths the first fix did not cover.
 *
 * - A browser submits the M06 <textarea> with CRLF line breaks, so pasting the text of an LF file
 *   still gave another checksum and the duplicate was not detected.
 * - With the S14-06 ai-service, a 502 carries a Vietnamese reason while the logged call keeps the
 *   raw provider text (D09): M11 appended that English text as "Chi tiết từ LLM provider".
 */
class QaGenerationSpecsS1406ReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $tester;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tester = User::factory()->tester()->create();
        $this->project = Project::factory()->for($this->tester, 'owner')->create();
    }

    public function test_web_pasted_spec_with_browser_crlf_line_breaks_is_a_duplicate_of_the_lf_file(): void
    {
        $content = "openapi: 3.0.3\ninfo:\n  title: Crlf\n  version: '1'\npaths:\n  /ping:\n    get:\n      responses:\n        '200':\n          description: ok\n";

        $this->actingAs($this->tester)->post("/projects/{$this->project->id}/specs", [
            'version_label' => 'file',
            'input_method' => 'file',
            'spec_file' => UploadedFile::fake()->createWithContent('crlf.yaml', $content),
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertSame(1, ApiSpec::query()->where('project_id', $this->project->id)->count());

        $this->actingAs($this->tester)->post("/projects/{$this->project->id}/specs", [
            'version_label' => 'pasted',
            'input_method' => 'text',
            'spec_text' => str_replace("\n", "\r\n", $content),
        ])->assertSessionHas('error');

        $this->assertSame(1, ApiSpec::query()->where('project_id', $this->project->id)->count());
    }

    public function test_web_pasted_spec_is_stored_with_lf_line_breaks_and_untrimmed(): void
    {
        $content = "\nopenapi: 3.0.3\ninfo:\n  title: Lf\n  version: '1'\npaths:\n  /ping:\n    get:\n      responses:\n        '200':\n          description: ok\n\n";

        $this->actingAs($this->tester)->post("/projects/{$this->project->id}/specs", [
            'version_label' => 'pasted',
            'input_method' => 'text',
            'spec_text' => str_replace("\n", "\r\n", $content),
        ])->assertSessionHasNoErrors();

        $spec = ApiSpec::query()->where('project_id', $this->project->id)->firstOrFail();
        $this->assertSame($content, $spec->raw_content);
        $this->assertSame(hash('sha256', $content), $spec->checksum);
    }

    private function failedLlmJob(string $error): GenerationJob
    {
        $spec = ApiSpec::factory()->for($this->project)->create(['status' => ApiSpecStatus::Parsed]);
        $suite = TestSuite::factory()->create([
            'project_id' => $this->project->id,
            'api_spec_id' => $spec->id,
            'created_by' => $this->tester->id,
        ]);
        $endpoint = Endpoint::factory()->create([
            'api_spec_id' => $spec->id,
            'method' => HttpMethod::Get,
            'path' => '/orders',
        ]);

        return GenerationJob::factory()->create([
            'project_id' => $this->project->id,
            'api_spec_id' => $spec->id,
            'test_suite_id' => $suite->id,
            'strategy' => GenerationStrategy::Llm,
            'status' => GenerationJobStatus::Failed,
            'params' => ['endpoint_ids' => [$endpoint->id]],
            'created_by' => $this->tester->id,
            'error' => $error,
        ]);
    }

    public function test_vietnamese_provider_reason_is_not_followed_by_the_raw_provider_log(): void
    {
        // What ai-service (S14-06) returns for a 404 and what it logs for the same call (D09).
        $reason = "Nhà cung cấp LLM trả lỗi HTTP 404 cho model 'qwen2.5-coder:7b': model không tồn tại trên nhà cung cấp.";
        $raw = "Client error '404 Not Found' for url 'https://api.groq.com/openai/v1/chat/completions'\nFor more information check: https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/404";
        $job = $this->failedLlmJob(GenerationJobError::compose(
            ErrorCode::LlmProviderError->value,
            GenerationJobError::PROVIDER_ERROR_PREFIX.$reason,
        ));
        LlmCallLog::factory()->create(['generation_job_id' => $job->id, 'purpose' => LlmPurpose::Generate, 'error' => $raw]);

        $message = (string) app(SummarizeGenerationJobAction::class)->execute($job->refresh())->errorMessage;

        $this->assertSame(GenerationJobError::PROVIDER_ERROR_PREFIX.$reason.' (LLM_PROVIDER_ERROR)', $message);
        $this->assertStringNotContainsString('Client error', $message);
        $this->assertStringNotContainsString('https://', $message);
    }

    public function test_provider_error_without_the_ai_service_reason_still_shows_the_logged_cause(): void
    {
        $job = $this->failedLlmJob(GenerationJobError::compose(ErrorCode::LlmProviderError->value, 'Mọi lời gọi LLM đều lỗi.'));
        LlmCallLog::factory()->create(['generation_job_id' => $job->id, 'purpose' => LlmPurpose::Generate, 'error' => 'timed out']);

        $message = (string) app(SummarizeGenerationJobAction::class)->execute($job->refresh())->errorMessage;

        $this->assertSame('Mọi lời gọi LLM đều lỗi. (LLM_PROVIDER_ERROR) Chi tiết từ LLM provider: timed out', $message);
    }
}
