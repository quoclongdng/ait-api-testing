<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiSpecStatus;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApiSpecs\ListEndpointsRequest;
use App\Http\Resources\EndpointResource;
use App\Models\ApiSpec;
use Illuminate\Http\JsonResponse;

class EndpointController extends Controller
{
    /**
     * List normalized endpoints for an API spec.
     *
     * Access (403) and page/per_page (422) are checked by ListEndpointsRequest.
     */
    public function index(ListEndpointsRequest $request, ApiSpec $spec): JsonResponse
    {
        if ($spec->status === ApiSpecStatus::ParseFailed) {
            throw new ApiException(
                ErrorCode::SpecParseFailed,
                'Không thể xem danh sách endpoint vì đặc tả chưa được phân tích thành công.',
                ['errors' => $spec->parse_errors ?? []]
            );
        }

        /** @var array{page?: int|string, per_page?: int|string} $validated */
        $validated = $request->validated();
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 20);

        $paginator = $spec->endpoints()
            ->orderBy('id', 'asc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => EndpointResource::collection($paginator->items())->resolve(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
