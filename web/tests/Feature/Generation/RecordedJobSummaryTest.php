<?php

namespace Tests\Feature\Generation;

use App\Actions\Generation\ImportRecordedLlmJobAction;
use App\Actions\Generation\SummarizeGenerationJobAction;
use App\Data\Generation\RecordedLlmJob;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\HttpMethod;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Models\GenerationJob;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * QA S14-06: a recorded LLM/HYBRID job replayed into the demo said "Hoàn thành trong 0 giây" next to
 * hundreds of seconds of recorded LLM latency. A replay is marked and M11 says what it is instead.
 */
class RecordedJobSummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private TestSuite $suite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->tester()->create();
        $spec = ApiSpec::factory()->create();
        $spec->project->update(['owner_id' => $this->owner->id]);
        Endpoint::factory()->create([
            'api_spec_id' => $spec->id,
            'method' => HttpMethod::Get,
            'path' => '/items',
            'responses' => ['200' => ['description' => 'OK']],
        ]);
        $this->suite = TestSuite::factory()->create(['project_id' => $spec->project_id, 'api_spec_id' => $spec->id]);
    }

    public function test_a_replayed_job_has_no_duration_and_says_it_is_a_replay(): void
    {
        $job = app(ImportRecordedLlmJobAction::class)->execute($this->suite, $this->owner, $this->recordedJob());

        $this->assertTrue($job->isReplayed());
        $this->assertSame(11, $job->params[GenerationJob::RECORDED_SOURCE_PARAM]);
        $summary = app(SummarizeGenerationJobAction::class)->execute($job);
        $this->assertTrue($summary->replayed);
        $this->assertNull($summary->durationSeconds);
        $this->assertSame(530000, $summary->llm['total_latency_ms'], 'the recorded latency is kept');

        $this->actingAs($this->owner)->get("/generation-jobs/{$job->id}")
            ->assertOk()
            ->assertSee('data-testid="job-replayed"', escape: false)
            ->assertSeeText('Nạp lại từ bản ghi thực nghiệm')
            ->assertDontSeeText('Hoàn thành trong 0 giây');

        $this->actingAs($this->owner)->getJson("/api/v1/generation-jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.summary.replayed', true)
            ->assertJsonPath('data.summary.duration_seconds', null)
            ->assertJsonMissingPath('data.params.'.GenerationJob::RECORDED_SOURCE_PARAM);
    }

    public function test_a_live_job_keeps_its_duration(): void
    {
        $job = GenerationJob::factory()->create([
            'project_id' => $this->suite->project_id,
            'api_spec_id' => $this->suite->api_spec_id,
            'test_suite_id' => $this->suite->id,
            'strategy' => GenerationStrategy::Llm,
            'status' => GenerationJobStatus::Succeeded,
            'started_at' => Carbon::parse('2026-10-14 08:00:00'),
            'finished_at' => Carbon::parse('2026-10-14 08:04:14'),
        ]);

        $summary = app(SummarizeGenerationJobAction::class)->execute($job);

        $this->assertFalse($job->isReplayed());
        $this->assertFalse($summary->replayed);
        $this->assertSame(254, $summary->durationSeconds);
        $this->actingAs($this->owner)->get("/generation-jobs/{$job->id}")
            ->assertOk()
            ->assertDontSee('data-testid="job-replayed"', escape: false);
    }

    private function recordedJob(): RecordedLlmJob
    {
        $dsl = [
            'dsl_version' => '1.0',
            'title' => 'List items',
            'endpoint' => ['method' => 'GET', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'LLM',
            'steps' => [[
                'name' => 'list',
                'request' => ['method' => 'GET', 'path' => '/items'],
                'assertions' => [['type' => 'status', 'equals' => 200]],
            ]],
        ];

        return RecordedLlmJob::fromRecord(11, GenerationStrategy::Llm, ['model' => 'qwen2.5-coder:7b', 'temperature' => 0.2, 'seed' => 42, 'max_cases_per_endpoint' => 10], (string) json_encode([
            'test_cases' => [['dsl' => $dsl, 'status' => 'VALID', 'validation_errors' => [], 'repair_attempts' => 0, 'fingerprint' => null]],
            'llm_calls' => [[
                'purpose' => 'GENERATE', 'provider' => 'openai_compatible', 'model' => 'qwen2.5-coder:7b',
                'prompt' => [['role' => 'user', 'content' => 'generate']], 'response_raw' => '{"test_cases": []}',
                'temperature' => 0.2, 'seed' => 42, 'prompt_tokens' => 100, 'completion_tokens' => 50,
                'latency_ms' => 530000, 'error' => null,
            ]],
            'stats' => ['generated' => 1, 'valid' => 1, 'invalid' => 0, 'repaired' => 0, 'duplicates' => 0],
        ], JSON_PRESERVE_ZERO_FRACTION));
    }
}
