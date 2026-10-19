<?php

namespace App\Actions\Runs;

use App\Enums\ErrorCode;
use App\Enums\QueueName;
use App\Enums\TestCaseStatus;
use App\Enums\TestRunStatus;
use App\Exceptions\ApiException;
use App\Jobs\ExecuteTestRunJob;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\Target\TargetAllowlistService;

/**
 * Creates a QUEUED TestRun for a suite and dispatches the worker job (docs/DESIGN.md §4.3, D10).
 */
class CreateTestRunAction
{
    public function __construct(
        private TargetAllowlistService $allowlistService,
    ) {}

    /**
     * @param  array{target_base_url: string, benchmark_variant?: string|null, test_case_ids?: list<int>|null}  $data
     *
     * @throws ApiException
     */
    public function execute(User $user, TestSuite $suite, array $data): TestRun
    {
        $targetBaseUrl = rtrim($data['target_base_url'], '/');

        // D07: reject targets outside the allowlist before anything is queued.
        $this->allowlistService->assertAllowed($targetBaseUrl);

        $testCaseIds = $this->resolveSelectedCaseIds($suite, $data['test_case_ids'] ?? null);

        /** @var TestRun $testRun */
        $testRun = $suite->testRuns()->create([
            'target_base_url' => $targetBaseUrl,
            'benchmark_variant' => $data['benchmark_variant'] ?? null,
            'status' => TestRunStatus::Queued,
            'totals' => null,
            'triggered_by' => $user->id,
        ]);

        // Default queue: a single worker process executes runs one at a time, which the per-case reset
        // of the shared benchmarks relies on (RUN_RESET_BENCHMARK_HOSTS); LLM jobs never delay it (S14-06).
        ExecuteTestRunJob::dispatch($testRun->id, $testCaseIds)->onQueue(QueueName::Default);

        return $testRun;
    }

    /**
     * Only VALID or APPROVED cases of this suite may be put into a run (docs/DESIGN.md §4.2).
     *
     * @param  list<int>|null  $ids
     * @return list<int>|null
     *
     * @throws ApiException
     */
    private function resolveSelectedCaseIds(TestSuite $suite, ?array $ids): ?array
    {
        if ($ids === null) {
            return null;
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));

        /** @var list<int> $runnable */
        $runnable = $suite->testCases()
            ->whereIn('id', $ids)
            ->whereIn('status', [TestCaseStatus::Valid->value, TestCaseStatus::Approved->value])
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $rejected = array_values(array_diff($ids, $runnable));
        if ($rejected !== []) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'Một số test case không thuộc bộ kiểm thử hoặc chưa ở trạng thái VALID/APPROVED.',
                ['test_case_ids' => ['Test case không hợp lệ để chạy: '.implode(', ', $rejected).'.']]
            );
        }

        sort($ids);

        return $ids;
    }
}
