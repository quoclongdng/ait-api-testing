<?php

namespace App\Actions\TestCases;

use App\Enums\LlmPurpose;
use App\Models\Endpoint;
use App\Models\GenerationJob;
use App\Models\LlmCallLog;
use App\Models\TestCase;
use App\Support\RecordedRepairLinker;
use Illuminate\Support\Collection;

/**
 * M10 tab "Log gọi LLM" (QA S13-03): the calls that produced this test case first, the other calls
 * of its generation job (other endpoints of the same job) separately.
 *
 * - A call linked to the case (test_case_id) always belongs to it; a call linked to another case is
 *   that case's own REPAIR and is not listed at all.
 * - An unlinked call of the job belongs to the case when its prompt targets the case's endpoint
 *   (ai-service writes `"endpoint": {"method": "<M>", "path": "<p>"}` into every GENERATE/REPAIR
 *   prompt); otherwise it is listed under the job's other calls.
 * - Unlinked REPAIR calls are repairs of dropped cases since the web links each REPAIR call to the
 *   case it worked on (llm_calls[].case_index); they are not listed. Jobs logged before that link
 *   have no linked REPAIR row at all: their unlinked repairs are shown only on cases that went through
 *   repair (repair_attempts > 0), and only the ones whose invalid or repaired DSL carries the case's
 *   title on the case's endpoint (QA S14-06: siblings on one endpoint no longer see each other's
 *   repairs). A repair whose DSL cannot be read keeps the endpoint match.
 */
class ListTestCaseLlmLogsAction
{
    /**
     * @return array{related: Collection<int, LlmCallLog>, others: Collection<int, LlmCallLog>}
     */
    public function execute(TestCase $testCase): array
    {
        $testCase->loadMissing(['endpoint', 'generationJob.llmCallLogs', 'llmCallLogs']);

        /** @var Collection<int, LlmCallLog> $direct */
        $direct = $testCase->llmCallLogs;
        /** @var Collection<int, LlmCallLog> $jobLogs */
        $jobLogs = $testCase->generationJob instanceof GenerationJob
            ? $testCase->generationJob->llmCallLogs
            : collect();

        $legacyJob = ! $jobLogs->contains(
            fn (LlmCallLog $log): bool => $log->purpose === LlmPurpose::Repair && $log->test_case_id !== null,
        );
        $showUnlinkedRepairs = $legacyJob && $testCase->repair_attempts > 0;

        // Unlinked job calls only: linked ones are either in $direct or belong to another case.
        $unlinked = $jobLogs->filter(fn (LlmCallLog $log): bool => $log->test_case_id === null
            && ($log->purpose === LlmPurpose::Generate || $showUnlinkedRepairs));

        $needle = $this->endpointNeedle($testCase->endpoint);
        $belongs = fn (LlmCallLog $log): bool => $needle !== null && $this->promptMentions($log, $needle);

        // Legacy repairs that name another case of the job are that case's own: not listed here.
        $otherCasesRepairs = $showUnlinkedRepairs
            ? $unlinked->filter(fn (LlmCallLog $log): bool => $log->purpose === LlmPurpose::Repair && $this->repairOfAnotherCase($log, $testCase))
            : collect();
        $unlinked = $unlinked->reject(fn (LlmCallLog $log): bool => $otherCasesRepairs->contains('id', $log->id));
        $legacyRepairOfCase = fn (LlmCallLog $log): bool => $log->purpose === LlmPurpose::Repair && $this->repairOfCase($log, $testCase);

        return [
            'related' => $direct->merge($unlinked->filter(fn (LlmCallLog $log): bool => $belongs($log) || $legacyRepairOfCase($log)))
                ->unique('id')->sortBy('id')->values(),
            'others' => $unlinked->reject(fn (LlmCallLog $log): bool => $belongs($log) || $legacyRepairOfCase($log))->sortBy('id')->values(),
        ];
    }

    /**
     * A legacy REPAIR whose DSL titles are known and do not include this case's title, or whose
     * endpoint is known and differs from this case's (RecordedRepairLinker reads both from the call).
     */
    private function repairOfAnotherCase(LlmCallLog $log, TestCase $testCase): bool
    {
        $titles = RecordedRepairLinker::repairTitles($log->prompt, $log->response_raw);
        if ($titles === []) {
            return false; // nothing to tell the case by: keep the endpoint match
        }

        return ! $this->repairOfCase($log, $testCase);
    }

    /**
     * A legacy REPAIR carrying this case's title on this case's endpoint.
     */
    private function repairOfCase(LlmCallLog $log, TestCase $testCase): bool
    {
        $title = $testCase->title;
        if (! in_array($title, RecordedRepairLinker::repairTitles($log->prompt, $log->response_raw), true)) {
            return false;
        }
        $endpoint = $testCase->endpoint->method->value.' '.$testCase->endpoint->path;
        $repairEndpoint = RecordedRepairLinker::repairEndpoint($log->prompt, $log->response_raw);

        return $repairEndpoint === null || $repairEndpoint === $endpoint;
    }

    private function endpointNeedle(?Endpoint $endpoint): ?string
    {
        if (! $endpoint instanceof Endpoint) {
            return null;
        }

        return '"endpoint": {"method": "'.$endpoint->method->value.'", "path": "'.$endpoint->path.'"}';
    }

    private function promptMentions(LlmCallLog $log, string $needle): bool
    {
        $prompt = $log->prompt;

        // ai-service stores a list of chat messages; older rows wrap it in {"messages": [...]}.
        $messages = isset($prompt['messages']) && is_array($prompt['messages']) ? $prompt['messages'] : $prompt;
        foreach ($messages as $message) {
            $content = is_array($message) ? ($message['content'] ?? null) : $message;
            if (is_string($content) && str_contains($content, $needle)) {
                return true;
            }
        }

        return false;
    }
}
