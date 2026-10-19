<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\System\CheckHealthAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(CheckHealthAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute()]);
    }
}
