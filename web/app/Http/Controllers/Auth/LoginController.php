<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\LoginAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Support\Demo\DemoShowcase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    /**
     * Display the login view.
     */
    public function showLoginForm(Request $request): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->intended('/dashboard');
        }

        return view('auth.login', [
            // Public demo accounts of the showcase (S14-05); empty unless DEMO_SHOWCASE_ENABLED=true.
            'demoAccounts' => DemoShowcase::loginHints(),
            'demoPassword' => DemoShowcase::PASSWORD,
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function login(LoginRequest $request, LoginAction $action): RedirectResponse|JsonResponse
    {
        $user = $action->execute($request);
        $roleValue = $user->role->value;

        if ($request->wantsJson()) {
            return response()->json([
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $roleValue,
                ],
            ]);
        }

        return redirect()->intended('/dashboard');
    }
}
