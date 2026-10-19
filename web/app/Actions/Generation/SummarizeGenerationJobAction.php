<?php

namespace App\Actions\Generation;

use App\Data\Generation\GenerationJobSummary;
use App\Enums\ErrorCode;
use App\Enums\GenerationJobStatus;
use App\Enums\LlmPurpose;
use App\Enums\TestCaseStatus;
use App\Models\Endpoint;
use App\Models\GenerationJob;
use App\Models\LlmCallLog;
use App\Models\TestCase;
use App\Services\Support\GenerationJobError;

/**
 * Builds M11 progress and statistics for one generation job: counts per §4.2 status, per-endpoint
 * results and errors, LLM token/latency usage from llm_call_logs and the §4.1 job status.
 */
class SummarizeGenerationJobAction
{
    private const MAX_ERRORS_PER_ENDPOINT = 3;

    public function execute(GenerationJob $job): GenerationJobSummary
    {
        // Polled every 2 s by M11: never hydrate the DSL jsonb or the verbatim prompt/response.
        $job->loadMissing([
            'apiSpec.endpoints',
            'testCases' => fn ($query) => $query->select(['id', 'generation_job_id', 'endpoint_id', 'status', 'repair_attempts', 'validation_errors']),
            'llmCallLogs' => fn ($query) => $query->select(['id', 'generation_job_id', 'purpose', 'model', 'prompt_tokens', 'completion_tokens', 'latency_ms', 'error']),
        ]);

        $cases = $job->testCases;
        $terminal = in_array($job->status, [
            GenerationJobStatus::Succeeded,
            GenerationJobStatus::Partial,
            GenerationJobStatus::Failed,
        ], true);

        [$percent, $label] = match ($job->status) {
            GenerationJobStatus::Queued => [5, 'Đang chờ worker nhận job (QUEUED).'],
            GenerationJobStatus::Running => [50, 'Worker đang sinh, kiểm tra và khử trùng test case (RUNNING).'],
            GenerationJobStatus::Succeeded => [100, 'Hoàn tất: mọi endpoint đều có test case hợp lệ.'],
            GenerationJobStatus::Partial => [100, 'Hoàn tất một phần: có endpoint lỗi nhưng vẫn có test case hợp lệ.'],
            GenerationJobStatus::Failed => [100, 'Thất bại: không có test case hợp lệ nào được tạo.'],
        };

        $stats = is_array($job->stats) ? $job->stats : [];
        $counts = [
            'generated' => $this->statOr($stats, 'generated', $cases->count()),
            'valid' => $this->statOr($stats, 'valid', $cases->where('status', TestCaseStatus::Valid)->count()),
            'invalid' => $this->statOr($stats, 'invalid', $cases->where('status', TestCaseStatus::Invalid)->count()),
            'duplicates' => $this->statOr($stats, 'duplicates', $cases->where('status', TestCaseStatus::Duplicate)->count()),
            'repaired' => $this->statOr($stats, 'repaired', $this->successfulRepairs($cases)),
        ];

        $endpointRows = $this->endpointRows($job, $terminal);

        if ($job->status === GenerationJobStatus::Succeeded && $counts['valid'] === 0 && $counts['duplicates'] > 0) {
            $label = 'Hoàn tất: không có test case mới, mọi test case sinh ra đều trùng với test case đã có trong suite.';
        } elseif ($job->status === GenerationJobStatus::Succeeded) {
            // §4.1 keeps a job SUCCEEDED when an endpoint only produced duplicates: say so instead
            // of claiming that every endpoint has a valid case (QA S14-06 round 3).
            // An endpoint row with duplicates and INVALID cases (possible for RULE, which counts an
            // endpoint by its VALID cases before dedup) is not "every case was a duplicate".
            $withoutNew = array_values(array_filter(
                $endpointRows,
                static fn (array $row): bool => $row['valid'] === 0 && $row['duplicates'] > 0,
            ));
            $duplicateOnly = count(array_filter($withoutNew, static fn (array $row): bool => $row['invalid'] === 0));
            $mixed = count($withoutNew) - $duplicateOnly;
            if ($withoutNew !== []) {
                $label = 'Hoàn tất: có test case hợp lệ mới; '.implode('; ', array_filter([
                    $duplicateOnly > 0 ? "{$duplicateOnly} endpoint không có test case mới vì mọi test case sinh ra đều trùng với test case đã có trong suite" : null,
                    $mixed > 0 ? "{$mixed} endpoint không có test case mới (test case trùng với test case đã có hoặc không hợp lệ)" : null,
                ])).'.';
            }
        }

        $jobErrors = match (true) {
            $job->error === null || $job->error === '' => [],
            // "CODE\nmessage" of a failed LLM/HYBRID job: the message is the single job error (S10-01).
            GenerationJobError::code($job->error) !== null => ($detail = GenerationJobError::detail($job->error)) !== null ? [$detail] : [],
            default => array_values(array_filter(array_map('trim', explode("\n", $job->error)), fn (string $l): bool => $l !== '')),
        };

        return new GenerationJobSummary(
            status: $job->status->value,
            strategy: $job->strategy->value,
            isTerminal: $terminal,
            progressPercent: $percent,
            progressLabel: $label,
            counts: $counts,
            endpoints: $endpointRows,
            llm: $this->llmUsage($job),
            errorMessage: $this->errorMessage($job->error, $this->lastLlmError($job)),
            jobErrors: $jobErrors,
            // A replayed record took no LLM time here: its duration would contradict the recorded
            // latencies of its llm_call_logs (QA S14-06), so it has none.
            durationSeconds: ! $job->isReplayed() && $job->started_at !== null && $job->finished_at !== null
                ? (int) $job->started_at->diffInSeconds($job->finished_at, true)
                : null,
            replayed: $job->isReplayed(),
        );
    }

