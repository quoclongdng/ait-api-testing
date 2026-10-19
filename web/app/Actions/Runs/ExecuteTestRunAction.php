<?php

namespace App\Actions\Runs;

use App\Data\Execution\AssertionResult;
use App\Data\Execution\StepExecutionResult;
use App\Data\Experiments\AuthFixture;
use App\Enums\TestCaseStatus;
use App\Enums\TestRunStatus;
use App\Enums\TestVerdict;
use App\Exceptions\ApiException;
use App\Models\ApiSpec;
use App\Models\TestCase;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Services\Execution\AssertionEngine;
use App\Services\Execution\CredentialInjector;
use App\Services\Execution\TestExecutor;
use App\Services\Support\SafeErrorMessage;
use App\Services\Target\TargetAllowlistService;
use App\Support\SensitiveDataMasker;
use App\Support\StepLabel;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Executes a QUEUED TestRun inside the worker (docs/DESIGN.md §4.3, §10 steps 6–7).
 *
 * - Only VALID / APPROVED cases are run (§4.2).
 * - Requests go through TestExecutor (allowlist D07, timeouts, masked logs).
 * - PASS/FAIL is decided only by the deterministic AssertionEngine (D03).
 * - A cancel observed between cases stops the run; remaining cases are stored as SKIPPED.
 * - Optional per-case hooks isolate cases on benchmark sandboxes (experiments: $betweenCases;
 *   M12 runs on a benchmark: $beforeEachCase, see ExecuteTestRunJob).
 */
class ExecuteTestRunAction
{
    public function __construct(
        private TestExecutor $testExecutor,
        private AssertionEngine $assertionEngine,
        private TransitionTestRunStatusAction $transition,
        private TargetAllowlistService $allowlistService,
        private CredentialInjector $credentials,
    ) {}

    /**
     * @param  list<int>|null  $testCaseIds
     * @param  AuthFixture|null  $auth  Experiment credentials (ADR-0006, with tokens): defines the
     *                                  `auth_token_<role>` run variables and the default Authorization header.
     * @param  (Closure(): void)|null  $betweenCases  Called before every case except the first (experiment
     *                                                case isolation, ADR-0006 addendum 2026-10-12: resets the
     *                                                sandbox). An exception from it aborts the run (the caller
     *                                                marks it FAILED). Ordinary M12 runs pass null.
     * @param  (Closure(): void)|null  $beforeEachCase  Called before every case, the first included (M12 run on
     *                                                  a benchmark sandbox: BenchmarkTargetReset, ADR-0006
     *                                                  addendum 2026-10-13). An exception from it stores that case
     *                                                  as ERROR without sending it, and the run continues.
     */
    public function execute(int $testRunId, ?array $testCaseIds = null, ?AuthFixture $auth = null, ?Closure $betweenCases = null, ?Closure $beforeEachCase = null): void
    {
        $testRun = TestRun::find($testRunId);
        if (! $testRun instanceof TestRun || $testRun->status !== TestRunStatus::Queued) {
            // Missing, cancelled before start, or already picked up by another worker.
            return;
        }

        try {
            $this->transition->execute($testRun, TestRunStatus::Running);
        } catch (ApiException) {
            return; // cancelled concurrently
        }

        $testRun->loadMissing('testSuite.apiSpec.endpoints');
        $spec = $testRun->testSuite->apiSpec;

        $query = $testRun->testSuite->testCases()
            ->whereIn('status', [TestCaseStatus::Valid->value, TestCaseStatus::Approved->value])
            ->orderBy('id');
        if ($testCaseIds !== null) {
            $query->whereIn('id', $testCaseIds);
        }
        /** @var Collection<int, TestCase> $testCases */
        $testCases = $query->get();

        $totals = ['total' => $testCases->count(), 'pass' => 0, 'fail' => 0, 'error' => 0, 'skipped' => 0];

        // D07: the allowlist may have changed since the run was created.
        if (! $this->allowlistService->isAllowed($testRun->target_base_url)) {
            foreach ($testCases as $testCase) {
                $this->storeResult($testRun, $testCase, TestVerdict::Error, error: 'TARGET_NOT_ALLOWED: Hệ thống đích không thuộc danh sách cho phép.');
                $totals['error']++;
            }
            $this->finish($testRun, TestRunStatus::Failed, $totals);

            return;
        }

        foreach ($testCases->values() as $index => $testCase) {
            if ($this->isCancelled($testRun)) {
                foreach ($testCases->values()->slice($index) as $remaining) {
                    $this->storeResult($testRun, $remaining, TestVerdict::Skipped, error: 'Lần chạy đã bị hủy trước khi thực thi test case này.');
                    $totals['skipped']++;
                }
                break;
            }

            if ($betweenCases !== null && $index > 0) {
                $betweenCases();
            }

            $verdict = $this->prepareCase($testRun, $testCase, $beforeEachCase)
                ?? $this->runCase($testRun, $testCase, $spec, $auth);
            $totals[match ($verdict) {
                TestVerdict::Pass => 'pass',
                TestVerdict::Fail => 'fail',
                TestVerdict::Skipped => 'skipped',
                TestVerdict::Error => 'error',
            }]++;
        }

        $this->finish($testRun, TestRunStatus::Completed, $totals);
    }

