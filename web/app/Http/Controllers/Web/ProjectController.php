<?php

namespace App\Http\Controllers\Web;

use App\Actions\Projects\CreateProjectAction;
use App\Actions\Projects\DeleteProjectAction;
use App\Actions\Projects\DescribeTargetAllowlistWarningAction;
use App\Actions\Projects\ListProjectsAction;
use App\Actions\Projects\UpdateProjectAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\ProjectIndexRequest;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index(ProjectIndexRequest $request, ListProjectsAction $action): View
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{search?: string|null} $validated */
        $validated = $request->validated();

        return view('projects.index', [
            'projects' => $action->execute($user, 12, $validated['search'] ?? null)->withQueryString(),
            'search' => $validated['search'] ?? '',
        ]);
    }

    public function store(
        StoreProjectRequest $request,
        CreateProjectAction $action,
        DescribeTargetAllowlistWarningAction $targetWarning,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        /** @var array{name: string, description?: string|null, default_target_url?: string|null} $validated */
        $validated = $request->validated();
        $project = $action->execute($user, $validated);

        return redirect()->route('projects.index')
            ->with('success', "Đã tạo dự án {$project->name} thành công.")
            ->with('warning', $targetWarning->execute($project->default_target_url));
    }

    public function update(
        UpdateProjectRequest $request,
        Project $project,
        UpdateProjectAction $action,
        DescribeTargetAllowlistWarningAction $targetWarning,
    ): RedirectResponse {
        /** @var array{name?: string, description?: string|null, default_target_url?: string|null} $validated */
        $validated = $request->validated();
        $project = $action->execute($project, $validated);

        return redirect()->route('projects.index')
            ->with('success', "Đã cập nhật dự án {$project->name}.")
            ->with('warning', $targetWarning->execute($project->default_target_url));
    }

    public function destroy(Request $request, Project $project, DeleteProjectAction $action): RedirectResponse
    {
        Gate::authorize('delete', $project);
        $name = $project->name;
        $action->execute($project);

        return redirect()->route('projects.index')
            ->with('success', "Đã xóa dự án {$name}.");
    }
}
