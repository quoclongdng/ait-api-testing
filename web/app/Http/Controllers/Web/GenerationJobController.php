<?php

namespace App\Http\Controllers\Web;

use App\Actions\Generation\CancelGenerationJobAction;
use App\Actions\Generation\ResolveLlmAvailabilityAction;
use App\Actions\Generation\StartGenerationJobAction;
use App\Actions\Generation\SummarizeGenerationJobAction;
use App\Data\Generation\GenerationJobInput;
use App\Enums\GenerationJobStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\GenerationJobs\StoreGenerationJobRequest;
use App\Models\GenerationJob;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\Support\GenerationJobError;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class GenerationJobController extends Controller
{
    public function create(TestSuite $suite, ResolveLlmAvailabilityAction $llmAvailability): View
    {
        Gate::authorize('view', $suite);

        $suite->load(['project.businessRules.endpoint', 'apiSpec.endpoints']);
        // One ai-service /health call: availability of LLM/HYBRID and the configured model.
        $llmOptions = $llmAvailability->formOptions();

        return view('generation.create', [
            'suite' => $suite,
            'project' => $suite->project,
            'apiSpec' => $suite->apiSpec,
            'endpoints' => $suite->apiSpec->endpoints->sortBy('id')->values(),
            'businessRules' => $suite->project->businessRules,
            // ADR-0007 AI_LLM_PROVIDER=disabled: Vietnamese reason why LLM/HYBRID are disabled, null when available.
            'llmUnavailableReason' => $llmOptions['reason'],
            'modelSuggestions' => $llmOptions['models'],
            'defaults' => [
                'temperature' => GenerationJobInput::DEFAULT_TEMPERATURE,
                'seed' => (int) config('ait.ai_service.default_seed'),
                'max_repair_attempts' => GenerationJobInput::DEFAULT_MAX_REPAIR_ATTEMPTS,
                'max_cases_per_endpoint' => GenerationJobInput::DEFAULT_MAX_CASES_PER_ENDPOINT,
            ],
        ]);
    }

    public function store(
        StoreGenerationJobRequest $request,
        TestSuite $suite,
        StartGenerationJobAction $action
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        /** @var array<string, mixed> $validated */
        $validated = $request->validated();
        /** @var string $strategy */
        $strategy = $validated['strategy'];

        try {
            // The job is created QUEUED and processed by the queue worker (D10, §4.1).
            // An empty endpoint selection means "every endpoint of the suite's spec".
            $job = $action->execute($user, $suite, GenerationJobInput::fromArray($strategy, $validated));
            $job->refresh();
        } catch (ApiException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Lỗi khi sinh test tự động', [
                'suite_id' => $suite->id,
                'exception' => $e,
            ]);

            $message = config('app.debug')
                ? 'Lỗi khi sinh test: '.$e->getMessage()
                : 'Đã xảy ra lỗi hệ thống trong quá trình sinh test. Vui lòng thử lại sau.';

            return back()->withInput()->with('error', $message);
        }

        if ($job->status === GenerationJobStatus::Failed) {
            return redirect()->route('generation-jobs.show', $job)
                ->with('error', "Tiến trình sinh test #{$job->id} thất bại: ".($job->error !== null && $job->error !== '' ? GenerationJobError::oneLine($job->error) : 'không rõ nguyên nhân.'));
        }

        return redirect()->route('generation-jobs.show', $job)
            ->with('success', "Đã tạo tiến trình sinh test #{$job->id} với chiến lược {$job->strategy->value}.");
    }

    public function show(GenerationJob $generationJob, SummarizeGenerationJobAction $summarize): View
    {
        Gate::authorize('view', $generationJob);

        $generationJob->load([
            'project',
            'testSuite',
            'apiSpec',
            'creator',
            // Creation order (ids follow the spec order of the endpoints), not PostgreSQL heap order.
            'testCases' => fn ($query) => $query->orderBy('id'),
            'testCases.endpoint',
            'llmCallLogs',
        ]);

        return view('generation.show', [
            'job' => $generationJob,
            'suite' => $generationJob->testSuite,
            'testCases' => $generationJob->testCases,
            'summary' => $summarize->execute($generationJob)->toArray(),
        ]);
    }

    public function cancel(GenerationJob $generationJob, CancelGenerationJobAction $action): RedirectResponse
    {
        Gate::authorize('cancel', $generationJob);

        try {
            $action->execute($generationJob);
        } catch (ApiException $e) {
            // The action names the actual status (RUNNING or already finished).
            return redirect()->route('generation-jobs.show', $generationJob)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('generation-jobs.show', $generationJob)
            ->with('success', "Đã hủy tiến trình sinh test #{$generationJob->id}.");
    }
}
