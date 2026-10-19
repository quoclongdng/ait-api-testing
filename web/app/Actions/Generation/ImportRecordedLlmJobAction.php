<?php

namespace App\Actions\Generation;

use App\Data\Ai\AiGeneratedTestCase;
use App\Data\Generation\RecordedLlmJob;
use App\Enums\GenerationJobStatus;
use App\Models\GenerationJob;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Replays a recorded LLM/HYBRID job of the official experiment into a suite (S13-03 demo fallback,
 * docs/demo/kich-ban-demo.md): the same steps as ExecuteAiGenerationAction::executeEndToEnd with the
 * recorded ai-service response instead of a live call. The job walks QUEUED → RUNNING → terminal
 * (§4.1), the LLM calls are stored verbatim (D09) and every case is validated again by the web,
 * deduplicated and persisted (D04).
 */
class ImportRecordedLlmJobAction
{
    public function __construct(
        private readonly TransitionGenerationJobAction $transition,
        private readonly RecordLlmCallsAction $recordLlmCalls,
        private readonly ProcessGeneratedTestCasesAction $processCases,
    ) {}

    public function execute(TestSuite $suite, User $user, RecordedLlmJob $recorded): GenerationJob
    {
        return DB::transaction(function () use ($suite, $user, $recorded): GenerationJob {
            $job = GenerationJob::create([
                'project_id' => $suite->project_id,
                'api_spec_id' => $suite->api_spec_id,
                'test_suite_id' => $suite->id,
                'strategy' => $recorded->strategy,
                'status' => GenerationJobStatus::Queued,
                // Same GenerationParams as an M11 job over every endpoint of the spec.
                'params' => array_filter([
                    'endpoint_ids' => $suite->apiSpec->endpoints()->orderBy('id')->pluck('id')
                        ->map(static fn ($id): int => (int) $id)->all(),
                    'techniques' => [],
                    'model' => $recorded->params['model'],
                    'temperature' => $recorded->params['temperature'],
                    'seed' => $recorded->params['seed'],
                    'max_cases_per_endpoint' => $recorded->params['max_cases_per_endpoint'],
                    // Public credential context of an experiment job (ADR-0006, no token): the web
                    // validation then accepts the `{{auth_token_<role>}}` the recorded cases use.
                    'auth' => $recorded->params['auth'] ?? null,
                    // Marks the replay (QA S14-06): M11 shows it instead of the replay's own duration.
                    GenerationJob::RECORDED_SOURCE_PARAM => $recorded->sourceJobId,
                ], static fn (mixed $value): bool => $value !== null),
                'stats' => null,
                'created_by' => $user->id,
            ]);

            $job = $this->transition->execute($job, GenerationJobStatus::Running, ['started_at' => Carbon::now()]);
            $this->recordLlmCalls->execute($job, $recorded->response->unlinkedLlmCalls());
            $job->update(['stats' => $recorded->response->stats->toArray()]);
            $this->processCases->execute($job, self::withCurrentMessages($recorded->response->testCases), $recorded->response->llmCalls);

            return $job->refresh();
        });
    }

    /**
     * The official run (benchmark-v1.0) recorded the ai-service errors of that time, partly English
     * ("Repair must preserve the original target endpoint", opis "The properties must match schema:
     * steps"). M10/M11 show them to TESTERs (§8), so the recorded cases get today's Vietnamese
     * wording; untranslatable raw schema text is dropped (the web validation reports the problem
     * itself). The DSL, status and repair attempts are unchanged (S14-06).
     *
     * @param  list<AiGeneratedTestCase>  $cases
     * @return list<AiGeneratedTestCase>
     */
    public static function withCurrentMessages(array $cases): array
    {
        return array_map(static fn (AiGeneratedTestCase $case): AiGeneratedTestCase => new AiGeneratedTestCase(
            dsl: $case->dsl,
            status: $case->status,
            validationErrors: self::currentMessages($case->validationErrors),
            repairAttempts: $case->repairAttempts,
            fingerprint: $case->fingerprint,
        ), $cases);
    }

    /**
     * Today's wording of recorded ai-service messages, without untranslatable raw schema text and
     * without repeats (also used to converge cases imported before, ConvergeRecordedLlmJobsAction).
     *
     * @param  array<mixed>  $messages
     * @return list<string>
     */
    public static function currentMessages(array $messages): array
    {
        return array_values(array_unique(array_filter(array_map(
            self::currentMessage(...),
            array_values(array_filter($messages, 'is_string')),
        ), static fn (?string $message): bool => $message !== null)));
    }

    private static function currentMessage(string $message): ?string
    {
        return match (true) {
            str_starts_with($message, 'Repair must preserve the original target endpoint') => 'Bản sửa phải giữ nguyên endpoint đích của test case ban đầu.',
            str_starts_with($message, 'Repair answer unusable') => 'Câu trả lời sửa lỗi của LLM bị cắt do vượt giới hạn token đầu ra: test case quá lớn để sửa tự động.',
            str_starts_with($message, 'Repair response must contain') => 'Câu trả lời sửa lỗi phải chứa một đối tượng Test DSL JSON.',
            str_starts_with($message, 'Failed to parse repair JSON') => 'Không đọc được JSON trong câu trả lời sửa lỗi của LLM.',
            str_starts_with($message, 'Failed to parse') => 'Không đọc được JSON trong câu trả lời của LLM.',
            str_starts_with($message, 'Repair skipped') => 'Bỏ qua sửa lỗi: đã hết thời gian hoặc giới hạn cho phép của lượt sinh test.',
            str_starts_with($message, 'Repair provider error') => 'Lỗi khi gọi LLM để sửa test case.',
            // Raw opis/jsonschema English ("The data must match $ref", "Lỗi schema tại 'steps.0':
            // 'assertions' is a required property"): the web validation states the same problem.
            preg_match('/^(The |All array items )|^Lỗi schema tại /u', $message) === 1 => null,
            default => $message,
        };
    }
}
