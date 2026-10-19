<?php

namespace App\Http\Controllers;

use App\Actions\ApiSpecs\CreateApiSpecAction;
use App\Exceptions\ApiException;
use App\Http\Requests\Specs\UploadSpecRequest;
use App\Models\ApiSpec;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class SpecController extends Controller
{
    /**
     * Screen M06: List spec history and provide upload form.
     */
    public function index(Project $project): View
    {
        Gate::authorize('view', $project);

        $specs = $project->specs()
            ->withCount('endpoints')
            ->orderByDesc('id')
            ->get();

        return view('specs.index', [
            'project' => $project,
            'specs' => $specs,
        ]);
    }

    /**
     * Handle OpenAPI upload or direct text paste (Screen M06).
     */
    public function store(
        UploadSpecRequest $request,
        Project $project,
        CreateApiSpecAction $action,
    ): RedirectResponse {
        Gate::authorize('createSpec', $project);

        $rawContent = $request->getRawContent();
        if (trim($rawContent) === '') {
            return back()->withInput()->with('error', 'Vui lòng cung cấp nội dung đặc tả OpenAPI hợp lệ.');
        }

        if (strlen($rawContent) > 5 * 1024 * 1024) {
            return back()->withInput()->with('error', 'Kích thước nội dung đặc tả vượt quá giới hạn cho phép (tối đa 5 MB).');
        }

        try {
            $spec = $action->execute(
                project: $project,
                versionLabel: (string) $request->validated('version_label'),
                rawContent: $rawContent,
                sourceFilename: $request->getSourceFilename(),
                format: $request->resolveFormat(),
            );

            $endpointCount = $spec->endpoints()->count();

            return redirect()->route('projects.specs.show', [$project, $spec])
                ->with('success', "Đã phân tích thành công đặc tả OpenAPI phiên bản {$spec->version_label} (trích xuất {$endpointCount} endpoints).");
        } catch (ApiException $e) {
            // The parser's reasons (details.errors) are shown under the flash, not only the
            // generic "Không thể phân tích đặc tả OpenAPI." (S13-03).
            $reasons = $e->details['errors'] ?? null;

            return back()->withInput()
                ->with('error', $e->getMessage())
                ->with('spec_parse_errors', is_array($reasons) ? array_values($reasons) : []);
        } catch (Throwable $e) {
            Log::error('Lỗi hệ thống khi phân tích đặc tả OpenAPI', [
                'project_id' => $project->id,
                'exception' => $e,
            ]);

            $message = config('app.debug')
                ? 'Lỗi hệ thống khi phân tích đặc tả: '.$e->getMessage()
                : 'Đã xảy ra lỗi hệ thống khi xử lý đặc tả. Vui lòng liên hệ quản trị viên hoặc thử lại sau.';

            return back()->withInput()->with('error', $message);
        }
    }

    /**
     * Screen M07: Endpoints list & Schema details.
     */
    public function show(Project $project, ApiSpec $spec): View
    {
        if ($spec->project_id !== $project->id) {
            abort(404);
        }

        Gate::authorize('view', $project);

        $endpoints = $spec->endpoints()
            ->orderBy('path')
            ->orderBy('method')
            ->get();

        return view('specs.show', [
            'project' => $project,
            'spec' => $spec,
            'endpoints' => $endpoints,
        ]);
    }
}
