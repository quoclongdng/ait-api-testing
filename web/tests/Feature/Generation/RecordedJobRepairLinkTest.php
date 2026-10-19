<?php

namespace Tests\Feature\Generation;

use App\Actions\Generation\ImportRecordedLlmJobAction;
use App\Actions\TestCases\ListTestCaseLlmLogsAction;
use App\Data\Generation\RecordedLlmJob;
use App\Enums\GenerationJobStatus;
use App\Enums\GenerationStrategy;
use App\Enums\HttpMethod;
use App\Enums\LlmPurpose;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Models\GenerationJob;
use App\Models\LlmCallLog;
use App\Models\TestCase as TestCaseModel;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\Demo\DemoShowcaseFixture;
use App\Support\RecordedRepairLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * QA S14-06: the recorded LLM/HYBRID jobs of the demo predate llm_calls[].case_index, so every REPAIR call
 * was stored unlinked and M10 listed the repairs of sibling cases on the same endpoint as "related".
 * RecordedRepairLinker links them at import time; jobs imported before keep a title-based split in M10.
 */
class RecordedJobRepairLinkTest extends TestCase
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

    public function test_import_links_each_recorded_repair_to_its_own_case(): void
    {
        $job = app(ImportRecordedLlmJobAction::class)->execute($this->suite, $this->owner, $this->recordedJob());

        $this->assertSame(GenerationJobStatus::Succeeded, $job->status);
        [$a, $b] = $this->cases($job)->all();

        $this->assertSame(['repair A1', 'repair A2'], $this->linkedRepairs($a));
        $this->assertSame(['repair B1'], $this->linkedRepairs($b));
        $this->assertSame(1, LlmCallLog::query()->where('purpose', LlmPurpose::Repair)->whereNull('test_case_id')->count(), 'the dropped case\'s repair stays unlinked');

        $logs = app(ListTestCaseLlmLogsAction::class)->execute($a);
        $this->assertSame(['generate items', 'repair A1', 'repair A2'], $this->responses($logs['related']));
        $this->assertSame([], $this->responses($logs['others']));

        $logs = app(ListTestCaseLlmLogsAction::class)->execute($b);
        $this->assertSame(['generate items', 'repair B1'], $this->responses($logs['related']));
    }

    public function test_m10_of_a_job_imported_before_the_link_separates_repairs_by_case_title(): void
    {
        $job = GenerationJob::factory()->create([
            'project_id' => $this->suite->project_id,
            'api_spec_id' => $this->suite->api_spec_id,
            'test_suite_id' => $this->suite->id,
            'strategy' => GenerationStrategy::Llm,
            'status' => GenerationJobStatus::Succeeded,
        ]);
        $endpoint = Endpoint::query()->where('path', '/items')->firstOrFail();
        $case = fn (string $label, int $repairs): TestCaseModel => TestCaseModel::factory()->create([
            'test_suite_id' => $this->suite->id,
            'generation_job_id' => $job->id,
            'endpoint_id' => $endpoint->id,
            'title' => "Case {$label}",
            'repair_attempts' => $repairs,
        ]);
        $a = $case('A', 2);
        $b = $case('B', 1);
        // As stored by the first showcase: every recorded call unlinked.
        foreach ($this->calls() as $call) {
            LlmCallLog::factory()->create([
                'generation_job_id' => $job->id,
                'test_case_id' => null,
                'purpose' => $call['purpose'],
                'prompt' => $call['prompt'],
                'response_raw' => $call['response_raw'],
            ]);
        }
        // A legacy repair whose DSL cannot be read keeps the endpoint match.
        LlmCallLog::factory()->create([
            'generation_job_id' => $job->id, 'test_case_id' => null, 'purpose' => LlmPurpose::Repair,
            'prompt' => [['role' => 'user', 'content' => 'Repair "endpoint": {"method": "GET", "path": "/items"} please.']],
            'response_raw' => 'not json',
        ]);

        $logs = app(ListTestCaseLlmLogsAction::class)->execute($a);
        $this->assertSame(['generate items', 'repair A1', 'repair A2', 'not json'], $this->responses($logs['related']));
        $this->assertSame([], $this->responses($logs['others']));

        $logs = app(ListTestCaseLlmLogsAction::class)->execute($b);
        $this->assertSame(['generate items', 'repair B1', 'not json'], $this->responses($logs['related']));
    }

    public function test_linker_keeps_responses_that_are_already_linked_or_unreadable(): void
    {
        $linked = (string) json_encode(['test_cases' => [], 'llm_calls' => [['purpose' => 'REPAIR', 'case_index' => 0]], 'stats' => new \stdClass]);
        $this->assertSame($linked, RecordedRepairLinker::link($linked));
        $this->assertSame('not json', RecordedRepairLinker::link('not json'));

        // Objects stay objects and numbers keep their form.
        $json = RecordedRepairLinker::link($this->responseJson());
        $this->assertStringContainsString('"query":{}', $json);
        $this->assertStringContainsString('"temperature":0.2', $json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame([null, 0, 0, null, 1], array_map(static fn (array $c): ?int => $c['case_index'] ?? null, $decoded['llm_calls']));
    }

    public function test_every_repair_of_the_official_recorded_jobs_is_linked(): void
    {
        $fixture = app(DemoShowcaseFixture::class);
        foreach ($fixture->slugs() as $slug) {
            foreach ($fixture->recordedJobs($slug) as $strategy => $job) {
                $linked = [];
                foreach ($job->response->llmCalls as $call) {
                    if ($call->caseIndex !== null) {
                        $linked[$call->caseIndex] = ($linked[$call->caseIndex] ?? 0) + 1;
                    }
                }
                foreach ($job->response->testCases as $index => $case) {
                    $this->assertSame($case->repairAttempts, $linked[$index] ?? 0, "{$slug} {$strategy} case {$index}");
                }
            }
        }
    }

    private function recordedJob(): RecordedLlmJob
    {
        return RecordedLlmJob::fromRecord(
            7,
            GenerationStrategy::Llm,
            ['model' => 'qwen2.5-coder:7b', 'temperature' => 0.2, 'seed' => 42, 'max_cases_per_endpoint' => 10],
            $this->responseJson(),
        );
    }

    private function responseJson(): string
    {
        return (string) json_encode([
            'test_cases' => [
                ['dsl' => $this->dsl('A'), 'status' => 'VALID', 'validation_errors' => [], 'repair_attempts' => 2, 'fingerprint' => null],
                ['dsl' => $this->dsl('B'), 'status' => 'VALID', 'validation_errors' => [], 'repair_attempts' => 1, 'fingerprint' => null],
            ],
            'llm_calls' => array_map(static fn (array $call): array => $call + [
                'provider' => 'openai_compatible', 'model' => 'qwen2.5-coder:7b', 'temperature' => 0.2, 'seed' => 42,
                'prompt_tokens' => 10, 'completion_tokens' => 5, 'latency_ms' => 1000, 'error' => null,
            ], array_map(static fn (array $call): array => ['purpose' => $call['purpose']->value] + $call, $this->calls())),
            'stats' => ['generated' => 2, 'valid' => 2, 'invalid' => 0, 'repaired' => 2, 'duplicates' => 0],
        ], JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * GENERATE, two repairs of A, the repair of a case dropped afterwards, then the repair of B: the
     * order ai-service writes them in.
     *
     * @return list<array{purpose: LlmPurpose, prompt: list<array{role: string, content: string}>, response_raw: string}>
     */
    private function calls(): array
    {
        $repair = fn (string $label, string $raw): array => [
            'purpose' => LlmPurpose::Repair,
            'prompt' => [
                ['role' => 'system', 'content' => 'Repair the case.'],
                // Python json.dumps separators, as ai-service writes the prompt: the M10 endpoint needle matches.
                ['role' => 'user', 'content' => "### Invalid Test Case DSL:\n<<<DATA:invalid_test_case>>>\n"
                    .str_replace(['":', ',"'], ['": ', ', "'], (string) json_encode($this->dsl($label)))."\n<<<END DATA:invalid_test_case>>>\n"],
            ],
            'response_raw' => $raw,
        ];

        return [
            ['purpose' => LlmPurpose::Generate, 'prompt' => [['role' => 'user', 'content' => 'Generate for "endpoint": {"method": "GET", "path": "/items"}']], 'response_raw' => 'generate items'],
            $repair('A', 'repair A1'),
            $repair('A', 'repair A2'),
            $repair('Dropped', 'repair dropped'),
            $repair('B', 'repair B1'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dsl(string $label): array
    {
        return [
            'dsl_version' => '1.0',
            'title' => "Case {$label}",
            'endpoint' => ['method' => 'GET', 'path' => '/items'],
            'technique' => 'HAPPY_PATH',
            'source' => 'LLM',
            'steps' => [[
                'name' => "Call {$label}",
                'request' => ['method' => 'GET', 'path' => '/items', 'query' => $label === 'A' ? new \stdClass : ['case' => $label]],
                'assertions' => [['type' => 'status', 'equals' => 200]],
            ]],
        ];
    }

    /**
     * @return Collection<int, TestCaseModel>
     */
    private function cases(GenerationJob $job): Collection
    {
        return TestCaseModel::query()->where('generation_job_id', $job->id)->orderBy('id')->get();
    }

    /**
     * @return list<string>
     */
    private function linkedRepairs(TestCaseModel $case): array
    {
        /** @var list<string> $raw */
        $raw = LlmCallLog::query()->where('test_case_id', $case->id)->where('purpose', LlmPurpose::Repair)->orderBy('id')->pluck('response_raw')->all();

        return $raw;
    }

    /**
     * @param  Collection<int, LlmCallLog>  $logs
     * @return list<string>
     */
    private function responses(Collection $logs): array
    {
        /** @var list<string> $raw */
        $raw = $logs->pluck('response_raw')->values()->all();

        return $raw;
    }
}
