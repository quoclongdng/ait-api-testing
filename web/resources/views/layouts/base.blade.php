{{--
    Common base of the two application shells (S14-04): <head>, skip link, responsive sidebar
    (fixed from xl, off-canvas drawer below with focus trap), top bar with the user menu, flash
    messages, main content and footer. Never extended directly: layouts.admin and layouts.tester set
    $shell (identity, accent classes) and the `sidebar` section, screens extend layouts.app (role
    of the signed-in user, App\Support\RoleLayout) or layouts.admin (admin/* screens). The <head>
    core is shared with the guest layout through layouts.partials.head.
--}}
@php
    /** @var array<string, string> $shell */
    $currentUser = auth()->user();
    $userInitial = $currentUser
        ? mb_strtoupper(mb_substr(\Illuminate\Support\Str::of($currentUser->name)->trim()->afterLast(' ')->toString(), 0, 1))
        : '?';
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    @include('layouts.partials.head', [
        'title' => html_entity_decode(trim($__env->yieldContent('title', 'Tổng quan')), ENT_QUOTES | ENT_HTML5).' — '.config('app.name', 'AI Testing Platform'),
        'withCsrf' => true,
        'themeColor' => '#0f172a',
    ])
</head>
<body class="min-h-screen bg-slate-900 text-slate-100 font-sans antialiased {{ \App\Support\AccentClasses::selection($currentUser) }} selection:text-white" data-layout="app" data-shell="{{ $shell['role'] }}">
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[60] focus:rounded-lg {{ $shell['skip'] }} focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white focus:shadow-lg">Bỏ qua đến nội dung chính</a>
    <x-assets-missing-notice />

    {{-- The drawer only exists below xl: widening the window past xl while it is open (tablet rotation,
         desktop resize) closes it, so the scroll lock and the focus trap never outlive the drawer. --}}
    <div x-data="{
             mobileOpen: false,
             init() {
                 const desktop = window.matchMedia('(min-width: 1280px)');
                 const closeOnDesktop = (query) => { if (query.matches) this.mobileOpen = false; };
                 desktop.addEventListener('change', closeOnDesktop);
             },
         }"
         data-testid="app-shell"
         x-effect="document.body.classList.toggle('overflow-hidden', mobileOpen)"
         @keydown.escape.window="mobileOpen = false"
         class="min-h-screen">

        <!-- Backdrop of the mobile navigation drawer -->
        <div x-show="mobileOpen" x-cloak
             x-transition:enter="transition-opacity ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
             x-transition:leave="transition-opacity ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
             @click="mobileOpen = false" aria-hidden="true"
             class="fixed inset-0 z-40 bg-slate-950/70 backdrop-blur-sm xl:hidden"></div>

        <!-- Sidebar: fixed from xl, off-canvas drawer (hidden from keyboard and screen readers while closed) below -->
        <aside id="app-sidebar" data-testid="app-sidebar" data-shell="{{ $shell['role'] }}" aria-label="{{ $shell['areaLabel'] }}"
               x-modal-focus="mobileOpen"
               :class="{ 'invisible -translate-x-full': ! mobileOpen }"
               class="invisible -translate-x-full fixed inset-y-0 left-0 z-40 flex w-72 max-w-[85vw] flex-col overflow-hidden border-r border-slate-800 bg-slate-950 shadow-2xl transition-[transform,visibility] duration-200 ease-out xl:visible xl:w-64 xl:translate-x-0 xl:shadow-none">
            <div aria-hidden="true" class="h-0.5 shrink-0 bg-gradient-to-r {{ $shell['stripe'] }}"></div>
            <div aria-hidden="true" class="pointer-events-none absolute -left-24 -top-24 h-64 w-64 rounded-full blur-3xl {{ $shell['glow'] }}"></div>

            <!-- Brand -->
            <div class="relative flex h-16 shrink-0 items-center justify-between gap-3 border-b border-slate-800/80 px-5">
                <a href="{{ route('dashboard') }}" class="group flex min-w-0 items-center gap-3 rounded-lg focus-visible:outline-none focus-visible:ring-2 {{ $shell['ring'] }}">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-tr from-indigo-600 via-indigo-500 to-cyan-400 text-sm font-bold text-white shadow-lg shadow-indigo-500/25 transition-transform group-hover:scale-105">AIT</span>
                    <span class="flex min-w-0 flex-col">
                        <span class="truncate text-sm font-bold tracking-tight text-white">AI Testing Platform</span>
                        <span class="truncate text-[11px] font-medium text-slate-400">Đại học Duy Tân</span>
                    </span>
                </a>
                <button type="button" @click="mobileOpen = false" aria-label="Đóng menu điều hướng" class="rounded-lg p-2 text-slate-400 hover:bg-slate-800 hover:text-white focus-visible:outline-none focus-visible:ring-2 {{ $shell['ring'] }} xl:hidden">
                    <x-shell.icon name="close" />
                </button>
            </div>

            <!-- Identity of the shell -->
            <div class="relative px-4 pt-4">
                <div data-testid="shell-area-label" class="flex items-center gap-3 rounded-xl border px-3 py-2.5 {{ $shell['chip'] }}">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg {{ $shell['chipIcon'] }}">
                        <x-shell.icon :name="$shell['areaIcon']" class="h-4 w-4" />
                    </span>
                    <span class="min-w-0">
                        <span class="block text-[10px] font-bold uppercase tracking-wider {{ $shell['chipEyebrow'] }}">{{ $currentUser?->role->label() }}</span>
                        <span class="block truncate text-sm font-semibold text-white">{{ $shell['areaLabel'] }}</span>
                    </span>
                </div>
            </div>

            <!-- Navigation -->
            <nav aria-label="Điều hướng chính" class="relative flex-1 space-y-6 overflow-y-auto px-3 py-5">
                @yield('sidebar')
            </nav>

            <!-- System health (GET /api/v1/health) -->
            <div class="relative shrink-0 border-t border-slate-800/80 px-5 py-3"
                 x-data="{
                     statusText: 'Đang kiểm tra',
                     isHealthy: true,
                     async checkHealth() {
                         try {
                             const res = await fetch('{{ route('api.v1.health') }}');
                             if (!res.ok) throw new Error();
                             const json = await res.json();
                             const data = json.data || json;
                             this.isHealthy = (data.status === 'ok' || data.status === 'healthy');
                             this.statusText = this.isHealthy ? 'Sẵn sàng' : 'Cảnh báo';
                         } catch (e) {
                             this.isHealthy = false;
                             this.statusText = 'Mất kết nối';
                         }
                     }
                 }"
                 x-init="checkHealth()"
                 title="Trạng thái nền tảng AIT (Web, Database, AI Service)">
                <p class="flex items-center gap-2 text-xs text-slate-400">
                    <span class="relative flex h-2 w-2" aria-hidden="true">
                        <span :class="isHealthy ? 'bg-emerald-400' : 'bg-rose-400'" class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-60 motion-reduce:animate-none"></span>
                        <span :class="isHealthy ? 'bg-emerald-500' : 'bg-rose-500'" class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                    </span>
                    <span>Hệ thống: <strong :class="isHealthy ? 'text-emerald-400' : 'text-rose-400'" class="font-semibold text-emerald-400" x-text="statusText">Đang kiểm tra</strong></span>
                </p>
            </div>
        </aside>

        <!-- Content column -->
        <div class="flex min-h-screen flex-col xl:pl-64">
            <!-- Top bar -->
            <header class="sticky top-0 z-30 border-b border-slate-800 bg-slate-900/90 backdrop-blur-md">
                <div class="flex h-16 items-center gap-3 px-4 sm:px-6 lg:px-8">
                    <div class="flex xl:hidden">
                        <button type="button" data-testid="shell-menu-button" @click="mobileOpen = !mobileOpen" aria-label="Mở/đóng menu điều hướng" aria-controls="app-sidebar" :aria-expanded="mobileOpen.toString()" aria-expanded="false"
                                class="-ml-2 rounded-lg p-2 text-slate-300 hover:bg-slate-800 hover:text-white focus-visible:outline-none focus-visible:ring-2 {{ $shell['ring'] }}">
                            <x-shell.icon name="menu" class="h-6 w-6" />
                        </button>
                    </div>

                    <a href="{{ route('dashboard') }}" class="flex shrink-0 items-center gap-2 rounded-lg focus-visible:outline-none focus-visible:ring-2 {{ $shell['ring'] }} xl:hidden" aria-label="AI Testing Platform — về trang tổng quan">
                        <span aria-hidden="true" class="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-tr from-indigo-600 via-indigo-500 to-cyan-400 text-xs font-bold text-white">AIT</span>
                    </a>

                    <p class="flex min-w-0 flex-1 items-center gap-2 text-sm">
                        {{-- Sidebar section of the current screen; only its coloured dot below sm. --}}
                        <span data-testid="shell-section-pill" title="{{ $shell['sectionLabel'] }}" class="inline-flex shrink-0 items-center gap-1.5 rounded-full border px-1.5 py-1.5 text-xs font-semibold sm:px-2.5 sm:py-0.5 {{ $shell['pill'] }}">
                            <span aria-hidden="true" class="h-1.5 w-1.5 rounded-full {{ $shell['dot'] }}"></span>
                            <span class="sr-only sm:not-sr-only">{{ $shell['sectionLabel'] }}</span>
                        </span>
                        <span aria-hidden="true" class="hidden text-slate-400 sm:inline">/</span>
                        <span class="truncate font-medium text-slate-200">@yield('title', 'Tổng quan')</span>
                    </p>

                    @auth
                        <div class="relative shrink-0" x-data="{ open: false }" data-testid="account-menu-wrapper" @focusout="if (open && $event.relatedTarget && !$el.contains($event.relatedTarget)) open = false" @keydown.escape.window="if (open) { open = false; $refs.accountButton.focus() }">
                            <button x-ref="accountButton" data-testid="user-menu-button" @click="open = !open" @click.outside="open = false" type="button" aria-controls="account-menu" :aria-expanded="open.toString()" aria-expanded="false"
                                    class="flex items-center gap-2.5 rounded-xl border border-slate-700 bg-slate-800/80 py-1 pl-1 pr-2 text-sm transition-colors hover:bg-slate-700/80 focus-visible:outline-none focus-visible:ring-2 {{ $shell['ring'] }}">
                                <span aria-hidden="true" class="flex h-8 w-8 items-center justify-center rounded-lg text-xs font-bold text-white {{ $shell['avatar'] }}">{{ $userInitial }}</span>
                                {{-- Visible name and role are the accessible name (WCAG 2.5.3); sr-only below sm. --}}
                                <span class="sr-only min-w-0 flex-col text-left sm:not-sr-only sm:flex">
                                    <span class="max-w-[160px] truncate text-xs font-semibold leading-tight text-slate-100">{{ $currentUser->name }}</span>
                                    <span class="text-[11px] font-medium leading-tight {{ $shell['roleText'] }}">{{ $currentUser->role->label() }}</span>
                                </span>
                                <span class="sr-only">, mở menu tài khoản</span>
                                <x-shell.icon name="chevron-down" class="h-4 w-4 text-slate-400 transition-transform" ::class="open ? 'rotate-180' : ''" />
                            </button>

                            <div id="account-menu" x-show="open" x-cloak
                                 x-transition:enter="transition ease-out duration-100" x-transition:enter-start="transform opacity-0 scale-95" x-transition:enter-end="transform opacity-100 scale-100"
                                 x-transition:leave="transition ease-in duration-75" x-transition:leave-start="transform opacity-100 scale-100" x-transition:leave-end="transform opacity-0 scale-95"
                                 class="absolute right-0 z-50 mt-2 w-64 rounded-xl border border-slate-700 bg-slate-800 py-1 shadow-xl">
                                <div class="border-b border-slate-700/60 px-4 py-3">
                                    <p class="truncate text-sm font-semibold text-white" data-testid="user-menu-name">{{ $currentUser->name }}</p>
                                    <p class="truncate text-xs text-slate-400">{{ $currentUser->email }}</p>
                                    <span data-testid="user-menu-role" class="mt-2 inline-block rounded border px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider {{ $shell['badge'] }}">{{ $currentUser->role->label() }}</span>
                                </div>

                                <form method="POST" action="{{ route('logout') }}" class="p-1">
                                    @csrf
                                    <button type="submit" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-xs font-semibold text-rose-300 transition-colors hover:bg-rose-500/20 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-400">
                                        <x-shell.icon name="logout" class="h-4 w-4" />
                                        Đăng xuất
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endauth
                </div>
            </header>

            <!-- Flash Messages -->
            <div class="mx-auto mt-4 w-full max-w-7xl px-4 sm:px-6 lg:px-8">
                @include('layouts.partials.flash')
            </div>

            <!-- Main Page Content -->
            <main id="main" tabindex="-1" class="mx-auto w-full max-w-7xl flex-1 px-4 py-6 focus:outline-none sm:px-6 lg:px-8">
                @yield('content')
            </main>

            <!-- Footer -->
            <footer class="mt-auto border-t border-slate-800/80 py-6 text-xs text-slate-400">
                <div class="mx-auto flex max-w-7xl flex-col items-center justify-between gap-2 px-4 text-center sm:flex-row sm:px-6 sm:text-left lg:px-8">
                    <p>© 2026 AI Testing Platform (AIT) — Đại học Duy Tân</p>
                    <p>Trường Trí Tuệ Nhân Tạo và Thông Tin</p>
                </div>
            </footer>
        </div>
    </div>
</body>
</html>
