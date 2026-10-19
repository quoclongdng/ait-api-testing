<?php

namespace App\Http\Controllers\Admin;

use App\Actions\AllowedTargets\CreateAllowedTargetAction;
use App\Actions\AllowedTargets\DeleteAllowedTargetAction;
use App\Actions\AllowedTargets\ListAllowedTargetsAction;
use App\Actions\System\DescribeLlmConfigurationAction;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AllowedTargets\StoreAllowedTargetRequest;
use App\Models\AllowedTarget;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AllowedTargetController extends Controller
{
    public function index(Request $request, ListAllowedTargetsAction $action, DescribeLlmConfigurationAction $llm): View
    {
        Gate::authorize('viewAny', AllowedTarget::class);

        // M04 shows the provider/model reported by ai-service, not web-side guesses (§8, ADR-0004).
        return view('admin.allowed-targets.index', [
            'allowedTargets' => $action->execute(),
            'environmentTargets' => config('ait.allowed_target_hosts', []),
            'llmConfig' => $llm->execute(),
        ]);
    }

    public function store(StoreAllowedTargetRequest $request, CreateAllowedTargetAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{host: string, port?: int|null, note?: string|null} $validated */
        $validated = $request->validated();

        try {
            $target = $action->execute($user, $validated);
        } catch (ApiException $exception) {
            return back()->withInput()->withErrors(['host' => $exception->getMessage()]);
        }

        return redirect()->route('admin.allowed-targets.index')
            ->with('success', "Đã thêm {$target->host} vào allowlist.");
    }

    public function destroy(Request $request, AllowedTarget $allowedTarget, DeleteAllowedTargetAction $action): RedirectResponse
    {
        Gate::authorize('delete', $allowedTarget);
        $host = $allowedTarget->host;
        $action->execute($allowedTarget);

        return redirect()->route('admin.allowed-targets.index')
            ->with('success', "Đã xóa {$host} khỏi allowlist.");
    }
}