    /**
     * Moves a run that crashed in the worker to FAILED (§4.3). QUEUED runs pass through RUNNING
     * so the state machine is respected; terminal runs are left untouched.
     *
     * @param  list<int>|null  $testCaseIds  Subset the run was started with (null: every runnable case).
     */
    public function markFailed(int $testRunId, ?array $testCaseIds = null): void
    {
        $testRun = TestRun::find($testRunId);
        if (! $testRun instanceof TestRun) {
            return;
        }

        try {
            if ($testRun->status === TestRunStatus::Queued) {
                $this->transition->execute($testRun, TestRunStatus::Running);
            }
            if ($testRun->status === TestRunStatus::Running) {
                // Killed mid-run (job timeout, OOM, restart): the cases never reached are stored as
                // SKIPPED with the reason, and totals are filled, so M12/M13 stay consistent.
                $totals = $this->recordUnexecuted($testRun, $testCaseIds);
                $this->transition->execute($testRun, TestRunStatus::Failed, ['totals' => $totals]);
            }
        } catch (ApiException) {
            // Cancelled concurrently: nothing to do.
        }
    }

    /**
     * @param  list<int>|null  $testCaseIds
     * @return array{total: int, pass: int, fail: int, error: int, skipped: int}
     */
    private function recordUnexecuted(TestRun $testRun, ?array $testCaseIds): array
    {
        $done = TestResult::query()->where('test_run_id', $testRun->id)->pluck('test_case_id')->all();

        $query = $testRun->testSuite()->firstOrFail()->testCases()
            ->whereIn('status', [TestCaseStatus::Valid->value, TestCaseStatus::Approved->value])
            ->whereNotIn('id', $done)
            ->orderBy('id');
        if ($testCaseIds !== null) {
            $query->whereIn('id', $testCaseIds);
        }
        foreach ($query->get() as $testCase) {
            $this->storeResult($testRun, $testCase, TestVerdict::Skipped, error: 'Lần chạy bị dừng (vượt thời gian xử lý hoặc tiến trình worker bị dừng) trước khi thực thi test case này.');
        }

        $counts = TestResult::query()
            ->where('test_run_id', $testRun->id)
            ->selectRaw('verdict, count(*) as aggregate')
            ->groupBy('verdict')
            ->pluck('aggregate', 'verdict')
            ->map(fn (mixed $n): int => (int) $n)
            ->all();
        $totals = [
            'total' => 0,
            'pass' => $counts[TestVerdict::Pass->value] ?? 0,
            'fail' => $counts[TestVerdict::Fail->value] ?? 0,
            'error' => $counts[TestVerdict::Error->value] ?? 0,
            'skipped' => $counts[TestVerdict::Skipped->value] ?? 0,
        ];
        $totals['total'] = $totals['pass'] + $totals['fail'] + $totals['error'] + $totals['skipped'];

        return $totals;
    }

