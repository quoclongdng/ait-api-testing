<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\LogoutAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LogoutController extends Controller
{
    /**
     * Log the user out of the application.
     */
    public function logout(Request $request, LogoutAction $action): RedirectResponse|JsonResponse
    {
        $action->execute($request);

        if ($request->wantsJson()) {
            return response()->json([
                'data' => [
                    'message' => __('Logged out successfully.'),
                ],
            ]);
        }

        return redirect()->route('login');
    }
}