    /**
     * @return list<array{endpoint_id: int, method: string, path: string, generated: int, valid: int, invalid: int, duplicates: int, repaired: int, has_error: bool, errors: list<string>, note: string|null}>
     */
    private function endpointRows(GenerationJob $job, bool $terminal): array
    {
        $targetIds = $job->getTargetEndpointIds();
        $casesByEndpoint = $job->testCases->groupBy('endpoint_id');
        $endpointIds = array_values(array_unique(array_merge(
            $targetIds,
            $casesByEndpoint->keys()->map(static fn ($id): int => (int) $id)->all(),
        )));

        $rows = [];
        foreach ($endpointIds as $endpointId) {
            /** @var Endpoint|null $endpoint */
            $endpoint = $job->apiSpec->endpoints->firstWhere('id', $endpointId);
            $cases = $casesByEndpoint->get($endpointId) ?? collect();

            $valid = $cases->where('status', TestCaseStatus::Valid)->count()
                + $cases->where('status', TestCaseStatus::Approved)->count();
            $invalidCases = $cases->where('status', TestCaseStatus::Invalid);

            $errors = [];
            foreach ($invalidCases as $case) {
                foreach ($case->validation_errors ?? [] as $message) {
                    if (is_string($message) && ! in_array($message, $errors, true)) {
                        $errors[] = $message;
                    }
                }
            }
            $errors = array_slice($errors, 0, self::MAX_ERRORS_PER_ENDPOINT);
            if ($terminal && $cases->isEmpty()) {
                $errors[] = 'Không sinh được test case nào cho endpoint này.';
            }

            $duplicates = $cases->where('status', TestCaseStatus::Duplicate)->count();
            // An endpoint without a new valid case whose cases duplicate existing ones: not an
            // error, but the row must not look as if it produced something new.
            $note = null;
            if ($valid === 0 && $duplicates > 0) {
                $note = $invalidCases->isEmpty()
                    ? 'Mọi case đều trùng với case đã có trong suite: không có case mới.'
                    : "{$duplicates} case trùng với case đã có trong suite, các case còn lại không hợp lệ.";
            }

            $rows[] = [
                'endpoint_id' => $endpointId,
                'method' => $endpoint !== null ? $endpoint->method->value : '?',
                'path' => $endpoint !== null ? $endpoint->path : '(endpoint #'.$endpointId.')',
                'generated' => $cases->count(),
                'valid' => $valid,
                'invalid' => $invalidCases->count(),
                'duplicates' => $duplicates,
                'repaired' => $this->successfulRepairs($cases),
                // An endpoint whose cases are all DUPLICATE found nothing new but did not fail.
                'has_error' => $terminal && $valid === 0 && ($cases->isEmpty() || $invalidCases->isNotEmpty()),
                'errors' => $errors,
                'note' => $note,
            ];
        }

        return $rows;
    }

    /**
     * @return array{calls: int, generate_calls: int, repair_calls: int, failed_calls: int, prompt_tokens: int, completion_tokens: int, total_tokens: int, total_latency_ms: int, avg_latency_ms: int, models: list<string>}
     */
    private function llmUsage(GenerationJob $job): array
    {
        $logs = $job->llmCallLogs;
        $calls = $logs->count();
        $promptTokens = (int) $logs->sum(fn (LlmCallLog $l): int => $l->prompt_tokens ?? 0);
        $completionTokens = (int) $logs->sum(fn (LlmCallLog $l): int => $l->completion_tokens ?? 0);
        $latency = (int) $logs->sum(fn (LlmCallLog $l): int => $l->latency_ms);

        /** @var list<string> $models */
        $models = $logs->pluck('model')->filter(fn ($m): bool => is_string($m) && $m !== '')->unique()->values()->all();

        return [
            'calls' => $calls,
            'generate_calls' => $logs->where('purpose', LlmPurpose::Generate)->count(),
            'repair_calls' => $logs->where('purpose', LlmPurpose::Repair)->count(),
            'failed_calls' => $logs->filter(fn (LlmCallLog $l): bool => $l->error !== null && $l->error !== '')->count(),
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $promptTokens + $completionTokens,
            'total_latency_ms' => $latency,
            'avg_latency_ms' => $calls > 0 ? intdiv($latency, $calls) : 0,
            'models' => $models,
        ];
    }