    /**
     * Runs $beforeEachCase (benchmark reset). On failure the case is stored as ERROR and not sent:
     * running it on dirty sandbox data would turn leftovers of earlier runs into false FAILs.
     *
     * @param  (Closure(): void)|null  $beforeEachCase
     */
    private function prepareCase(TestRun $testRun, TestCase $testCase, ?Closure $beforeEachCase): ?TestVerdict
    {
        if ($beforeEachCase === null) {
            return null;
        }

        try {
            $beforeEachCase();
        } catch (Throwable $e) {
            $reason = $e instanceof ApiException
                ? "{$e->errorCode->value}: {$e->getMessage()}"
                : SafeErrorMessage::from($e, 'Lỗi hệ thống khi đặt lại sandbox.', ['test_run_id' => $testRun->id, 'test_case_id' => $testCase->id]);
            $this->storeResult($testRun, $testCase, TestVerdict::Error, error: 'Không đặt lại được dữ liệu benchmark về dữ liệu mẫu trước test case này nên test case không được gửi. '.$reason);

            return TestVerdict::Error;
        }

        return null;
    }

    private function runCase(TestRun $testRun, TestCase $testCase, ?ApiSpec $spec, ?AuthFixture $auth = null): TestVerdict
    {
        $runVariables = $auth?->variables() ?? [];
        try {
            // The `array` cast decodes `{}` as `[]`; run the stored JSON so an empty object body or
            // an empty-object assertion value keeps its JSON type on the wire and in the oracle.
            $dsl = $this->objectPreservingDsl($testCase);
            if ($auth !== null && (is_array($dsl) || is_object($dsl))) {
                $dsl = $this->credentials->apply($dsl, $auth, $spec);
            }
            // Run variables (auth_token_<role>) take precedence over the case's own `variables`.
            $execution = $this->testExecutor->execute($dsl, $testRun->target_base_url, $runVariables);
        } catch (ApiException $e) {
            $this->storeResult($testRun, $testCase, TestVerdict::Error, error: "{$e->errorCode->value}: {$e->getMessage()}");

            return TestVerdict::Error;
        } catch (Throwable $e) {
            $this->storeResult($testRun, $testCase, TestVerdict::Error, error: SafeErrorMessage::from(
                $e,
                'Không thể thực thi test case do lỗi hệ thống.',
                ['test_run_id' => $testRun->id, 'test_case_id' => $testCase->id],
            ));

            return TestVerdict::Error;
        }

        /** @var array<int, mixed> $dslSteps */
        $dslSteps = is_array($execution->dsl['steps'] ?? null) ? array_values($execution->dsl['steps']) : [];
        $variables = array_replace($execution->initialVariables, $runVariables);
        /** @var list<AssertionResult> $assertions */
        $assertions = [];

        foreach ($execution->stepResults as $stepResult) {
            $variables = array_replace($variables, $stepResult->extractedVariables);
            $stepSpec = $dslSteps[$stepResult->stepIndex - 1] ?? null;

            if ($stepResult->response === null || ! is_array($stepSpec)) {
                continue;
            }

            /** @var array<string, mixed> $stepSpec */
            array_push($assertions, ...$this->assertionEngine->assertStep($stepSpec, $stepResult->response, $spec, $variables));

            if ($stepResult->errorMessage !== null) {
                // A response arrived but post-processing (extract) failed: record it as a failed check.
                $assertions[] = new AssertionResult(
                    type: 'extract',
                    passed: false,
                    expected: null,
                    actual: null,
                    message: $stepResult->errorMessage,
                );
            }
        }

        [$verdict, $error] = $this->determineCaseVerdict($execution->stepResults, $assertions);

        $last = $execution->lastStep();

        $this->storeResult(
            $testRun,
            $testCase,
            $verdict,
            durationMs: $execution->durationMs,
            requestLog: ($last?->requestLog() ?? []) + ['steps' => $execution->requestLogs()],
            responseLog: ($last?->responseLog() ?? []) + ['steps' => $execution->responseLogs()],
            assertionResults: array_map(fn (AssertionResult $r): array => $r->toArray(), $assertions),
            error: $error,
        );

        return $verdict;
    }

