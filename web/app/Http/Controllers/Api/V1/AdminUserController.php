<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\User\CreateUserAction;
use App\Actions\User\ListUsersAction;
use App\Actions\User\UpdateUserAction;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListUsersRequest;
use App\Http\Requests\Admin\StoreUserApiRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET/POST /api/v1/admin/users and PATCH /api/v1/admin/users/{userId} (ADMIN only, UserPolicy).
 */
class AdminUserController extends Controller
{
    public function index(ListUsersRequest $request, ListUsersAction $action): JsonResponse
    {
        /** @var array{search?: string|null, role?: string|null, status?: string|null, per_page?: int} $validated */
        $validated = $request->validated();
        $users = $action->execute($validated, (int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => collect($users->items())
                ->map(fn (User $user): array => (new UserResource($user))->resolve($request))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function store(StoreUserApiRequest $request, CreateUserAction $action): JsonResponse
    {
        /** @var array{name: string, email: string, password: string, role: UserRole|string, is_active?: bool|null} $validated */
        $validated = $request->validated();
        $user = $action->execute($validated);

        return response()->json([
            'data' => (new UserResource($user))->resolve($request),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdateUserRequest $request, User $user, UpdateUserAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        /** @var array{name?: string, role?: string, is_active?: bool} $validated */
        $validated = $request->validated();

        return response()->json([
            'data' => (new UserResource($action->execute($actor, $user, $validated)))->resolve($request),
        ]);
    }
}
