<?php

namespace App\Http\Controllers\Admin;

use App\Actions\User\CreateUserAction;
use App\Actions\User\ListUsersAction;
use App\Actions\User\ToggleUserStatusAction;
use App\Actions\User\UpdateUserAction;
use App\Actions\User\UpdateUserRoleAction;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateUserRequest;
use App\Http\Requests\Admin\ListUsersRequest;
use App\Http\Requests\Admin\ToggleUserStatusRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Requests\Admin\UpdateUserRoleRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserController extends Controller
{
    /**
     * Display a listing of users (M03).
     */
    public function index(ListUsersRequest $request, ListUsersAction $action): View|RedirectResponse
    {
        /** @var array{search?: string|null, role?: string|null, status?: string|null} $filters */
        $filters = $request->validated();
        $users = $action->execute($filters)->withQueryString();

        // A page past the end (a stale bookmark, ?page=99) shows "Trang 99/2" and an empty table:
        // send the admin to the last page that exists, keeping the filters (S13-03).
        if ($users->currentPage() > max(1, $users->lastPage())) {
            return redirect()->route('admin.users.index', array_merge(
                $request->except('page'),
                ['page' => max(1, $users->lastPage())],
            ));
        }

        return view('admin.users.index', [
            'users' => $users,
            'roles' => UserRole::cases(),
            'filters' => [
                'search' => (string) ($filters['search'] ?? ''),
                'role' => (string) ($filters['role'] ?? ''),
                'status' => (string) ($filters['status'] ?? ''),
            ],
        ]);
    }

    /**
     * Store a newly created user (M03 Modal).
     */
    public function store(CreateUserRequest $request, CreateUserAction $action): RedirectResponse
    {
        /** @var array{name: string, email: string, password: string, role: UserRole|string, is_active?: bool|null} $validated */
        $validated = $request->validated();
        $action->execute($validated);

        return redirect()->route('admin.users.index')
            ->with('success', 'Đã cấp tài khoản người dùng mới thành công.');
    }

    /**
     * Change a user's name, role or active flag (M03 Action, docs/DESIGN.md §1).
     */
    public function update(UpdateUserRequest $request, User $user, UpdateUserAction $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        /** @var array{name?: string, role?: string, is_active?: bool} $validated */
        $validated = $request->validated();

        try {
            $updated = $action->execute($actor, $user, $validated);
        } catch (ApiException $e) {
            return redirect()->to($this->listUrl($request))->with('error', $e->getMessage());
        }

        return redirect()->to($this->listUrl($request))
            ->with('success', "Đã cập nhật tài khoản {$updated->email} (vai trò {$updated->role->value}).");
    }

    /**
     * Change the role of a user (M03 Action).
     */
    public function updateRole(UpdateUserRoleRequest $request, User $user, UpdateUserRoleAction $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $action->execute($actor, $user, $request->role());

        return redirect()->to($this->listUrl($request))
            ->with('success', "Đã đổi vai trò tài khoản {$user->email} thành {$user->role->label()}.");
    }

    /**
     * Toggle active/inactive status of a user (M03 Action).
     */
    public function toggleStatus(ToggleUserStatusRequest $request, User $user, ToggleUserStatusAction $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $updated = $action->execute($actor, $user, $request->targetActive());

        $statusText = $updated->is_active ? 'mở khóa' : 'khóa';

        return redirect()->to($this->listUrl($request))
            ->with('success', "Đã {$statusText} tài khoản {$updated->email} thành công.");
    }

    /**
     * M03 list URL to return to after a row action, keeping the search, filters and page the admin
     * was on (S13-03). Only a previous URL that is the M03 list itself is reused, so a forged
     * Referer cannot turn this into an open redirect.
     */
    private function listUrl(Request $request): string
    {
        $index = route('admin.users.index');
        $previous = url()->previous();

        if ($previous === $index || str_starts_with($previous, $index.'?')) {
            return $previous;
        }

        return $index;
    }
}
