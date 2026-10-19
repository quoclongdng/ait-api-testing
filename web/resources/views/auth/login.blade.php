@extends('layouts.auth')

@section('title', 'Đăng nhập — '.config('app.name', 'AI Testing Platform'))
@section('description', 'Đăng nhập AI Testing Platform (AIT). Tài khoản do quản trị viên cấp, không có đăng ký tự do.')
@section('robots', 'noindex, follow')

@section('content')
<div class="flex w-full flex-col items-center">
    <a href="{{ route('home') }}" class="group mb-8 flex items-center gap-3 rounded-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-950" aria-label="AI Testing Platform — về trang chủ">
        <span aria-hidden="true" class="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-tr from-indigo-600 via-indigo-500 to-cyan-400 text-sm font-bold text-white shadow-lg shadow-indigo-500/25 transition-transform group-hover:scale-105">AIT</span>
        <span class="flex flex-col">
            <span class="text-lg font-bold tracking-tight text-white transition-colors group-hover:text-indigo-300">AI Testing Platform</span>
            <span class="-mt-0.5 text-xs font-medium text-slate-400">Đại học Duy Tân</span>
        </span>
    </a>
    <div class="relative z-10 w-full max-w-md rounded-2xl border border-slate-800 bg-slate-900/90 p-6 shadow-2xl backdrop-blur-xl sm:p-8">
        <!-- Header (M01): blank auth layout. -->

        <div class="mb-8">
            <h1 class="text-2xl font-bold tracking-tight text-white">Đăng nhập</h1>
            <p class="mt-1.5 text-sm leading-relaxed text-slate-400">
                Vào AI Testing Platform để sinh, chạy và đánh giá ca kiểm thử API.
            </p>
        </div>

        @if ($errors->any())
            <div id="login-errors" role="alert" class="mb-6 p-4 rounded-xl bg-rose-950/80 border border-rose-500/50 text-rose-200 text-xs shadow-lg">
                <div class="font-semibold text-rose-300 mb-1 flex items-center gap-2">
                    <svg aria-hidden="true" class="w-4 h-4 shrink-0 text-rose-400" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                    </svg>
                    Không thể đăng nhập:
                </div>
                <ul class="list-disc list-inside space-y-0.5 text-rose-300">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('login.post') }}" class="space-y-4">
            @csrf

            <div>
                <label for="email" class="block text-xs font-semibold text-slate-300 mb-1.5">Email</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    value="{{ old('email') }}"
                    required
                    autofocus
                    autocomplete="username"
                    @if ($errors->any()) aria-invalid="true" aria-describedby="login-errors" @endif
                    placeholder="ten@ait.local"
                    class="w-full px-4 py-2.5 rounded-xl border border-slate-700 bg-slate-800/80 text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500 transition text-sm"
                />
            </div>

            <div>
                <label for="password" class="block text-xs font-semibold text-slate-300 mb-1.5">Mật khẩu</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    required
                    autocomplete="current-password"
                    @if ($errors->any()) aria-invalid="true" aria-describedby="login-errors" @endif
                    class="w-full px-4 py-2.5 rounded-xl border border-slate-700 bg-slate-800/80 text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500 transition text-sm"
                />
            </div>

            <div class="flex items-center justify-between pt-1">
                <label class="flex items-center gap-2 cursor-pointer select-none">
                    <input
                        type="checkbox"
                        name="remember"
                        id="remember"
                        value="1"
                        class="h-4 w-4 rounded border-slate-600 accent-indigo-500 [color-scheme:dark] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-900"
                    />
                    <span class="text-xs text-slate-300 font-medium">Ghi nhớ đăng nhập</span>
                </label>
            </div>

            <button
                type="submit"
                id="login-button"
                class="w-full py-2.5 px-4 rounded-xl bg-gradient-to-r from-indigo-600 to-indigo-500 hover:from-indigo-500 hover:to-indigo-400 active:scale-[0.99] text-white font-semibold text-sm shadow-lg shadow-indigo-600/30 transition-all cursor-pointer flex items-center justify-center gap-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-900"
            >
                <span>Đăng nhập</span>
            </button>
        </form>

        @if (! empty($demoAccounts))
            <section aria-labelledby="demo-accounts-title" data-testid="demo-accounts" class="mt-6 rounded-xl border border-teal-500/30 bg-teal-500/5 p-4">
                <h2 id="demo-accounts-title" class="text-xs font-semibold uppercase tracking-wider text-teal-300">Tài khoản demo</h2>
                <p class="mt-1 text-xs text-slate-400">Dùng chung cho mọi người xem thử, mật khẩu <span class="font-mono font-semibold text-slate-200">{{ $demoPassword }}</span>. Tài khoản demo được khôi phục mỗi đêm.</p>
                <ul class="mt-3 space-y-1.5 text-xs">
                    @foreach ($demoAccounts as $account)
                        <li class="flex flex-wrap items-center justify-between gap-x-3 gap-y-0.5">
                            <span class="font-mono text-slate-100">{{ $account['email'] }}</span>
                            <span class="text-slate-400">{{ $account['role'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <p class="mt-6 pt-4 border-t border-slate-800/80 text-center text-xs text-slate-400 font-medium">
            Tài khoản do quản trị viên (ADMIN) cấp, không có đăng ký tự do.
        </p>
    </div>
</div>
@endsection
