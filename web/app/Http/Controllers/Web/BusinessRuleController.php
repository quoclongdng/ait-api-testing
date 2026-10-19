<?php

namespace App\Http\Controllers\Web;

use App\Actions\BusinessRules\SearchBusinessRulesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessRules\StoreBusinessRuleRequest;
use App\Http\Requests\BusinessRules\UpdateBusinessRuleRequest;
use App\Models\BusinessRule;
use App\Models\Endpoint;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class BusinessRuleController extends Controller
{
    /**
     * Screen M08: Project's Business Rules management.
     */
    public function index(Request $request, Project $project, SearchBusinessRulesAction $searchRules): View
    {
        Gate::authorize('view', $project);

        $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'endpoint_id' => ['nullable', 'string', 'max:50'],
        ], [
            'search.string' => 'Từ khóa tìm kiếm phải là chuỗi ký tự.',
            'endpoint_id.string' => 'Bộ lọc endpoint phải là giá trị đơn lẻ, không được là mảng.',
        ]);

        $search = $request->query('search');
        $endpointFilter = $request->query('endpoint_id');

        $businessRules = $searchRules->execute(
            $project,
            is_string($search) ? $search : null,
            is_string($endpointFilter) ? $endpointFilter : null,
        )->withQueryString();

        // Endpoints of every spec version of this project, newest spec first; the view groups them by
        // version so identical "[GET] /path" entries of two versions can be told apart (S13-03).
        $endpoints = Endpoint::query()
            ->with('apiSpec')
            ->whereHas('apiSpec', fn ($q) => $q->where('project_id', $project->id))
            ->orderByDesc('api_spec_id')
            ->orderBy('path')
            ->orderBy('method')
            ->get();

        $stats = [
            'total' => $project->businessRules()->count(),
            'global' => $project->businessRules()->whereNull('endpoint_id')->count(),
            'endpoint_specific' => $project->businessRules()->whereNotNull('endpoint_id')->count(),
        ];

        return view('business-rules.index', [
            'project' => $project,
            'businessRules' => $businessRules,
            'endpoints' => $endpoints,
            'stats' => $stats,
            'filters' => [
                'search' => is_string($search) ? $search : '',
                'endpoint_id' => is_string($endpointFilter) ? $endpointFilter : '',
            ],
        ]);
    }

    public function store(StoreBusinessRuleRequest $request, Project $project): RedirectResponse
    {
        Gate::authorize('create', [BusinessRule::class, $project]);

        /** @var array{code: string, endpoint_id?: int|null, description: string} $validated */
        $validated = $request->validated();

        try {
            $rule = $project->businessRules()->create($validated);

            return redirect()->route('projects.business-rules.index', $project)
                ->with('success', "Đã thêm quy tắc nghiệp vụ {$rule->code} thành công.");
        } catch (Throwable $e) {
            Log::error('Lỗi khi thêm quy tắc nghiệp vụ', [
                'project_id' => $project->id,
                'exception' => $e,
            ]);

            $message = config('app.debug')
                ? 'Lỗi khi thêm quy tắc: '.$e->getMessage()
                : 'Đã xảy ra lỗi hệ thống khi lưu quy tắc nghiệp vụ. Vui lòng thử lại sau.';

            return back()->withInput()->with('error', $message);
        }
    }

    public function update(
        UpdateBusinessRuleRequest $request,
        Project $project,
        BusinessRule $businessRule
    ): RedirectResponse {
        if ($businessRule->project_id !== $project->id) {
            abort(404);
        }

        Gate::authorize('update', $businessRule);

        /** @var array{code: string, endpoint_id?: int|null, description: string} $validated */
        $validated = $request->validated();

        try {
            $businessRule->update($validated);

            return redirect()->route('projects.business-rules.index', $project)
                ->with('success', "Đã cập nhật quy tắc nghiệp vụ {$businessRule->code}.");
        } catch (Throwable $e) {
            Log::error('Lỗi khi cập nhật quy tắc nghiệp vụ', [
                'rule_id' => $businessRule->id,
                'exception' => $e,
            ]);

            $message = config('app.debug')
                ? 'Lỗi khi cập nhật: '.$e->getMessage()
                : 'Đã xảy ra lỗi hệ thống khi cập nhật quy tắc nghiệp vụ.';

            return back()->withInput()->with('error', $message);
        }
    }

    public function destroy(
        Request $request,
        Project $project,
        BusinessRule $businessRule
    ): RedirectResponse {
        if ($businessRule->project_id !== $project->id) {
            abort(404);
        }

        Gate::authorize('delete', $businessRule);

        $code = $businessRule->code;
        $businessRule->delete();

        return redirect()->route('projects.business-rules.index', $project)
            ->with('success', "Đã xóa quy tắc nghiệp vụ {$code}.");
    }
}
