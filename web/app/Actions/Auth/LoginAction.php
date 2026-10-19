<?php

namespace App\Actions\Auth;

use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class LoginAction
{
    /**
     * Attempt to authenticate the user and handle rate limiting.
     *
     * Two counters of failed attempts (config ait.rate_limits, docs/DESIGN.md §7/§9):
     * - email|ip: LOGIN_MAX_ATTEMPTS per minute against one account (brute force);
     * - ip: LOGIN_MAX_ATTEMPTS_PER_IP within LOGIN_IP_DECAY_SECONDS across every email (password
     *   spraying). A successful login clears only the email|ip counter, so one valid account cannot
     *   be used to reset the per-IP budget.
     * Both lockouts answer with the same generic message, whether or not the email exists.
     *
     * @throws ValidationException
     */
    public function execute(LoginRequest $request): User
    {
        $throttleKey = $request->throttleKey();
        $ipKey = $request->ipThrottleKey();
        $perEmailIp = max(1, (int) config('ait.rate_limits.login_per_email_ip', 5));
        $perIp = max(1, (int) config('ait.rate_limits.login_per_ip', 20));
        $ipDecay = max(60, (int) config('ait.rate_limits.login_ip_decay_seconds', 600));

        foreach ([$throttleKey => $perEmailIp, $ipKey => $perIp] as $key => $maxAttempts) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                $this->lockedOut(RateLimiter::availableIn($key));
            }
        }

        $credentials = [
            'email' => (string) $request->input('email'),
            'password' => (string) $request->input('password'),
        ];
        $remember = $request->boolean('remember');

        if (! Auth::attempt($credentials, $remember)) {
            RateLimiter::hit($throttleKey);
            RateLimiter::hit($ipKey, $ipDecay);

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        if (! $user->is_active) {
            Auth::logout();
            RateLimiter::hit($throttleKey);
            RateLimiter::hit($ipKey, $ipDecay);

            throw ValidationException::withMessages([
                'email' => 'Tài khoản của bạn đã bị khóa. Vui lòng liên hệ Quản trị viên.',
            ]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        return $user;
    }

    /**
     * @throws ValidationException
     */
    private function lockedOut(int $seconds): never
    {
        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => (int) ceil($seconds / 60),
            ]),
        ]);
    }
}