    private function objectPreservingDsl(TestCase $testCase): mixed
    {
        $raw = $testCase->getRawOriginal('dsl');
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, false);
            if (is_object($decoded)) {
                return $decoded;
            }
        }

        return $testCase->dsl;
    }

    /**
     * Verdict rules (docs/DESIGN.md §4.3, D03):
     * - ERROR only when a step got no response (request not sent / transport failure / timeout)
     *   and no assertion evaluated on an earlier step failed, or when no assertion was evaluated.
     * - An assertion that already failed on an earlier step makes the case FAIL ("assertion sai",
     *   §4.3) even if a later step then got no response; the transport error is kept as context.
     * - A step that received a response but failed afterwards (e.g. `extract` path missing from
     *   the body) is FAIL: the response did not have the shape the case relies on. The step
     *   error is kept as context.
     * - Otherwise the AssertionEngine decides PASS/FAIL.
     *
     * @param  list<StepExecutionResult>  $stepResults
     * @param  list<AssertionResult>  $assertions
     * @return array{0: TestVerdict, 1: ?string}
     */
    private function determineCaseVerdict(array $stepResults, array $assertions): array
    {
        $failedStep = null;
        foreach ($stepResults as $stepResult) {
            if ($stepResult->errorMessage !== null || $stepResult->response === null) {
                $failedStep = $stepResult;
                break;
            }
        }

        if ($failedStep !== null) {
            $error = "Bước {$failedStep->stepIndex} ('".StepLabel::display($failedStep->stepName)."'): ".($failedStep->errorMessage ?? 'không nhận được response.');

            if ($failedStep->response === null) {
                // $assertions only holds results of steps that received a response (all earlier).
                foreach ($assertions as $assertion) {
                    if (! $assertion->passed) {
                        return [TestVerdict::Fail, $error];
                    }
                }

                return [TestVerdict::Error, $error];
            }

            return [TestVerdict::Fail, $error];
        }

        if ($assertions === []) {
            return [TestVerdict::Error, 'Không có assertion nào được đánh giá.'];
        }

        return [$this->assertionEngine->determineVerdict($assertions), null];
    }

    /**
     * test_results are insert-only (docs/DESIGN.md §5).
     *
     * @param  array<string, mixed>  $requestLog
     * @param  array<string, mixed>  $responseLog
     * @param  list<array<string, mixed>>  $assertionResults
     */
    private function storeResult(
        TestRun $testRun,
        TestCase $testCase,
        TestVerdict $verdict,
        int $durationMs = 0,
        array $requestLog = ['steps' => []],
        array $responseLog = ['steps' => []],
        array $assertionResults = [],
        ?string $error = null,
    ): void {
        TestResult::create([
            'test_run_id' => $testRun->id,
            'test_case_id' => $testCase->id,
            'verdict' => $verdict,
            'duration_ms' => max(0, $durationMs),
            'request_log' => $requestLog,
            'response_log' => $responseLog,
            'assertion_results' => $assertionResults,
            // Defence in depth for §9: no credential carried by a URL in an error message is stored.
            'error_message' => $error === null ? null : SensitiveDataMasker::maskText($error),
        ]);
    }

    private function isCancelled(TestRun $testRun): bool
    {
        $status = TestRun::query()->whereKey($testRun->id)->value('status');

        return ($status instanceof TestRunStatus ? $status : TestRunStatus::tryFrom((string) $status)) === TestRunStatus::Cancelled;
    }

    /**
     * @param  array{total: int, pass: int, fail: int, error: int, skipped: int}  $totals
     */
    private function finish(TestRun $testRun, TestRunStatus $status, array $totals): void
    {
        try {
            $this->transition->execute($testRun, $status, ['totals' => $totals]);
        } catch (ApiException) {
            // Cancelled while the last case was running: keep CANCELLED, still record totals.
            $testRun->refresh();
            $testRun->totals = $totals;
            $testRun->save();
        }
    }
}
