<?php

namespace App\Http\Controllers\Web;

use App\Actions\TestCases\ListTestCaseLlmLogsAction;
use App\Actions\TestCases\TransitionTestCaseStatusAction;
use App\Actions\TestCases\UpdateTestCaseDslAction;
use App\Enums\TestCaseStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\TestCases\UpdateTestCaseRequest;
use App\Models\TestCase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class TestCaseController extends Controller
{
    public function show(TestCase $testCase, ListTestCaseLlmLogsAction $listLlmLogs): View
    {
        Gate::authorize('view', $testCase);

        $testCase->load(['suite.project', 'endpoint']);

        // The calls of this case's endpoint and its own REPAIR calls first; the job's calls for other
        // endpoints separately; other cases' REPAIR calls never (ListTestCaseLlmLogsAction, S13-03).
        $logs = $listLlmLogs->execute($testCase);

        return view('test-cases.show', [
            'testCase' => $testCase,
            'suite' => $testCase->suite,
            'endpoint' => $testCase->endpoint,
            'llmLogs' => $logs['related'],
            'otherLlmLogs' => $logs['others'],
        ]);
    }

    public function update(
        UpdateTestCaseRequest $request,
        TestCase $testCase,
        UpdateTestCaseDslAction $action
    ): RedirectResponse {
        /** @var array{title?: string, dsl: array<string, mixed>} $validated */
        $validated = $request->validated();

        try {
            $updated = $action->execute($testCase, $request->dslObject(), $validated['title'] ?? null);
        } catch (ApiException $e) {
            $message = $e->getMessage();
            $dslErrors = $e->details['dsl'] ?? null;
            if (is_array($dslErrors) && $dslErrors !== []) {
                $shown = array_map(
                    fn (mixed $error): string => is_string($error) ? $error : (string) json_encode($error, JSON_UNESCAPED_UNICODE),
                    array_slice($dslErrors, 0, 3)
                );
                $message .= ' Lỗi: '.implode('; ', $shown).(count($dslErrors) > 3 ? '; ...' : '');
            }

            return redirect()->route('test-cases.show', $testCase)->withInput()->with('error', $message);
        } catch (\Throwable $e) {
            Log::error('Lỗi khi cập nhật DSL test case', [
                'test_case_id' => $testCase->id,
                'exception' => $e,
            ]);

            $message = config('app.debug')
                ? 'Lỗi cập nhật DSL: '.$e->getMessage()
                : 'Đã xảy ra lỗi hệ thống khi cập nhật DSL. Vui lòng kiểm tra lại định dạng.';

            return back()->withInput()->with('error', $message);
        }

        if ($updated->status === TestCaseStatus::Invalid) {
            return redirect()->route('test-cases.show', $updated)
                ->with('warning', 'Đã lưu DSL nhưng phát hiện lỗi cấu trúc/ngữ nghĩa. Xem tab "Lỗi Validation".');
        }

        return redirect()->route('test-cases.show', $updated)
            ->with('success', 'Đã cập nhật DSL thành công. Trạng thái test case: HỢP LỆ (VALID).');
    }

    public function approve(TestCase $testCase, TransitionTestCaseStatusAction $action): RedirectResponse
    {
        Gate::authorize('approve', $testCase);

        try {
            $action->execute($testCase, TestCaseStatus::Approved);
        } catch (ApiException $e) {
            return redirect()->route('test-cases.show', $testCase)
                ->with('error', 'Chỉ có thể duyệt test case khi ở trạng thái HỢP LỆ (VALID).');
        }

        return redirect()->route('test-cases.show', $testCase)
            ->with('success', "Đã duyệt test case #{$testCase->id} thành công.");
    }

    public function archive(TestCase $testCase, TransitionTestCaseStatusAction $action): RedirectResponse
    {
        Gate::authorize('archive', $testCase);

        try {
            $action->execute($testCase, TestCaseStatus::Archived);
        } catch (ApiException $e) {
            return redirect()->route('test-cases.show', $testCase)
                ->with('error', 'Chỉ có thể lưu trữ test case ở trạng thái VALID hoặc APPROVED.');
        }

        return redirect()->route('test-cases.show', $testCase)
            ->with('success', "Đã lưu trữ test case #{$testCase->id}.");
    }
}