    /**
     * Successful repairs only (same rule as ai-service stats.repaired): a case that went through
     * repair but still ended INVALID was not "repaired".
     *
     * @param  iterable<TestCase>  $cases
     */
    private function successfulRepairs(iterable $cases): int
    {
        $count = 0;
        foreach ($cases as $case) {
            if ($case->repair_attempts > 0 && $case->status !== TestCaseStatus::Invalid) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function statOr(array $stats, string $key, int $fallback): int
    {
        return isset($stats[$key]) && is_numeric($stats[$key]) ? (int) $stats[$key] : $fallback;
    }

    /**
     * Error of the last failed LLM call (e.g. "timed out"), logged from a 502 response (D09).
     */
    private function lastLlmError(GenerationJob $job): ?string
    {
        $failed = $job->llmCallLogs
            ->filter(fn (LlmCallLog $l): bool => $l->error !== null && $l->error !== '')
            ->sortByDesc('id')
            ->first();

        return $failed instanceof LlmCallLog ? mb_substr((string) $failed->error, 0, 500) : null;
    }

    private function errorMessage(?string $error, ?string $llmError = null): ?string
    {
        if ($error === null || $error === '') {
            return null;
        }

        $code = GenerationJobError::code($error);
        $detail = GenerationJobError::detail($error);
        // Provider errors are shown to every TESTER: no provider URL (only the reason), on one line.
        $llmError = $llmError !== null ? self::withoutUrls($llmError) : null;
        if ($code !== null && $detail !== null) {
            $detail = self::withoutUrls($detail);

            // The stored cause names what to fix (e.g. AI_SERVICE_TIMEOUT, AI_MAX_PROMPT_CHARS): show it
            // rather than the generic text below, which may point at the wrong cause (S10-01).
            // A 502 from ai-service ("Lỗi từ LLM provider: ...") already states the provider's reason
            // in Vietnamese; the logged call error is the raw provider text (D09, shown on M10), so
            // repeating it here would show the same failure twice, partly in English (S14-06).
            $statesProviderReason = str_starts_with($detail, GenerationJobError::PROVIDER_ERROR_PREFIX);

            return "{$detail} ({$code->value})"
                .($code === ErrorCode::LlmProviderError && ! $statesProviderReason
                    && $llmError !== null && $llmError !== '' && ! str_contains($detail, $llmError)
                    ? " Chi tiết từ LLM provider: {$llmError}"
                    : '');
        }

        return match ($code) {
            ErrorCode::AiServiceUnavailable => 'Không gọi được ai-service (AI_SERVICE_UNAVAILABLE).',
            ErrorCode::LlmProviderError => 'LLM lỗi hoặc trả dữ liệu không dùng được (LLM_PROVIDER_ERROR).'
                .($llmError !== null && $llmError !== '' ? " Chi tiết từ LLM provider: {$llmError}" : ''),
            ErrorCode::DslInvalid => 'Không có test case nào qua được kiểm tra DSL (DSL_INVALID).',
            ErrorCode::ValidationFailed => 'Dữ liệu đầu vào không hợp lệ, ví dụ không có endpoint hoặc HYBRID thiếu case RULE làm hạt giống (VALIDATION_FAILED).',
            ErrorCode::InternalError => 'Lỗi hệ thống khi xử lý job (INTERNAL_ERROR).',
            default => $error,
        };
    }

    /**
     * One line, without http(s) URLs: a raw provider error ("Client error '404 Not Found' for url
     * 'https://...' For more information check: https://...") must not show the provider host to a
     * TESTER, and the same text logged with a newline must still be recognised as the same message.
     */
    private static function withoutUrls(string $text): string
    {
        $text = (string) preg_replace("~\\s*(?:for url\\s+)?['\"]?https?://[^\\s'\"]+['\"]?~iu", '', $text);
        $text = (string) preg_replace('~\\s*For more information check:?\\s*~iu', ' ', $text);

        return trim((string) preg_replace('/\\s+/u', ' ', $text));
    }
}
